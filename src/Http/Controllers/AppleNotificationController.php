<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Contracts\SchedulesUserDeletion;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Social\AppleNotificationVerifier;
use FlutterSdk\MagicStarter\Social\InvalidIdentityException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Keeps a Sign in with Apple link in step with the account at Apple, from the
 * server-to-server notifications Apple posts when a user stops using Sign in
 * with Apple for the app, deletes their Apple Account, or toggles mail
 * forwarding for a private relay address.
 *
 * The request carries no credential of its own: the JWS signature is the
 * authentication, so nothing in the body is read before
 * {@see AppleNotificationVerifier} has accepted it. An unverifiable payload
 * answers 400; every verified one answers 200, an unknown subject or an event
 * type this package does not act on included, because Apple retries anything
 * else and no retry can make those actionable.
 *
 * A revoked consent keeps the link with `revoked_at` set, so the next sign-in
 * with the same subject reactivates it. A deleted Apple Account schedules an
 * orphan deletion only when Apple was the user's last way in; the deletion is
 * scheduled, never performed here.
 */
class AppleNotificationController
{
    /**
     * Apply one Apple notification.
     *
     * @throws Throwable When applying a verified event fails; the claim is
     *                   released first so Apple's retry is processed.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $verifier = app(AppleNotificationVerifier::class);
        $payload = $request->input('payload');

        // 1. Verify before reading anything: the signature is the only credential.
        try {
            $notification = $verifier->verify(is_string($payload) ? $payload : '');
        } catch (InvalidIdentityException) {
            return response()->json([
                'message' => 'The notification could not be verified.',
            ], 400);
        }

        // 2. A delivery already applied is acknowledged, not applied twice.
        if (! $verifier->claim($notification['jti'])) {
            return response()->json(status: 200);
        }

        // 3. Apply atomically, releasing the claim on failure so the retry is not a no-op.
        try {
            DB::transaction(fn () => $this->apply(
                $notification['type'],
                $notification['subject'],
                $notification['email'],
            ));
        } catch (Throwable $exception) {
            $verifier->release($notification['jti']);

            throw $exception;
        }

        return response()->json(status: 200);
    }

    /**
     * Apply a verified event to the Apple link it names, if this deployment holds one.
     */
    protected function apply(?string $type, ?string $subject, ?string $email): void
    {
        if ($subject === null) {
            return;
        }

        $account = MagicStarter::socialAccountModel()::query()
            ->where('provider', 'apple')
            ->where('provider_user_id', $subject)
            ->first();

        if ($account === null) {
            return;
        }

        match ($type) {
            'consent-revoked' => $this->revoke($account),
            'account-deleted' => $this->forget($account),
            'email-enabled', 'email-disabled' => $email === null
                ? null
                : $account->forceFill(['email_at_link' => $email])->save(),
            default => null,
        };
    }

    /**
     * Stop the link signing anybody in: mark it revoked, drop the refresh token
     * Apple has already invalidated, and sign the user out everywhere.
     */
    protected function revoke(Model $account): void
    {
        $account->forceFill([
            'revoked_at' => $account->getAttribute('revoked_at') ?? now(),
            'refresh_token' => null,
        ])->save();

        $user = $account->getAttribute('user');

        if ($user instanceof Authenticatable) {
            $user->tokens()->delete();
        }
    }

    /**
     * Revoke the link, then schedule an orphan deletion when it was the user's last way in.
     */
    protected function forget(Model $account): void
    {
        $this->revoke($account);

        $user = $account->getAttribute('user');

        if (! $user instanceof Authenticatable || ! $this->wasLastWayIn($user, $account)) {
            return;
        }

        app(SchedulesUserDeletion::class)->schedule($user, orphan: true);
    }

    /**
     * Whether the user has no password and no other active provider link.
     *
     * A user model without {@see \FlutterSdk\MagicStarter\Traits\HasSocialAccounts}
     * cannot say whether it has a password, so it is treated as having one: a
     * wrong answer here would schedule a deletion nobody asked for.
     */
    protected function wasLastWayIn(Authenticatable $user, Model $account): bool
    {
        if (! method_exists($user, 'hasPassword') || $user->hasPassword()) {
            return false;
        }

        return ! MagicStarter::socialAccountModel()::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->whereKeyNot($account->getKey())
            ->whereNull('revoked_at')
            ->exists();
    }
}
