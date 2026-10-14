<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Audit\Auditor;
use FlutterSdk\MagicStarter\Contracts\OpensSupportConversations;
use FlutterSdk\MagicStarter\Contracts\PostsCustomerSupportMessages;
use FlutterSdk\MagicStarter\Enums\SupportAttachmentOrigin;
use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Enums\SupportConversationStatus;
use FlutterSdk\MagicStarter\Enums\SupportMessageKind;
use FlutterSdk\MagicStarter\Enums\SupportSenderType;
use FlutterSdk\MagicStarter\Events\SupportConversationNeedsStaff;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Jobs\RespondToSupportConversation;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportHandoff;
use FlutterSdk\MagicStarter\Support\SupportRules;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
 * conversation opened without a subject gets the first line of its body.
 *
 * A signed-in opener who taps Contact sales again while a contact-sales
 * conversation of theirs in their current team is not resolved continues it:
 * the body becomes another customer message and the same conversation is
 * returned. The host builds the AI's account context from the conversation's
 * team, so another team's thread is never reused. In-app and public
 * conversations are always new.
 *
 * A signed-in opener the AI desk answers (see {@see SupportHandoff::routesToAi()})
 * starts `pending`: the responder job is queued once the conversation commits
 * and staff hear of it only on a handoff. Every other conversation starts
 * `open` and alerts staff, as it always has.
 *
 * A signed-in opener may send files with the first message (the
 * `support-attachments` feature), which make its body optional, and, with the
 * `support-diagnostics` feature, the app's screenshot and `client_context`.
 * Every file goes through {@see StoreSupportAttachments} and is refused before
 * anything is written. The context keeps the allowlisted keys only, its route
 * without the query or fragment that may carry a token.
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
        private readonly PostsCustomerSupportMessages $messages,
        private readonly StoreSupportAttachments $attachments,
    ) {}

    /**
     * Open a conversation for a signed-in user.
     *
     * @param  array<string, mixed>  $input  topic (required unless the source is `contact_sales` or a subject is
     *                                       sent, then filed under `other`), body, an optional subject and an
     *                                       optional source (`in_app` or `contact_sales`); a contact-sales
     *                                       thread that is continued keeps its own subject and topic, so a sent
     *                                       subject is ignored then; `attachments`, `screenshot` and
     *                                       `client_context` as the features allow
     *
     * @throws ValidationException
     */
    public function open(Authenticatable $user, array $input): SupportConversation
    {
        // 1. Validate the customer's own fields; owner fields are not even read.
        $diagnostics = Features::hasSupportDiagnosticsFeatures();
        $validated = Validator::make($input, SupportRules::conversation(
            [
                SupportConversationSource::IN_APP,
                SupportConversationSource::CONTACT_SALES,
            ],
            topicRequired: true,
            attachments: Features::hasSupportAttachmentsFeatures(),
            diagnostics: $diagnostics,
        ))->validate();
        $source = SupportConversationSource::from(
            Arr::get($validated, 'source', SupportConversationSource::IN_APP->value),
        );
        $body = (string) Arr::get($validated, 'body', '');

        // 2. Every file is checked, re-encoded and admitted before anything is written.
        $screenshot = Arr::get($validated, 'screenshot');
        $files = $this->attachments->prepare(
            $user,
            (array) Arr::get($validated, 'attachments', []),
            $screenshot instanceof UploadedFile ? $screenshot : null,
        );

        // 3. The opener, and the team they were in, come from the account. The
        //    raw attributes are read because `current_team_id` only exists with
        //    the teams feature. Whether the AI desk answers is decided once, here.
        $attributes = $user instanceof Model ? $user->getAttributes() : [];
        $toAi = $this->desk->routesToAi($user instanceof Model ? $user : null, $source);

        $teamId = $attributes['current_team_id'] ?? null;
        $fields = [
            'user_id' => $user->getAuthIdentifier(),
            'team_id' => $teamId,
            'email' => $attributes['email'] ?? null,
            'name' => $attributes['name'] ?? null,
            ...$this->filing(
                $validated,
                $source,
                fallbackTopic: self::FALLBACK_TOPIC,
                untitled: $files[0]['name'] ?? '',
            ),
            'source' => $source,
            'status' => $toAi ? SupportConversationStatus::PENDING : SupportConversationStatus::OPEN,
            ...($diagnostics ? ['client_context' => $this->clientContext($validated)] : []),
        ];

        // 4. Contact sales continues the opener's open thread of this team instead of filing a second one.
        if ($source === SupportConversationSource::CONTACT_SALES && $user instanceof Model) {
            [$conversation, $continued] = DB::transaction(
                fn (): array => $this->continueOrCreate($user, $teamId, $fields, $body, $files),
            );

            if ($continued) {
                DB::transaction(function () use ($conversation, $body, $user, $files): void {
                    $this->attachments->store($this->messages->post($conversation, $body, $user), $files, $user);
                });

                return $conversation;
            }
        } else {
            $conversation = $this->create($fields, $body, $user, $files);
        }

        // 5. A pending conversation goes to the responder once it commits; any other tells staff once.
        if ($toAi) {
            RespondToSupportConversation::dispatch($conversation->getKey(), $conversation->last_customer_message_id);
        }

        $this->announce($conversation, $user, alertStaff: ! $toAi);

        return $conversation;
    }

    /**
     * Find the opener's contact-sales conversation to continue, or create a new one.
     *
     * Runs inside the caller's transaction and locks the opener's own row first.
     * That row always exists, so two taps at the same instant queue on it and the
     * second finds the conversation the first created; a lock on
     * `support_conversations` alone would lock nothing when no row matches yet.
     *
     * @param  array<string, mixed>  $fields
     * @param  list<array{origin: SupportAttachmentOrigin, name: string, mime: string, extension: string,
     *                    contents: string, width: int|null, height: int|null}>  $files  stored only on a new one
     * @return array{0: SupportConversation, 1: bool} the conversation, and whether it already existed
     */
    private function continueOrCreate(
        Model&Authenticatable $user,
        mixed $teamId,
        array $fields,
        string $body,
        array $files,
    ): array {
        // 1. Serialise concurrent taps of one account on a row that is always there.
        $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->first();

        // 2. The newest unresolved contact-sales thread of the team the opener is in now.
        $existing = SupportConversation::query()
            ->where('user_id', $user->getKey())
            ->where('source', SupportConversationSource::CONTACT_SALES->value)
            ->where('status', '!=', SupportConversationStatus::RESOLVED->value)
            ->when(
                $teamId === null,
                fn (Builder $query) => $query->whereNull('team_id'),
                fn (Builder $query) => $query->where('team_id', $teamId),
            )
            ->latest('last_activity_at')
            ->first();

        if ($existing instanceof SupportConversation) {
            return [$existing, true];
        }

        // 3. Nothing to continue.
        return [$this->create($fields, $body, $user, $files), false];
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
            'email' => SupportRules::email(),
            'name' => SupportRules::name(),
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
     * A missing subject is the first line of the body, or `$untitled` for a
     * message of files alone; the topic is shown beside it everywhere, so it
     * is not repeated inside it.
     *
     * @param  array<string, mixed>  $validated
     * @param  string|null  $fallbackTopic  The in-app door's `other`, for a client that sends only a subject;
     *                                      the public door keeps a missing topic missing.
     * @param  string  $untitled  The subject when there is neither a subject nor a body: the first file's name.
     * @return array{topic: string|null, subject: string}
     */
    private function filing(
        array $validated,
        SupportConversationSource $source,
        ?string $fallbackTopic = null,
        string $untitled = '',
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

        $firstLine = trim(Str::before(trim((string) Arr::get($validated, 'body', '')), "\n"));

        return [
            'topic' => $topic,
            'subject' => Str::limit($firstLine === '' ? $untitled : $firstLine, 80),
        ];
    }

    /**
     * The allowlisted context the app sent, with the route cut before its query and fragment.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, string>|null null when the app sent nothing to keep
     */
    private function clientContext(array $validated): ?array
    {
        $context = array_filter(
            Arr::only((array) Arr::get($validated, 'client_context', []), SupportRules::CLIENT_CONTEXT_KEYS),
            static fn (mixed $value): bool => is_string($value) && $value !== '',
        );

        if (isset($context['route'])) {
            $context['route'] = Str::before(Str::before($context['route'], '#'), '?');
        }

        return $context === [] ? null : $context;
    }

    /**
     * Insert the conversation and its first message, retrying a lost number race.
     *
     * Each attempt is its own transaction, so a refused insert rolls back
     * cleanly (a savepoint when the caller holds a transaction) before the next
     * one reads the new maximum. The files are written last, after the insert
     * that can lose the race, so a retry never writes them twice.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array{origin: SupportAttachmentOrigin, name: string, mime: string, extension: string,
     *                    contents: string, width: int|null, height: int|null}>  $files
     *
     * @throws UniqueConstraintViolationException when every attempt lost
     */
    private function create(
        array $attributes,
        string $body,
        ?Authenticatable $sender,
        array $files = [],
    ): SupportConversation {
        $insert = function () use ($attributes, $body, $sender, $files): SupportConversation {
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

            if ($sender !== null) {
                $this->attachments->store($message, $files, $sender);
            }

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
