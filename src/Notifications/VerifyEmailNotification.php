<?php

namespace FlutterSdk\MagicStarter\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\URL;

class VerifyEmailNotification extends Notification
{
    public function via(object $notifiable): array
    {
        if ($notifiable instanceof object) {
            return [
                'mail',
            ];
        }

        return [
            'mail',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $verificationUrl = $this->resolveVerificationUrl($notifiable);

        return (new MailMessage)
            ->subject(Lang::get('Verify Email Address'))
            ->line(Lang::get('Verify Email Address'))
            ->action(Lang::get('Verify Email'), $verificationUrl);
    }

    protected function resolveVerificationUrl(object $notifiable): string
    {
        $signedUrl = URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(60),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ],
        );

        $frontendUrl = config('magic-starter.frontend_url');

        if (! is_string($frontendUrl) || trim($frontendUrl) === '') {
            return $signedUrl;
        }

        return str_replace(
            url('/'),
            rtrim($frontendUrl, '/'),
            $signedUrl,
        );
    }
}
