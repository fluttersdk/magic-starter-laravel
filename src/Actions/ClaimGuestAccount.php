<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\ClaimsGuestAccounts;
use FlutterSdk\MagicStarter\Events\GuestClaimed;
use FlutterSdk\MagicStarter\MagicStarter;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

/**
 * Action for claiming a guest session into an existing account.
 *
 * Guest conversion covers the person who turns their guest row INTO an account.
 * This covers the other half: the person who already has an account and signs
 * in to it from a device that has been running as a guest. Those are two
 * different user rows, so everything the guest accumulated is scoped to a user
 * id nobody will ever authenticate as again unless it is moved.
 *
 * The caller authenticates as the TARGET and presents the guest session's own
 * token, so both halves are proved by possession rather than by assertion.
 */
class ClaimGuestAccount implements ClaimsGuestAccounts
{
    /**
     * Claim a guest session into the authenticated account.
     *
     * @param  Authenticatable  $target  The account the rows move to. Must be the authenticated caller.
     * @param  array<string, mixed>  $input  The request payload, carrying the guest session's own token.
     * @return bool True when a guest was claimed, false when there was no live guest session to claim.
     *
     * @throws ValidationException When the presented token names something that cannot be claimed.
     */
    public function claim(Authenticatable $target, array $input): bool
    {
        // 1. The guest side is proved by its own Sanctum token and by nothing
        //    else. A device id is client-supplied, and it is cleared the moment
        //    a guest is promoted, so it can neither identify a guest reliably
        //    nor prove that the caller was the one running that session.
        $validated = Validator::make($input, [
            'guest_token' => ['required', 'string'],
        ])->validate();

        $guest = $this->resolveClaimableGuest((string) $validated['guest_token'], $target);

        if ($guest === null) {
            return false;
        }

        return $this->transfer($guest, $target);
    }

    /**
     * Resolve the guest session a token names, or null when there is none live.
     *
     * @param  string  $plainTextToken  The guest session's plain-text Sanctum token.
     * @param  Authenticatable  $target  The authenticated account, so a self-claim can be refused.
     *
     * @throws ValidationException
     */
    private function resolveClaimableGuest(string $plainTextToken, Authenticatable $target): ?Authenticatable
    {
        $tokenModel = Sanctum::personalAccessTokenModel();
        $token = $tokenModel::findToken($plainTextToken);

        // 1. No live token means there is nothing to claim, and that is what
        //    makes a repeat call a no-op success rather than an error: the
        //    first claim deleted the guest's tokens, so the second one lands
        //    here. The caller is told by the `false` return, so an honest
        //    client can still tell a retry from a transfer.
        if ($token === null) {
            return null;
        }

        // 2. A token Sanctum's own guard would refuse authorises nothing, so it
        //    cannot authorise moving a person's rows into an account either.
        //    Answered as "nothing to claim" rather than as a refusal, so the
        //    endpoint says nothing about which tokens exist.
        //
        //    BOTH of the guard's rules, because the one that reads as the
        //    obvious check is the one that never fires here. Guest tokens are
        //    minted by `AuthenticatesUsers` with `createToken('auth_token')`
        //    and no expiry, so `expires_at` is null on every token this package
        //    issues; on an application that sets `sanctum.expiration` the age of
        //    `created_at` is the ONLY rule that ages one out. Reading
        //    `expires_at` alone left a captured guest token refused by every
        //    other route and still able to move an account's rows.
        //
        //    The second comparison mirrors `Guard::isValidAccessToken`, falsy
        //    expiration included: Sanctum reads 0 and null alike as "no window".
        if ($token->expires_at !== null && $token->expires_at->isPast()) {
            return null;
        }

        $expiration = (int) config('sanctum.expiration');

        if ($expiration > 0 && ! $token->created_at?->gt(now()->subMinutes($expiration))) {
            return null;
        }

        $guest = $token->tokenable;
        $userModel = MagicStarter::userModel();

        // 3. One refusal for four causes, deliberately. A token may name
        //    something that cannot be authenticated at all, something that is
        //    not the configured user model, a user that is not a guest, or the
        //    caller themselves; telling them apart would turn this endpoint into
        //    an oracle about other people's tokens, and all four are the same
        //    mistake from the client's side.
        //
        //    The user-model test is not redundant beside the Authenticatable
        //    one, and the difference is the row this claim goes on to consume.
        //    The transfer locks the row by identifier IN THE CONFIGURED USER
        //    MODEL, so an application that also issues Sanctum tokens to some
        //    other authenticatable of its own could otherwise present one and
        //    have the claim consume whichever user row happens to carry the same
        //    key. Both halves resolve to the same table or the claim is refused.
        //    The Authenticatable test stays because the return type rests on it.
        if (! $guest instanceof Authenticatable
            || ! $guest instanceof $userModel
            || ! $this->isGuest($guest)
        ) {
            throw $this->refusal();
        }

        if ($guest->getAuthIdentifier() === $target->getAuthIdentifier()) {
            throw $this->refusal();
        }

        return $guest;
    }

