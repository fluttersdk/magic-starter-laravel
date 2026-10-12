<?php

namespace FlutterSdk\MagicStarter\Events;

use FlutterSdk\MagicStarter\Models\SupportConversation;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/**
 * Staff changed a conversation the opener can see: a reply, a status, a
 * priority or an assignee.
 *
 * A nudge to refetch, not a copy of the change: the payload is the
 * conversation id and nothing else, so no message text and no staff note ever
 * crosses the socket, and the client reads the thread through the API that
 * already applies the opener-only rule. Only a conversation with an opener
 * account is broadcast; a visitor on the public link has no channel.
 */
class SupportConversationUpdated implements ShouldBroadcast
{
    public readonly string|int $conversationId;

    public readonly string|int|null $openerId;

    /**
     * @param  SupportConversation  $conversation  one with an opener (`user_id` set)
     */
    public function __construct(SupportConversation $conversation)
    {
        $this->conversationId = $conversation->getKey();
        $this->openerId = $conversation->user_id;
    }

    public function broadcastAs(): string
    {
        return 'support.conversation.updated';
    }

    /**
     * The opener's private user channel, the one the client already listens on.
     *
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('App.Models.User.' . $this->openerId),
        ];
    }

    /**
     * @return array{conversation_id: string|int}
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
        ];
    }
}
