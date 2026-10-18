<?php

namespace FlutterSdk\MagicStarter\Notifications;

use Filament\Facades\Filament;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Panel;
use FlutterSdk\MagicStarter\Enums\SupportHandoffReason;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Filament\MagicStarterPlugin;
use FlutterSdk\MagicStarter\Http\Resources\NotificationResource;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Arr;
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
     * @param  string  $reason  `opened`, `customer_message`, `handoff`, `negative_rating`, `feedback` or `proposal`
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
        // The broadcast is the live delivery of the database row, so it follows it.
        return Features::hasOnesignalFeatures()
            ? [
                'database',
                'mail',
                'onesignal',
                'broadcast',
            ]
            : [
                'database',
                'mail',
                'broadcast',
            ];
    }

    /**
     * The short name magic_notifications' bell listens for (`NotificationManager.realtimeEvent`); a client matches
     * a frame by exact string, so Laravel's fully qualified default would never arrive.
     */
    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    /**
     * The frame's `type`, as `GET /notifications` answers it ({@see NotificationResource}). Laravel merges this over
     * the message, and its default is the fully qualified class name.
     */
    public function broadcastType(): string
    {
        return class_basename(static::class);
    }

    /**
     * The frame, in the shape `GET /notifications` lists a row ({@see NotificationResource}); Laravel's default
     * flattens the data, which the client cannot decode. `id` and `type` are merged in by Laravel.
     */
    public function toBroadcast(mixed $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'data' => $this->toDatabase($notifiable),
            'created_at' => now()->toIso8601String(),
            'read_at' => null,
        ]);
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

        // Filament renders the body as sanitised HTML that keeps `style`, so the customer's words go in escaped.
        return [
            ...FilamentNotification::make()
                ->title($this->title())
                ->body(e($this->conversation->subject))
                ->getDatabaseMessage(),
            ...$keys,
        ];
    }

    /**
     * The customer's subject rides in the plain-text header only: mail lines are Markdown, where it would become
     * live links and images in a staff inbox.
     */
    public function toMail(mixed $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title() . ': ' . $this->conversation->subject)
            ->line($this->reasonLine())
            ->line(Lang::get('magic-starter::admin_support.notifications.staff.open_panel'));

        $url = $this->panelUrl();

        return $url === null
            ? $mail
            : $mail->action(Lang::get('magic-starter::admin_support.notifications.staff.open_action'), $url);
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

    /**
     * The conversation's page in the panel that carries the starter plugin, or null when no safe link exists.
     *
     * This runs in a queue worker, which has no current panel, request or tenant. A panel on several domains
     * asks Filament for the current domain and a tenancy panel for a tenant, and a worker has neither, so such
     * a panel gets no link rather than a mail job that throws.
     */
    private function panelUrl(): ?string
    {
        // The classes can be in vendor with Filament's provider never registered, so the facade has no root.
        if (! class_exists(Filament::class) || ! app()->bound('filament')) {
            return null;
        }

        $panel = Arr::first(
            Filament::getPanels(),
            static fn (Panel $panel): bool => $panel->hasPlugin(MagicStarterPlugin::ID),
        );

        if ($panel === null || count($panel->getDomains()) > 1 || $panel->hasTenancy()) {
            return null;
        }

        /** @var MagicStarterPlugin $plugin */
        $plugin = $panel->getPlugin(MagicStarterPlugin::ID);
        $resource = $plugin->resourceFor('support');

        if ($resource === null || ! $resource::hasPage('view')) {
            return null;
        }

        return $resource::getUrl('view', ['record' => $this->conversation->getKey()], panel: $panel->getId());
    }

    private function title(): string
    {
        return Lang::get('magic-starter::admin_support.notifications.staff.title', [
            'number' => $this->conversation->number,
        ]);
    }

    /**
     * Why staff are told: a new conversation, a customer writing again, the AI desk's handoff and its reason, a
     * thumbs-down, a low feedback score or a billing proposal waiting for approval. None of them carries what the
     * customer wrote.
     */
    private function reasonLine(): string
    {
        return match ($this->reason) {
            'opened' => Lang::get('magic-starter::admin_support.notifications.staff.opened'),
            'handoff' => Lang::get('magic-starter::admin_support.notifications.staff.handoff', [
                'reason' => Lang::get(
                    'magic-starter::admin_support.enums.handoff_reason.'
                    . ($this->conversation->handoff_reason ?? SupportHandoffReason::UNAVAILABLE)->value,
                ),
            ]),
            'negative_rating' => Lang::get('magic-starter::admin_support.notifications.staff.negative_rating'),
            'feedback' => Lang::get('magic-starter::admin_support.notifications.staff.feedback'),
            'proposal' => Lang::get('magic-starter::admin_support.notifications.staff.proposal'),
            default => Lang::get('magic-starter::admin_support.notifications.staff.customer_wrote'),
        };
    }
}
