<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Audit\Auditor;
use FlutterSdk\MagicStarter\Contracts\RepliesToSupport;
use FlutterSdk\MagicStarter\Enums\SupportMessageKind;
use FlutterSdk\MagicStarter\Enums\SupportSenderType;
use FlutterSdk\MagicStarter\Events\SupportConversationUpdated;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Models\SupportMessage;
use FlutterSdk\MagicStarter\Notifications\SupportReplyNotification;
use FlutterSdk\MagicStarter\Support\SupportThreadToken;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * Records a staff reply or an internal note and tells the customer about a reply.
 *
 * A note is written, audited and nothing else: no notification, no mail, no
 * broadcast, and it answers no wait. A reply answers the wait and reaches the
 * customer by exactly one route: an opener with an account by database and
 * push plus a realtime nudge, a visitor with no account by a mail carrying a
 * newly minted thread link, which retires the link in every earlier mail.
 */
class ReplyToSupportConversation implements RepliesToSupport
{
    public function reply(Authenticatable $staff, SupportConversation $c, string $body, bool $note): SupportMessage
    {
        // 1. Validate the body against the same cap a customer message has.
        Validator::make([
            'body' => $body,
        ], [
            'body' => [
                'required',
                'string',
                'max:' . (int) config('magic-starter.support.message_max_length', 2000),
            ],
        ])->validate();

        // 2. A visitor's reply is only useful with a link to follow, so a
        //    missing link template refuses before anything is written.
        $mailsVisitor = ! $note && $c->user_id === null;
        $threadUrl = $mailsVisitor ? $this->threadUrlTemplate() : null;

        // 3. Record the message, and for a reply, answer the wait.
        $message = DB::transaction(function () use ($staff, $c, $body, $note): SupportMessage {
            /** @var SupportMessage $message */
            $message = $c->messages()->create([
                'sender_type' => SupportSenderType::STAFF,
                'sender_id' => $staff->getAuthIdentifier(),
                'kind' => $note ? SupportMessageKind::NOTE : SupportMessageKind::MESSAGE,
                'body' => $body,
            ]);

            if (! $note) {
                $c->first_reply_at ??= now();
                $c->waiting_since = null;
                $c->last_activity_at = now();
                $c->save();
            }

            return $message;
        });

        Auditor::record(
            $note ? 'support.note_added' : 'support.replied',
            $c,
            [
                'message_id' => $message->getKey(),
            ],
            $staff,
        );

        if ($note) {
            return $message;
        }

        // 4. Reach the customer by the one route their conversation has.
        if ($threadUrl !== null) {
            $link = str_replace('{token}', rawurlencode(SupportThreadToken::mint($c)), $threadUrl);

            Notification::route('mail', $c->email)->notify(new SupportReplyNotification($message, $link));

            return $message;
        }

        Notification::send($c->user, new SupportReplyNotification($message));

        // 5. A realtime outage must never fail a reply that is already stored.
        rescue(static fn () => event(new SupportConversationUpdated($c)), report: true);

        return $message;
    }

    /**
     * @throws RuntimeException when `magic-starter.support.public.thread_url` is unset
     */
    private function threadUrlTemplate(): string
    {
        $template = config('magic-starter.support.public.thread_url');

        if (! is_string($template) || trim($template) === '') {
            throw new RuntimeException(
                'Set magic-starter.support.public.thread_url (MAGIC_STARTER_SUPPORT_THREAD_URL) to reply to a visitor.',
            );
        }

        return trim($template);
    }
}
