<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Audit\Auditor;
use FlutterSdk\MagicStarter\Contracts\PostsCustomerSupportMessages;
use FlutterSdk\MagicStarter\Enums\SupportConversationStatus;
use FlutterSdk\MagicStarter\Enums\SupportMessageKind;
use FlutterSdk\MagicStarter\Enums\SupportSenderType;
use FlutterSdk\MagicStarter\Events\SupportConversationNeedsStaff;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Models\SupportMessage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
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
 * staff, reopening one that was snoozed or resolved.
 */
class PostCustomerSupportMessage implements PostsCustomerSupportMessages
{
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

        // 2. Validate the body against the configured cap.
        Validator::make(
            [
                'body' => $body,
            ],
            [
                'body' => [
                    'required',
                    'string',
                    'max:' . (int) config('magic-starter.support.message_max_length', 2000),
                ],
            ],
        )->validate();

        // 3. Record the message and move the conversation back onto staff's plate.
        //    The model listener is silenced: a visitor's request url carries the
        //    thread token, and its rows would copy that url into their context.
        $message = Auditor::withoutAuditing(fn (): SupportMessage => DB::transaction(
            function () use ($c, $body, $user): SupportMessage {
                $now = now();

                $message = $c->messages()->create([
                    'sender_type' => SupportSenderType::CUSTOMER,
                    'sender_id' => $user?->getAuthIdentifier(),
                    'kind' => SupportMessageKind::MESSAGE,
                    'body' => $body,
                ]);

                $c->forceFill([
                    'status' => SupportConversationStatus::OPEN,
                    'resolved_at' => null,
                    'waiting_since' => $c->waiting_since ?? $now,
                    'last_activity_at' => $now,
                    'opener_seen_at' => $now,
                ])->save();

                return $message;
            },
        ));

        // 4. Tell staff once, and record the one event in place of the silenced
        //    model rows, without the url for the same reason.
        Event::dispatch(new SupportConversationNeedsStaff($c, 'customer_message'));

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
