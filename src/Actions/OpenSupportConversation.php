<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Audit\Auditor;
use FlutterSdk\MagicStarter\Contracts\OpensSupportConversations;
use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Enums\SupportConversationStatus;
use FlutterSdk\MagicStarter\Enums\SupportMessageKind;
use FlutterSdk\MagicStarter\Enums\SupportSenderType;
use FlutterSdk\MagicStarter\Events\SupportConversationNeedsStaff;
use FlutterSdk\MagicStarter\Jobs\RespondToSupportConversation;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportHandoff;
use FlutterSdk\MagicStarter\Support\SupportRules;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Default action opening a support conversation with its first message.
 *
 * Who owns the conversation is never read from `$input`: `open()` takes the
 * opener and their current team from the auth context, `openPublic()` stores
 * neither, and every public submission is a new conversation, however many the
 * address already has. Nothing is mailed to a public submitter; the thread link
 * goes out with the first staff reply.
 *
 * A contact-sales conversation is always filed under the `sales` topic, and a
 * conversation opened without a subject gets one from its topic label and the
 * first line of its body.
 *
 * A signed-in opener the AI desk answers (see {@see SupportHandoff::routesToAi()})
 * starts `pending`: the responder job is queued once the conversation commits
 * and staff hear of it only on a handoff. Every other conversation starts
 * `open` and alerts staff, as it always has.
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
     * Where an in-app conversation sent with a subject and no topic is filed: what a client older than
     * the topic picker sends.
     */
    private const FALLBACK_TOPIC = 'other';

    public function __construct(
        private readonly SupportHandoff $desk,
    ) {}

    /**
     * Open a conversation for a signed-in user.
     *
     * @param  array<string, mixed>  $input  topic (required unless the source is `contact_sales` or a subject is
     *                                       sent, then filed under `other`), body, an optional subject and an
     *                                       optional source (`in_app` or `contact_sales`)
     *
     * @throws ValidationException
     */
    public function open(Authenticatable $user, array $input): SupportConversation
    {
        // 1. Validate the customer's own fields; owner fields are not even read.
        $validated = Validator::make($input, SupportRules::conversation([
            SupportConversationSource::IN_APP,
            SupportConversationSource::CONTACT_SALES,
        ], topicRequired: true))->validate();
        $source = SupportConversationSource::from(
            Arr::get($validated, 'source', SupportConversationSource::IN_APP->value),
        );

        // 2. The opener, and the team they were in, come from the account. The
        //    raw attributes are read because `current_team_id` only exists with
        //    the teams feature. Whether the AI desk answers is decided once, here.
        $attributes = $user instanceof Model ? $user->getAttributes() : [];
        $toAi = $this->desk->routesToAi($user instanceof Model ? $user : null, $source);

        $conversation = $this->create(
            [
                'user_id' => $user->getAuthIdentifier(),
                'team_id' => $attributes['current_team_id'] ?? null,
                'email' => $attributes['email'] ?? null,
                'name' => $attributes['name'] ?? null,
                ...$this->filing($validated, $source, fallbackTopic: self::FALLBACK_TOPIC),
                'source' => $source,
                'status' => $toAi ? SupportConversationStatus::PENDING : SupportConversationStatus::OPEN,
            ],
            $validated['body'],
            $user,
        );

        // 3. A pending conversation goes to the responder once it commits; any other tells staff once.
        if ($toAi) {
            RespondToSupportConversation::dispatch($conversation->getKey(), $conversation->last_customer_message_id);
        }

        $this->announce($conversation, $user, alertStaff: ! $toAi);

        return $conversation;
    }

    /**
     * Open a conversation for a visitor with no account.
     *
     * @param  array<string, mixed>  $input  email, name, body, an optional subject and topic, and an optional source
     *                                       (`public_form` or `contact_sales`)
     *
     * @throws ValidationException
     */
    public function openPublic(array $input): SupportConversation
    {
        // 1. Validate, with the address the thread link will be mailed to.
        $validated = Validator::make($input, [
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
            ...SupportRules::conversation([
                SupportConversationSource::PUBLIC_FORM,
                SupportConversationSource::CONTACT_SALES,
            ]),
        ])->validate();
        $source = SupportConversationSource::from(
            Arr::get($validated, 'source', SupportConversationSource::PUBLIC_FORM->value),
        );

        // 2. Always a new, unowned conversation. The address is lower-cased so
        //    the link on verification matches it whatever case it was typed in.
        $conversation = $this->create(
            [
                'user_id' => null,
                'team_id' => null,
                'email' => mb_strtolower(trim($validated['email'])),
                'name' => Arr::get($validated, 'name'),
                ...$this->filing($validated, $source),
                'source' => $source,
            ],
            $validated['body'],
            null,
        );

        // 3. Tell staff once.
        $this->announce($conversation, null);

        return $conversation;
    }

    /**
     * The topic and subject a conversation is filed under.
     *
     * Contact sales is always `sales`, and a missing topic is `$fallbackTopic`.
     * A missing subject is the topic label and the first line of the body, so
     * the staff inbox still reads at a glance.
     *
     * @param  array<string, mixed>  $validated
     * @param  string|null  $fallbackTopic  The in-app door's `other`, for a client that sends only a subject;
     *                                      the public door keeps a missing topic missing.
     * @return array{topic: string|null, subject: string}
     */
    private function filing(
        array $validated,
        SupportConversationSource $source,
        ?string $fallbackTopic = null,
    ): array {
        $topic = $source === SupportConversationSource::CONTACT_SALES
            ? 'sales'
            : Arr::get($validated, 'topic') ?? $fallbackTopic;

        $subject = Arr::get($validated, 'subject');

        if (is_string($subject) && trim($subject) !== '') {
            return [
                'topic' => $topic,
                'subject' => $subject,
            ];
        }

        $firstLine = trim(Str::before(trim((string) $validated['body']), "\n"));
        $label = match (true) {
            $topic === null => null,
            Lang::has('magic-starter::support.topics.' . $topic) => Lang::get(
                'magic-starter::support.topics.' . $topic,
            ),
            default => Str::headline($topic),
        };

        return [
            'topic' => $topic,
            'subject' => Str::limit($label === null ? $firstLine : $label . ': ' . $firstLine, 80),
        ];
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

            $message = $conversation->messages()->create([
                'sender_type' => SupportSenderType::CUSTOMER,
                'sender_id' => $sender?->getAuthIdentifier(),
                'kind' => SupportMessageKind::MESSAGE,
                'body' => $body,
            ]);

            $conversation->forceFill([
                'last_customer_message_id' => $message->getKey(),
                'ai_failures' => 0,
            ])->save();

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

    private function announce(
        SupportConversation $conversation,
        ?Authenticatable $opener,
        bool $alertStaff = true,
    ): void {
        if ($alertStaff) {
            Event::dispatch(new SupportConversationNeedsStaff($conversation, 'opened'));
        }

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
