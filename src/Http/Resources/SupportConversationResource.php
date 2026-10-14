<?php

namespace FlutterSdk\MagicStarter\Http\Resources;

use Closure;
use FlutterSdk\MagicStarter\Enums\SupportConversationStatus;
use FlutterSdk\MagicStarter\Enums\SupportMessageKind;
use FlutterSdk\MagicStarter\Enums\SupportSenderType;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Models\SupportMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * A support conversation as its customer sees it.
 *
 * Never carries the opener, the team, the assignee, the handoff reason, any
 * email or the thread token columns. `has_unread` is a staff or AI message
 * newer than `opener_seen_at`, computed in SQL by {@see self::forList()} or
 * {@see self::forThread()}. The thread is present only once
 * {@see self::forThread()} loaded it through `customerVisible()`, and a list
 * row's `last_message` only once {@see self::forList()} picked it through the
 * same scope, so a staff note cannot reach this resource.
 *
 * `awaiting_ai` is a pending conversation whose newest customer message the AI
 * desk has not answered yet; `waiting_on_team` an open one still waiting on a
 * person.
 *
 * With the `support-csat` feature and after {@see self::withRating()} the opener also reads `rating` (the last one
 * given, kept until a new one overwrites it) and `can_rate`, true while the conversation is resolved and unrated for
 * this resolution.
 *
 * Each message carries its `attachments` only after {@see self::withAttachments()}. Both are called by the signed-in
 * customer's controller and never by the public thread, whose link may have been forwarded.
 *
 * @mixin SupportConversation
 */
class SupportConversationResource extends JsonResource
{
    /**
     * The longest `last_message.body_preview`, in characters, the `...` included.
     */
    private const PREVIEW_LENGTH = 140;

    /**
     * Whether the thread's messages serialise their files.
     */
    private bool $withAttachments = false;

    /**
     * Whether `rating` and `can_rate` are serialised.
     */
    private bool $withRating = false;

    /**
     * Add the unread flag and, once the rows are read, each row's newest
     * customer-visible message to a customer-facing list query.
     *
     * @param  Builder<SupportConversation>  $query
     * @return Builder<SupportConversation>
     */
    public static function forList(Builder $query): Builder
    {
        return $query->withExists(self::unreadFlag())
            ->afterQuery(function (Collection $conversations): void {
                /** @var Collection<int, SupportConversation> $conversations */
                self::attachLastMessages($conversations);
            });
    }

    /**
     * Load the unread flag and the customer-visible thread onto one conversation.
     *
     * @param  bool  $attachments  also load each message's files, for {@see self::withAttachments()}
     */
    public static function forThread(SupportConversation $conversation, bool $attachments = false): SupportConversation
    {
        return $conversation
            ->loadExists(self::unreadFlag())
            ->load([
                'messages' => function (HasMany $query) use ($attachments): void {
                    /** @var HasMany<SupportMessage, SupportConversation> $query */
                    $query->customerVisible()
                        ->with($attachments
                            ? [
                                'sender',
                                'attachments',
                            ]
                            : [
                                'sender',
                            ])
                        ->orderBy('created_at')
                        ->orderBy('id');
                },
            ]);
    }

    /**
     * Serialise each message's files; the thread must come from {@see self::forThread()} with `$attachments`.
     */
    public function withAttachments(): static
    {
        $this->withAttachments = true;

        return $this;
    }

