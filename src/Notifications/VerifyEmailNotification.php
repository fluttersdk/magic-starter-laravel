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
     * Build the verification link: the client's verify page when a frontend URL
     * is configured, the signed API route otherwise.
     *
     * The client page replays `id`, `hash`, `expires` and `signature` against the
     * API route. The signature is RELATIVE (path and expiry, not host), matching
     * the route's `signed:relative`: the mail goes out from whichever host the
     * request came in on, and the staff panel resends from its own, so an
     * absolute signature bound the link to a host the client never calls. The
     * link used to keep the API path and swap only the host, which sent the
     * customer to `<client>/api/v1/email/verify/...`, a path no client serves.
     */
    protected function resolveVerificationUrl(object $notifiable): string
    {
        // 1. Sign the API route's path and expiry.
        $parameters = [
            'id' => $notifiable->getKey(),
            'hash' => sha1($notifiable->getEmailForVerification()),
        ];
        $signedPath = URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(60),
            $parameters,
            absolute: false,
        );

        // 2. Without a client, the API answers the link itself.
        $frontendUrl = FrontendUrl::baseOrNull();

        if ($frontendUrl === null) {
            return url($signedPath);
        }

        // 3. Hand the client what it needs to replay the signed request.
        parse_str((string) parse_url($signedPath, PHP_URL_QUERY), $signature);

        return $frontendUrl . '/auth/verify-email?' . http_build_query([
            ...$parameters,
            'expires' => $signature['expires'],
            'signature' => $signature['signature'],
        ]);
    }
}
