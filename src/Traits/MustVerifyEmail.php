<?php

namespace FlutterSdk\MagicStarter\Traits;

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Notifications\VerifyEmailNotification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Carbon;

trait MustVerifyEmail
{
    /**
     * Determine if the user's email has been verified.
     *
     * Guest users bypass email verification since they have no email
     * address to verify. This prevents the `verified` middleware from
     * blocking authenticated guest users on protected routes.
     */
    public function hasVerifiedEmail(): bool
    {
        // Guests have no email to verify — treat them as verified.
        if (method_exists($this, 'isGuest') && $this->isGuest()) {
            return true;
        }

        return $this->email_verified_at !== null;
    }

    /**
     * Mark the user's email as verified.
     */
    public function markEmailAsVerified(): bool
    {
        $this->forceFill([
            'email_verified_at' => Carbon::now(),
        ])->saveQuietly();

        event(new Verified($this));

        return true;
    }

    /**
     * Whether this instance has already queued a verification notification.
     *
     * Deliberately an INSTANCE property and never a static keyed by id. The
     * duplicate this guards against happens inside one request, on one object; a
     * static would also kill it and would then survive between requests under
     * Octane, muting a customer's later resend for the life of the worker.
     */
    protected bool $verificationNotificationSent = false;

    /**
     * Send the email verification notification, at most once per instance.
     *
     * **A registration reaches this method twice.** `Actions\CreateUser` sends
     * explicitly (it owns the intent, and its tests cover the feature gate, social
     * login and phone-only users), and `AuthController::register()` then fires
     * `Registered`, whose framework listener
     * ({@see \Illuminate\Auth\Listeners\SendEmailVerificationNotification}) calls
     * this again on the SAME instance. Measured on a consumer app: two identical
     * "Verify Email Address" mails carrying the same signed URL, 47ms apart, on
     * every sign-up.
     *
     * Neither caller can simply go. Dropping the action's send would leave anyone
     * invoking the action directly with no mail, and `Registered` has to keep firing
     * because `CreatePersonalTeamListener` hangs off it. So the invariant "one
     * verification mail per registration" is enforced here, at the one seam both
     * paths funnel through.
     *
     * A deliberate resend still works: `POST /email/verification-notification`
     * arrives in its own request with its own model instance.
     *
     * **The invariant is scoped to object identity, and that is its limit.** It
     * holds for every path this package owns, because all of them carry one
     * instance from the action to the event. A consumer who binds its own
     * `Contracts\CreatesUsers` and then fires `event(new Registered($user->fresh()))`,
     * or who reloads the model between the two, hands the listener a DIFFERENT
     * object with the flag unset and gets both mails again. Nothing signals that,
     * so read "one mail per registration" as a promise about this package's own
     * paths rather than an unconditional one, and pass the same instance through.
     */
    public function sendEmailVerificationNotification(): void
    {
        // The published User stub implements `MustVerifyEmailContract` and uses
        // this trait unconditionally, while the `verification.verify` route is
        // registered only when the `email-verification` feature is on. Without
        // this guard, registering on an install that left the feature off built
        // a signed URL for a route that does not exist: `POST /auth/register`
        // answered 500 with `Route [verification.verify] not defined`, the user
        // was created anyway because the event fires after the insert, and the
        // obvious retry answered "The email has already been taken". Measured
        // against Laravel 13 with sessions, extended-profile, notifications and
        // social-login selected.
        if (! Features::enabled(Features::emailVerification())) {
            return;
        }

        if ($this->verificationNotificationSent) {
            return;
        }

        $this->verificationNotificationSent = true;

        $this->notify(new VerifyEmailNotification);
    }

    /**
     * Get the email address that should be used for verification.
     */
    public function getEmailForVerification(): string
    {
        return (string) $this->email;
    }
}