    /**
     * Serialise `rating` and `can_rate` while the `support-csat` feature is on; for the signed-in opener only.
     */
    public function withRating(): static
    {
        $this->withRating = true;

        return $this;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'subject' => $this->subject,
            'topic' => $this->topic,
            'source' => $this->source->value,
            'status' => $this->status->value,
            'priority' => $this->priority->value,
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'has_unread' => (bool) $this->has_unread,
            'awaiting_ai' => $this->status === SupportConversationStatus::PENDING
                && $this->last_customer_message_id !== null
                && (string) $this->last_customer_message_id !== (string) $this->answered_message_id,
            'waiting_on_team' => $this->status === SupportConversationStatus::OPEN && $this->waiting_since !== null,
            'rating' => $this->when($this->showsRating(), fn (): ?array => $this->rating()),
            'can_rate' => $this->when($this->showsRating(), fn (): bool => $this->canRate()),
            'last_message' => $this->when(
                $this->resource->relationLoaded('lastMessage'),
                fn (): ?array => self::preview($this->resource->getRelation('lastMessage')),
            ),
            'messages' => $this->whenLoaded('messages', fn (): array => $this->resource->messages
                ->map(fn (SupportMessage $message): SupportMessageResource => (new SupportMessageResource($message))
                    ->withAttachments($this->withAttachments))
                ->values()
                ->all()),
        ];
    }

    private function showsRating(): bool
    {
        return $this->withRating && Features::hasSupportCsatFeatures();
    }

    /**
     * The opener's rating as last given, or null before any.
     *
     * @return array{value: string|null, comment: string|null, rated_at: string}|null
     */
    private function rating(): ?array
    {
        if ($this->csat_rated_at === null) {
            return null;
        }

        return [
            'value' => $this->csat_rating?->value,
            'comment' => $this->csat_comment,
            'rated_at' => $this->csat_rated_at->toIso8601String(),
        ];
    }

    /**
     * Whether the opener may rate now: the conversation is resolved and not yet rated for this resolution.
     */
    private function canRate(): bool
    {
        return $this->status === SupportConversationStatus::RESOLVED
            && ($this->csat_rated_at === null
                || ($this->resolved_at !== null && $this->csat_rated_at->lt($this->resolved_at)));
    }

    /**
     * Set each conversation's `lastMessage` to its newest customer-visible
     * reply, in one query for the whole page.
     *
     * Only kind `message` qualifies, through `customerVisible()` as well, so
     * neither a note nor an activity line is ever the preview. Ties on
     * `created_at` fall to the higher id, the thread's own order reversed.
     *
     * @param  Collection<int, SupportConversation>  $conversations
     */
    private static function attachLastMessages(Collection $conversations): void
    {
        if ($conversations->isEmpty()) {
            return;
        }

        $latest = SupportMessage::query()
            ->customerVisible()
            ->where('kind', SupportMessageKind::MESSAGE->value)
            ->whereIn('conversation_id', $conversations->modelKeys())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->groupLimit(1, 'conversation_id')
            ->with('sender')
            ->get()
            ->keyBy('conversation_id');

        foreach ($conversations as $conversation) {
            $conversation->setRelation('lastMessage', $latest->get($conversation->getKey()));
        }
    }

    /**
     * A list row's preview of one message.
     *
     * @return array{sender_type: string, sender_name: string|null, body_preview: string, created_at: string|null}|null
     */
    private static function preview(?SupportMessage $message): ?array
    {
        if ($message === null) {
            return null;
        }

        return [
            'sender_type' => $message->sender_type->value,
            'sender_name' => SupportMessageResource::senderOf($message)['name'] ?? null,
            'body_preview' => Str::limit(Str::squish($message->body), self::PREVIEW_LENGTH - 3),
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    /**
     * The `withExists` spec behind `has_unread`: a customer-visible staff or
     * AI message created after the opener last looked, or any one when they
     * never have.
     *
     * @return array<string, Closure(Builder<SupportMessage>): void>
     */
    private static function unreadFlag(): array
    {
        return [
            'messages as has_unread' => function (Builder $query): void {
                /** @var Builder<SupportMessage> $query */
                $query->customerVisible()
                    ->whereIn('sender_type', [
                        SupportSenderType::STAFF->value,
                        SupportSenderType::AI->value,
                    ])
                    ->where(function (Builder $query): void {
                        $query->whereNull('support_conversations.opener_seen_at')
                            ->orWhereColumn(
                                'support_messages.created_at',
                                '>',
                                'support_conversations.opener_seen_at',
                            );
                    });
            },
        ];
    }
}
