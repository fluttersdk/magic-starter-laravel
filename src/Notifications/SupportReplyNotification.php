<?php

namespace FlutterSdk\MagicStarter\Notifications;

use FlutterSdk\MagicStarter\Actions\ReplyToSupportConversation;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Models\SupportMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Lang;
use onesignal\client\model\LanguageStringMap;
use onesignal\client\model\Notification as OneSignalNotification;

/**
 * Tells the customer that staff answered their conversation.
 *
 * Two audiences, chosen by {@see ReplyToSupportConversation}: an opener with an
 * account is told in app (database) and by push, with generic text, ids and
 * the client route of the thread (`action_url`, from
 * `support.client_thread_path`) only, and reads the reply through the API; a visitor with no account gets a
 * mail carrying the reply itself and a fresh thread link (`$threadUrl`), since
 * the mail is their only way back in. Only ever built for a customer-visible
 * reply, never for a staff note.
 *
 * Encrypted on the queue because `$threadUrl` carries the live token, which
 * would otherwise sit readable in Redis and in Horizon's job history.
 */
class SupportReplyNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    /**
     * @param  string|null  $threadUrl  the public thread link with a freshly minted token, for a visitor; null for
     *                                  an opener with an account
     */
    public function __construct(
        public readonly SupportMessage $message,
        public readonly ?string $threadUrl = null,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        if ($this->threadUrl !== null) {
            return [
                'mail',
            ];
        }

        return Features::hasOnesignalFeatures()
            ? [
                'database',
                'onesignal',
            ]
            : [
                'database',
            ];
    }

    /**
     * The in-app row, in the `title` and `body` shape the client lists.
     *
     * @return array<string, mixed>
     */
    public function toArray(mixed $notifiable): array
    {
        return [
            'title' => $this->title(),
            'body' => $this->summary(),
            'conversation_id' => $this->message->conversation_id,
            'message_id' => $this->message->getKey(),
            'action_url' => $this->actionUrl(),
        ];
    }

    /**
     * The push: generic text and ids, never the reply.
     *
     * @return OneSignalNotification<string, mixed>
     */
    public function toOneSignal(mixed $notifiable): OneSignalNotification
    {
        $payload = new OneSignalNotification;

        $payload->setHeadings(new LanguageStringMap(['en' => $this->title()]));
        $payload->setContents(new LanguageStringMap(['en' => $this->summary()]));
        $payload->setData((object) [
            'conversation_id' => $this->message->conversation_id,
            'message_id' => $this->message->getKey(),
            'action_url' => $this->actionUrl(),
        ]);

        return $payload;
    }

    /**
     * The visitor's mail: the reply and the link to answer it.
     */
    public function toMail(mixed $notifiable): MailMessage
    {
        $conversation = $this->message->conversation;

        return (new MailMessage)
            ->subject(Lang::get('magic-starter::admin_support.notifications.reply.mail_subject', [
                'number' => $conversation->number,
                'subject' => $conversation->subject,
            ]))
            ->line(Lang::get('magic-starter::admin_support.notifications.reply.mail_intro'))
            ->line($this->message->body)
            ->action(Lang::get('magic-starter::admin_support.notifications.reply.mail_action'), (string) $this->threadUrl)
            ->line(Lang::get('magic-starter::admin_support.notifications.reply.mail_footer'));
    }

    /**
     * The in-app route of the thread: `support.client_thread_path` with `{id}`
     * replaced by the conversation key.
     */
    private function actionUrl(): string
    {
        return str_replace(
            '{id}',
            (string) $this->message->conversation_id,
            (string) config('magic-starter.support.client_thread_path', '/support/{id}'),
        );
    }

    private function title(): string
    {
        return Lang::get('magic-starter::admin_support.notifications.reply.title');
    }

    private function summary(): string
    {
        return Lang::get('magic-starter::admin_support.notifications.reply.summary');
    }
}
