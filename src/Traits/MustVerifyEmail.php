<?php

namespace FlutterSdk\MagicStarter\Traits;

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
     * Send the email verification notification.
     */
    public function sendEmailVerificationNotification(): void
    {
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
