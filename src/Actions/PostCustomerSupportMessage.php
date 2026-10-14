<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Audit\Auditor;
use FlutterSdk\MagicStarter\Contracts\PostsCustomerSupportMessages;
use FlutterSdk\MagicStarter\Enums\SupportConversationStatus;
use FlutterSdk\MagicStarter\Enums\SupportMessageKind;
use FlutterSdk\MagicStarter\Enums\SupportSenderType;
use FlutterSdk\MagicStarter\Events\SupportConversationNeedsStaff;
use FlutterSdk\MagicStarter\Jobs\RespondToSupportConversation;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Models\SupportMessage;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportHandoff;
use FlutterSdk\MagicStarter\Support\SupportRules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Default action adding a customer's message to their own conversation.
 *
 * The poster must be the opener: the same account, or no account on either
 * side for a visitor on the public link. Anyone else is answered as if the
 * conversation did not exist. A message puts the conversation back in front of
 * staff, reopening one that was snoozed or resolved, and alerts them unless it
 * was already waiting on them. A pending conversation the AI desk still
 * answers stays pending instead, and the message queues the responder.
 *
 * The body is capped here but may be blank: whether one is required is the
 * door's call, and a message of files alone has none. The files themselves are
 * written by {@see StoreSupportAttachments} in the caller's transaction, which
 * the responder waits for, since it is queued after commit.
 */
class PostCustomerSupportMessage implements PostsCustomerSupportMessages
{
    public function __construct(
        private readonly SupportHandoff $desk,
    ) {}

    /**
     * Append the customer's message to the thread.
     *
     * @param  Authenticatable|null  $user  the opener, or null for a visitor on the public link
     *
     * @throws AuthorizationException a 404 for anyone but the opener
     * @throws ValidationException
     */
    public function post(SupportConversation $c, string $body, ?Authenticatable $user): SupportMessage
    {
        // 1. Only the opener writes here; a team mate is no exception.
        if ((string) $c->user_id !== (string) $user?->getAuthIdentifier()) {
            throw (new AuthorizationException)->asNotFound();
        }

        // 2. Validate the body against the configured cap; the door decides whether it may be blank.
        Validator::make(
            [
                'body' => $body,
            ],
            [
                'body' => SupportRules::body(required: false),
            ],
        )->validate();

        // 3. Record the message, and decide from the locked row who answers it:
        //    the AI desk keeps a pending conversation it still answers, anything
        //    else goes back onto staff's plate. The model listener is silenced:
        //    a visitor's request url carries the thread token, and its rows
        //    would copy that url into their context.
        $toAi = $this->desk->routesToAi($user instanceof Model ? $user : null, $c->source);

        [$message, $staysWithAi, $wasWaiting] = Auditor::withoutAuditing(fn (): array => DB::transaction(
            function () use ($c, $body, $user, $toAi): array {
                $now = now();
                $c->setRawAttributes(
                    SupportConversation::query()->lockForUpdate()->findOrFail($c->getKey())->getAttributes(),
                    true,
                );
                $staysWithAi = $toAi && $c->status === SupportConversationStatus::PENDING;
                $wasWaiting = $c->status === SupportConversationStatus::OPEN && $c->waiting_since !== null;

                $message = $c->messages()->create([
                    'sender_type' => SupportSenderType::CUSTOMER,
                    'sender_id' => $user?->getAuthIdentifier(),
                    'kind' => SupportMessageKind::MESSAGE,
                    'body' => $body,
                ]);

                $c->forceFill([
                    'status' => $staysWithAi ? SupportConversationStatus::PENDING : SupportConversationStatus::OPEN,
                    'resolved_at' => null,
                    'waiting_since' => $c->waiting_since ?? $now,
                    'last_activity_at' => $now,
                    'opener_seen_at' => $now,
                    'last_customer_message_id' => $message->getKey(),
                    // A new turn starts clean: a superseded turn's failures are not this one's.
                    'ai_failures' => 0,
                ])->save();

                return [
                    $message,
                    $staysWithAi,
                    $wasWaiting,
                ];
            },
        ));

        // 4. The responder answers a pending turn once it commits. Staff are
        //    told once per wait, whoever wrote: a message to a conversation
        //    already waiting on staff adds no alert.
        if ($staysWithAi) {
            RespondToSupportConversation::dispatch($c->getKey(), $message->getKey());
        } elseif (! $wasWaiting) {
            Event::dispatch(new SupportConversationNeedsStaff($c, 'customer_message'));
        }

        // 5. Record the one event in place of the silenced model rows, without the url for the same reason.
        Auditor::record(
            'support.customer_message',
            $c,
            [
                'message_id' => $message->getKey(),
                ...($user === null ? ['url' => null] : []),
            ],
            $user,
        );

        return $message;
    }
}
