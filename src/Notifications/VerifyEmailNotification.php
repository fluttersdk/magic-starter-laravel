<?php

namespace FlutterSdk\MagicStarter\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\URL;

class VerifyEmailNotification extends Notification
{
    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $verificationUrl = $this->resolveVerificationUrl($notifiable);

        return (new MailMessage)
            ->subject(Lang::get('Verify Email Address'))
            ->line(Lang::get('Please click the button below to verify your email address.'))
            ->action(Lang::get('Verify Email'), $verificationUrl);
    }

    /**
     * Build the signed verification URL, replacing the backend base with the configured frontend URL.
     */
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
