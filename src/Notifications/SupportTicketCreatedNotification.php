<?php

namespace FlutterSdk\MagicStarter\Notifications;

use FlutterSdk\MagicStarter\Actions\OpenSupportConversation;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Confirms by mail that a new support conversation reached the desk.
 *
 * Sent by {@see OpenSupportConversation} only when `support.mail.ticket_created`
 * is on, and only for a conversation the request just created. Two audiences:
 * a visitor with no account gets a freshly minted thread link (`$threadUrl`),
 * an account holder the in-app route of the thread under the frontend base.
 *
 * It carries the conversation number and nothing the submitter typed. The
 * public form mails whatever address a visitor enters, so a subject, name or
 * body copied in here would turn the desk into a relay, and a mail line is
 * Markdown, so `[x](url)` in one would arrive as a live link.
 *
 * Queued, so a mail transport failure fails this notification and not the
 * request that already stored the conversation (a 500 there invites a
 * duplicate on resubmit). Encrypted on the queue because `$threadUrl` carries
 * the live token, which would otherwise sit readable in Redis and in Horizon.
 */
class SupportTicketCreatedNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    /**
     * @param  int  $number  the conversation's display number, the only conversation detail the mail shows
     * @param  string|int  $conversationId  the conversation key, for the in-app route
     * @param  string|null  $threadUrl  the public thread link with a freshly minted token, for a visitor; null
     *                                  for an account holder
     */
    public function __construct(
        public readonly int $number,
        public readonly string|int $conversationId,
        public readonly ?string $threadUrl = null,
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
        $visitor = $this->threadUrl !== null;

        return (new MailMessage)
            ->subject((string) __('magic-starter::support.mail.ticket_created.subject', [
                'number' => $this->number,
            ]))
            ->line((string) __('magic-starter::support.mail.ticket_created.intro', [
                'number' => $this->number,
            ]))
            ->line((string) __($visitor
                ? 'magic-starter::support.mail.ticket_created.visitor'
                : 'magic-starter::support.mail.ticket_created.account'))
            ->action(
                (string) __('magic-starter::support.mail.ticket_created.action'),
                $this->threadUrl ?? FrontendUrl::to(SupportConversation::clientThreadPath($this->conversationId)),
            );
    }
}
