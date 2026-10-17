<?php

namespace FlutterSdk\MagicStarter\Notifications;

use FlutterSdk\MagicStarter\Models\NewsletterSubscriber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mime\Email;

/**
 * Confirms by mail that a person subscribed to the newsletter, and how to leave.
 *
 * The caller pins the language with `->locale(MailLocale::for($user))`, so the
 * date and the copy read in the subscriber's language whichever process renders
 * the mail. The unsubscribe link is the one every later newsletter mail reuses,
 * and the RFC 8058 headers let a mail client unsubscribe with a single POST
 * without opening the page.
 *
 * Queued, so a mail transport failure fails this notification and not the
 * request that already recorded the consent.
 */
final class NewsletterSubscribedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public NewsletterSubscriber $subscriber,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return [
            'mail',
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $url = route('magic-starter.newsletter.unsubscribe', [
            'token' => $this->subscriber->unsubscribe_token,
        ]);

        // A row written by the previous release has no consent date; its creation is the nearest honest answer.
        $consentedAt = $this->subscriber->consented_at ?? $this->subscriber->created_at;

        // withLocale() switches the translator only, so Carbon would keep the app locale for the month name.
        $date = $consentedAt?->copy()->locale(trans()->getLocale())->isoFormat('LL');

        return (new MailMessage)
            ->subject((string) __('magic-starter::newsletter.mail.subject'))
            ->line((string) __('magic-starter::newsletter.mail.intro'))
            ->line((string) __('magic-starter::newsletter.mail.consented_on', [
                'date' => $date,
            ]))
            ->action((string) __('magic-starter::newsletter.mail.unsubscribe_action'), $url)
            ->line((string) __('magic-starter::newsletter.mail.outro'))
            ->withSymfonyMessage(function (Email $message) use ($url): void {
                $headers = $message->getHeaders();
                $headers->addTextHeader('List-Unsubscribe', '<' . $url . '>');
                $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });
    }
}
