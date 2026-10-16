<?php

namespace FlutterSdk\MagicStarter\Notifications;

use FlutterSdk\MagicStarter\Support\FrontendUrl;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
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
            ->subject((string) __('magic-starter::mail.verify.subject'))
            ->line((string) __('magic-starter::mail.verify.intro'))
            ->action((string) __('magic-starter::mail.verify.action'), $verificationUrl);
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

        // Only the PREDICATE is shared with the other two link builders. The swap
        // itself stays here, because a signed route must keep its signature and only
        // its host may change.
        $frontendUrl = FrontendUrl::baseOrNull();

        if ($frontendUrl === null) {
            return $signedUrl;
        }

        return str_replace(url('/'), $frontendUrl, $signedUrl);
    }
}
