<?php

namespace FlutterSdk\MagicStarter\Http\Resources;

use Closure;
use FlutterSdk\MagicStarter\Enums\SupportSenderType;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Models\SupportMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A support conversation as its customer sees it.
 *
 * Never carries the opener, the team, the assignee, any email or the thread
 * token columns. `has_unread` is a staff message newer than `opener_seen_at`,
 * computed in SQL by {@see self::forList()} or {@see self::forThread()}; the
 * thread is present only once {@see self::forThread()} loaded it through
 * `customerVisible()`, so a staff note cannot reach this resource.
 *
 * @mixin SupportConversation
 */
class SupportConversationResource extends JsonResource
{
    /**
     * Add the unread flag to a customer-facing list query.
     *
     * @param  Builder<SupportConversation>  $query
     * @return Builder<SupportConversation>
     */
    public static function forList(Builder $query): Builder
    {
        return $query->withExists(self::unreadFlag());
    }

    /**
     * Load the unread flag and the customer-visible thread onto one conversation.
     */
    public static function forThread(SupportConversation $conversation): SupportConversation
    {
        return $conversation
            ->loadExists(self::unreadFlag())
            ->load([
                'messages' => function (HasMany $query): void {
                    /** @var HasMany<SupportMessage, SupportConversation> $query */
                    $query->customerVisible()
                        ->orderBy('created_at')
                        ->orderBy('id');
                },
            ]);
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
            'source' => $this->source->value,
            'status' => $this->status->value,
            'priority' => $this->priority->value,
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'has_unread' => (bool) $this->has_unread,
            'messages' => SupportMessageResource::collection($this->whenLoaded('messages')),
        ];
    }

    /**
     * The `withExists` spec behind `has_unread`: a customer-visible staff
     * message created after the opener last looked, or any one when they never
     * have.
     *
     * @return array<string, Closure(Builder<SupportMessage>): void>
     */
    private static function unreadFlag(): array
    {
        return [
            'messages as has_unread' => function (Builder $query): void {
                /** @var Builder<SupportMessage> $query */
                $query->customerVisible()
                    ->where('sender_type', SupportSenderType::STAFF->value)
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