    /**
     * Move the guest to the target inside one transaction.
     *
     * @param  Authenticatable  $guest  The guest resolved from the presented token.
     * @param  Authenticatable  $target  The account the rows move to.
     * @return bool True when this call performed the transfer.
     */
    private function transfer(Authenticatable $guest, Authenticatable $target): bool
    {
        return DB::transaction(function () use ($guest, $target): bool {
            // 1. Re-read the guest under a row lock and judge it again. Two
            //    clients racing the same guest token both resolve it before
            //    either has deleted it, so without this the second would
            //    dispatch the event a second time and a consumer's listener
            //    would move the same rows twice. The lock serialises them and
            //    the guest's TOKENS are what the loser reads, in step 2 below.
            //    The guest test here is for the other race, a promotion landing
            //    between the token resolving and this lock.
            //
            //    Read through the CONFIGURED user model rather than off the
            //    resolved instance. `newQuery()` on something typed
            //    Authenticatable is a call whose generic return type Larastan
            //    cannot complete, and it failed the analyser on CI's dependency
            //    set while passing on this checkout's.
            $userModel = MagicStarter::userModel();

            $locked = $userModel::query()
                ->whereKey($guest->getAuthIdentifier())
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof Authenticatable || ! $this->isGuest($locked)) {
                return false;
            }

            // 2. Has this claim already been made? The sentinel is the thing a
            //    winner destroys rather than a column a guest may or may not
            //    carry: the claim below deletes every token the guest holds, and
            //    the credential that got us here was one of them, so a guest
            //    with none is a guest somebody has already claimed. A missing
            //    `device_id` was the earlier answer and it was wrong, because
            //    the published UserFactory makes guests that never had one and
            //    their claim would silently no-op.
            //
            //    A LOCKING read, so it is a current read on every isolation
            //    level: under REPEATABLE READ a plain SELECT here could be
            //    answered from a snapshot taken before the winner committed,
            //    and the loser would dispatch the event a second time.
            if (! $this->holdsTokens($locked)) {
                return false;
            }

            // 3. Move the rows this package owns.
            $this->moveNotifications($locked, $target);

            // 4. Hand the seam to the consumer. Synchronous and inside the
            //    transaction, so a listener that throws unwinds the whole claim
            //    rather than leaving a half-moved account behind. The guest is
            //    still intact here, tokens and device id included, because a
            //    listener may key its own rows on either.
            Event::dispatch(new GuestClaimed($locked, $target));

            // 5. Consume the guest row. The tokens go, so no session survives.
            //    The device id goes, so the guest lookup cannot match this row
            //    again and a later guest login from the same device opens a new
            //    one. Between them nothing can reach it: the row carries no
            //    email and no password either, so no credential can name it.
            //
            //    `is_guest` STAYS TRUE, and that is a decision rather than an
            //    omission. The row never stopped being a guest; it is a spent
            //    one, and an application pruning abandoned guests with
            //    `where('is_guest', true)` has to be able to reclaim it.
            //    Clearing the flag was the other candidate: it made the row
            //    unprunable by the obvious query while protecting nothing the
            //    absent tokens do not already protect. Deleting the row here
            //    was rejected as well, because a consumer's listener may have
            //    left rows pointing at it and this package cannot know.
            //
            //    `forceFill` because `device_id` is system-managed and
            //    deliberately outside the published User stub's $fillable.
            $this->revokeTokens($locked);
            $locked->forceFill(['device_id' => null])->save();

            return true;
        });
    }

    /**
     * Move the guest's database notifications to the target.
     *
     * The notifications table is the ONLY user-scoped table this package moves,
     * and the other candidates are left alone for reasons rather than by
     * oversight. Notification settings carry a unique key per type and channel,
     * so moving a guest's defaults would collide with, and would be less
     * authoritative than, choices the account has already made. A guest's
     * personal team is not moved because the target already owns one, and a
     * second personal team is a state the teams feature has no meaning for.
     * Newsletter subscriptions are keyed on an email address, which a guest has
     * by definition never had.
     *
     * The table is checked rather than assumed: an application that keeps its
     * notifications elsewhere has no such table, and an UPDATE against a missing
     * one would abort a claim that is otherwise perfectly valid.
     *
     * @param  Authenticatable  $guest  The guest whose rows move.
     * @param  Authenticatable  $target  The account the rows move to.
     */
    private function moveNotifications(Authenticatable $guest, Authenticatable $target): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        DB::table('notifications')
            ->where('notifiable_type', $guest->getMorphClass())
            ->where('notifiable_id', $guest->getAuthIdentifier())
            ->update([
                'notifiable_id' => $target->getAuthIdentifier(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Determine whether the guest still holds any Sanctum token, under a lock.
     *
     * Only ever asked from inside the claim's transaction, where it answers
     * "has another claim already taken this guest". Every caller reached here by
     * presenting one of these tokens, so the answer can only turn false through
     * {@see self::revokeTokens()}.
     *
     * @param  Authenticatable  $guest  The guest row already locked by the caller.
     */
    private function holdsTokens(Authenticatable $guest): bool
    {
        $tokenModel = Sanctum::personalAccessTokenModel();

        return $tokenModel::query()
            ->where('tokenable_type', $guest->getMorphClass())
            ->where('tokenable_id', $guest->getAuthIdentifier())
            ->lockForUpdate()
            ->exists();
    }

    /**
     * Delete every Sanctum token the guest holds.
     *
     * Queried through the token model rather than through the user's `tokens()`
     * relation, because HasApiTokens is a trait the consumer applies to its own
     * user model and a claim must revoke the sessions whether or not it did.
     *
     * @param  Authenticatable  $guest  The guest being consumed.
     */
    private function revokeTokens(Authenticatable $guest): void
    {
        $tokenModel = Sanctum::personalAccessTokenModel();

        $tokenModel::query()
            ->where('tokenable_type', $guest->getMorphClass())
            ->where('tokenable_id', $guest->getAuthIdentifier())
            ->delete();
    }

    /**
     * Determine whether a user row is still a guest.
     *
     * `method_exists` rather than a plain call: HasGuestSupport is a trait the
     * consumer applies to its own user model, and the column has to be readable
     * on a model that never took it.
     *
     * @param  Authenticatable  $user  The user row to judge.
     */
    private function isGuest(Authenticatable $user): bool
    {
        return method_exists($user, 'isGuest')
            ? $user->isGuest()
            : (bool) $user->getAttribute('is_guest');
    }

    /**
     * The one refusal this endpoint answers a bad guest credential with.
     *
     * The sentence comes from the framework's own validation lines rather than
     * from a package string, so it arrives in the caller's language in every
     * application without this package shipping a translation for it.
     */
    private function refusal(): ValidationException
    {
        return ValidationException::withMessages([
            'guest_token' => [(string) trans('validation.exists', ['attribute' => 'guest token'])],
        ]);
    }
}
