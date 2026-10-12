<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Audit\Auditor;
use FlutterSdk\MagicStarter\Contracts\OpensSupportConversations;
use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Enums\SupportMessageKind;
use FlutterSdk\MagicStarter\Enums\SupportSenderType;
use FlutterSdk\MagicStarter\Events\SupportConversationNeedsStaff;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Default action opening a support conversation with its first message.
 *
 * Who owns the conversation is never read from `$input`: `open()` takes the
 * opener and their current team from the auth context, `openPublic()` stores
 * neither, and every public submission is a new conversation, however many the
 * address already has. Nothing is mailed to a public submitter; the thread link
 * goes out with the first staff reply.
 */
class OpenSupportConversation implements OpensSupportConversations
{
    /**
     * How many times an insert that lost the display-number race is retried.
     *
     * The number is the current maximum plus one, so two conversations opened
     * at the same instant read the same maximum and the unique index refuses
     * the second; a retry reads the new maximum.
     */
    private const INSERT_ATTEMPTS = 3;

    /**
     * Open a conversation for a signed-in user.
     *
     * @param  array<string, mixed>  $input  subject, body and an optional source (`in_app` or `contact_sales`)
     *
     * @throws ValidationException
     */
    public function open(Authenticatable $user, array $input): SupportConversation
    {
        // 1. Validate the customer's own fields; owner fields are not even read.
        $validated = $this->validate($input, [
            SupportConversationSource::IN_APP,
            SupportConversationSource::CONTACT_SALES,
        ]);

        // 2. The opener, and the team they were in, come from the account. The
        //    raw attributes are read because `current_team_id` only exists with
        //    the teams feature.
        $attributes = $user instanceof Model ? $user->getAttributes() : [];

        $conversation = $this->create(
            [
                'user_id' => $user->getAuthIdentifier(),
                'team_id' => $attributes['current_team_id'] ?? null,
                'email' => $attributes['email'] ?? null,
                'name' => $attributes['name'] ?? null,
                'subject' => $validated['subject'],
                'source' => Arr::get($validated, 'source', SupportConversationSource::IN_APP->value),
            ],
            $validated['body'],
            $user,
        );

        // 3. Tell staff once, now that the conversation exists for good.
        $this->announce($conversation, $user);

        return $conversation;
    }

    /**
     * Open a conversation for a visitor with no account.
     *
     * @param  array<string, mixed>  $input  email, name, subject, body and an optional source
     *                                       (`public_form` or `contact_sales`)
     *
     * @throws ValidationException
     */
    public function openPublic(array $input): SupportConversation
    {
        // 1. Validate, with the address the thread link will be mailed to.
        $validated = $this->validate(
            $input,
            [
                SupportConversationSource::PUBLIC_FORM,
                SupportConversationSource::CONTACT_SALES,
            ],
            [
                'email' => [
                    'required',
                    'string',
                    'email',
                    'max:255',
                ],
                'name' => [
                    'nullable',
                    'string',
                    'max:255',
                ],
            ],
        );

        // 2. Always a new, unowned conversation. The address is lower-cased so
        //    the link on verification matches it whatever case it was typed in.
        $conversation = $this->create(
            [
                'user_id' => null,
                'team_id' => null,
                'email' => mb_strtolower(trim($validated['email'])),
                'name' => Arr::get($validated, 'name'),
                'subject' => $validated['subject'],
                'source' => Arr::get($validated, 'source', SupportConversationSource::PUBLIC_FORM->value),
            ],
            $validated['body'],
            null,
        );

        // 3. Tell staff once.
        $this->announce($conversation, null);

        return $conversation;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<SupportConversationSource>  $sources  the sources this door accepts
     * @param  array<string, mixed>  $extraRules
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validate(array $input, array $sources, array $extraRules = []): array
    {
        return Validator::make($input, [
            ...$extraRules,
            'subject' => [
                'required',
                'string',
                'max:255',
            ],
            'body' => [
                'required',
                'string',
                'max:' . (int) config('magic-starter.support.message_max_length', 2000),
            ],
            'source' => [
                'sometimes',
                Rule::enum(SupportConversationSource::class)->only($sources),
            ],
        ])->validate();
    }

    /**
     * Insert the conversation and its first message, retrying a lost number race.
     *
     * Each attempt is its own transaction, so a refused insert rolls back
     * cleanly (a savepoint when the caller holds a transaction) before the next
     * one reads the new maximum.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws UniqueConstraintViolationException when every attempt lost
     */
    private function create(array $attributes, string $body, ?Authenticatable $sender): SupportConversation
    {
        $insert = function () use ($attributes, $body, $sender): SupportConversation {
            $now = now();

            $conversation = SupportConversation::query()->create([
                ...$attributes,
                'waiting_since' => $now,
                'last_activity_at' => $now,
                'opener_seen_at' => $now,
            ]);

            $conversation->messages()->create([
                'sender_type' => SupportSenderType::CUSTOMER,
                'sender_id' => $sender?->getAuthIdentifier(),
                'kind' => SupportMessageKind::MESSAGE,
                'body' => $body,
            ]);

            return $conversation;
        };

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction($insert);
            } catch (UniqueConstraintViolationException $lost) {
                if ($attempt >= self::INSERT_ATTEMPTS) {
                    throw $lost;
                }
            }
        }
    }

    private function announce(SupportConversation $conversation, ?Authenticatable $opener): void
    {
        Event::dispatch(new SupportConversationNeedsStaff($conversation, 'opened'));

        Auditor::record(
            'support.conversation_opened',
            $conversation,
            [
                'number' => $conversation->number,
                'source' => $conversation->source->value,
            ],
            $opener,
        );
    }
}
