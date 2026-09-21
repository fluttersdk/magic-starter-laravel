<?php

namespace FlutterSdk\MagicStarter\Actions;

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
class ClaimGuestAccount
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

        // 2. An expired token authorises nothing, which is exactly how Sanctum's
        //    own guard reads it, so it cannot authorise moving a person's rows
        //    into an account either. Answered as "nothing to claim" rather than
        //    as a refusal, so the endpoint says nothing about which tokens exist.
        if ($token->expires_at !== null && $token->expires_at->isPast()) {
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
            //    the flag below is what the loser reads: the claim clears
            //    `is_guest`, so "still a guest" is the same question here as it
            //    was for the token.
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

            // 2. Move the rows this package owns.
            $this->moveNotifications($locked, $target);

            // 3. Hand the seam to the consumer. Synchronous and inside the
            //    transaction, so a listener that throws unwinds the whole claim
            //    rather than leaving a half-moved account behind. The guest is
            //    still intact here, tokens and device id included, because a
            //    listener may key its own rows on either.
            Event::dispatch(new GuestClaimed($locked, $target));

            // 4. Consume the guest row, which is three writes because a guest
            //    can be reached three ways. The tokens go, so no session
            //    survives. The device id goes, so a later guest login from the
            //    same device opens a new row rather than this one. The flag
            //    goes, so the guest lookup cannot match it even if the device id
            //    is replayed, and so a racing second claim reads the row as
            //    already claimed. Nothing is left to name it with: the row
            //    carries no email and no password either.
            //
            //    `forceFill` because both columns are system-managed and
            //    deliberately outside the published User stub's $fillable.
            $this->revokeTokens($locked);
            $locked->forceFill([
                'is_guest' => false,
                'device_id' => null,
            ])->save();

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
