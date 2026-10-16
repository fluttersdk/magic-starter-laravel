<?php

namespace FlutterSdk\MagicStarter\Notifications;

use FlutterSdk\MagicStarter\Actions\ChangeSupportConversation;
use FlutterSdk\MagicStarter\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Asks the account holder of a newly resolved conversation to rate it.
 *
 * Sent by {@see ChangeSupportConversation} once per transition to resolved,
 * when `support.mail.csat_invitation` and the `support-csat` feature are on
 * and the resolution is not rated yet. The rating itself happens in the app,
 * so the link is the in-app thread; a visitor has no rating route and is
 * never sent this. Like the ticket-created mail it shows the conversation
 * number and no customer text.
 *
 * Queued, so a mail transport failure never fails the staff change that
 * already resolved the conversation. It is built in a staff member's
 * request, so no locale is pinned: Laravel renders it in the notifiable's
 * `HasLocalePreference` locale when it declares one.
 */
class SupportCsatInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  int  $number  the conversation's display number
     * @param  string|int  $conversationId  the conversation key, for the in-app route
     */
    public function __construct(
        public readonly int $number,
        public readonly string|int $conversationId,
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
        return (new MailMessage)
            ->subject((string) __('magic-starter::support.mail.csat_invitation.subject', [
                'number' => $this->number,
            ]))
            ->line((string) __('magic-starter::support.mail.csat_invitation.intro', [
                'number' => $this->number,
            ]))
            ->line((string) __('magic-starter::support.mail.csat_invitation.ask'))
            ->action((string) __('magic-starter::support.mail.csat_invitation.action'), $this->clientThreadUrl());
    }

    /**
     * The absolute in-app route of the thread, where the rating is given.
     */
    private function clientThreadUrl(): string
    {
        $base = FrontendUrl::baseOrNull() ?? rtrim(url('/'), '/');

        return $base . str_replace(
            '{id}',
            (string) $this->conversationId,
            (string) config('magic-starter.support.client_thread_path', '/support/{id}'),
        );
    }
}
