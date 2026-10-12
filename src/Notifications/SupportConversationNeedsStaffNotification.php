<?php

namespace FlutterSdk\MagicStarter\Notifications;

use Filament\Notifications\Notification as FilamentNotification;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Lang;
use onesignal\client\model\LanguageStringMap;
use onesignal\client\model\Notification as OneSignalNotification;

/**
 * Tells a staff member that a conversation is waiting on staff.
 *
 * It names the conversation (id, number and subject) and never carries what
 * the customer wrote: the message is read in the panel, behind its gate. The
 * push goes further and leaves the subject out too, since a lock screen is
 * not behind any gate.
 *
 * The database row is written in Filament's own format when Filament is
 * installed, so the panel's notification bell lists it; the conversation keys
 * ride alongside, where Filament ignores them.
 */
class SupportConversationNeedsStaffNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $reason  `opened` or `customer_message`
     */
    public function __construct(
        public readonly SupportConversation $conversation,
        public readonly string $reason,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return Features::hasOnesignalFeatures()
            ? [
                'database',
                'mail',
                'onesignal',
            ]
            : [
                'database',
                'mail',
            ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(mixed $notifiable): array
    {
        $keys = [
            'conversation_id' => $this->conversation->getKey(),
            'number' => $this->conversation->number,
            'subject' => $this->conversation->subject,
            'reason' => $this->reason,
        ];

        if (! class_exists(FilamentNotification::class)) {
            return [
                'title' => $this->title(),
                'body' => $this->conversation->subject,
                ...$keys,
            ];
        }

        return [
            ...FilamentNotification::make()
                ->title($this->title())
                ->body($this->conversation->subject)
                ->getDatabaseMessage(),
            ...$keys,
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title() . ': ' . $this->conversation->subject)
            ->line($this->reasonLine())
            ->line(Lang::get('Subject: :subject', ['subject' => $this->conversation->subject]))
            ->line(Lang::get('Open the admin panel to answer it.'));
    }

    /**
     * The push: the conversation number and ids, nothing the customer wrote.
     *
     * @return OneSignalNotification<string, mixed>
     */
    public function toOneSignal(mixed $notifiable): OneSignalNotification
    {
        $payload = new OneSignalNotification;

        $payload->setHeadings(new LanguageStringMap(['en' => $this->title()]));
        $payload->setContents(new LanguageStringMap(['en' => $this->reasonLine()]));
        $payload->setData((object) [
            'conversation_id' => $this->conversation->getKey(),
            'number' => $this->conversation->number,
        ]);

        return $payload;
    }

    private function title(): string
    {
        return Lang::get('Support conversation #:number', ['number' => $this->conversation->number]);
    }

    private function reasonLine(): string
    {
        return $this->reason === 'opened'
            ? Lang::get('A customer opened a new conversation.')
            : Lang::get('A customer wrote again and is waiting for an answer.');
    }
}
