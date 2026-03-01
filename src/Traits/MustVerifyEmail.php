<?php

namespace FlutterSdk\MagicStarter\Traits;

use FlutterSdk\MagicStarter\Notifications\VerifyEmailNotification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Carbon;

trait MustVerifyEmail
{
    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function markEmailAsVerified(): bool
    {
        $this->forceFill([
            'email_verified_at' => Carbon::now(),
        ])->saveQuietly();

        event(new Verified($this));

        return true;
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    public function getEmailForVerification(): string
    {
        return (string) $this->email;
    }
}
