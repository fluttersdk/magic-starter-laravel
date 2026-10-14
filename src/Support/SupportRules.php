<?php

namespace FlutterSdk\MagicStarter\Support;

use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use Illuminate\Validation\Rule;

/**
 * The one source of the validation rules for what a customer writes to support.
 *
 * Form requests and actions both validate the same fields; reading them from
 * here keeps a limit from drifting between the two.
 */
final class SupportRules
{
    /**
     * The width of the `topic` column; a topic a host adds to `support.topics` past it is refused.
     */
    public const TOPIC_MAX_LENGTH = 32;

    /**
     * The `client_context` keys a conversation keeps; any other key the app sends is dropped.
     *
     * @var list<string>
     */
    public const CLIENT_CONTEXT_KEYS = [
        'route',
        'app_version',
        'build_number',
        'platform',
        'os_version',
        'locale',
        'timezone',
    ];

    /**
     * The longest `client_context` value, in characters.
     */
    public const CLIENT_CONTEXT_MAX_LENGTH = 120;

    /**
     * The topics a host gets when its published config predates `support.topics`.
     *
     * A published `support` block replaces the package's whole block (the
     * merge is shallow), so without this fallback every in-app conversation of
     * such a host would be refused as an invalid topic.
     *
     * @var list<string>
     */
    public const DEFAULT_TOPICS = [
        'billing',
        'technical',
        'account',
        'sales',
        'other',
    ];

    /**
     * The topics a conversation may be filed under.
     *
     * @return list<string>
     */
    public static function topics(): array
    {
        return array_values((array) config('magic-starter.support.topics', self::DEFAULT_TOPICS));
    }

    /**
     * Rules for a message body, capped at `support.message_max_length` characters.
     *
     * @param  bool  $required  false where the caller decides whether a body is needed: a message of files
     *                          alone has none
     * @return list<string>
     */
    public static function body(bool $required = true): array
    {
        return [
            $required ? 'required' : 'nullable',
            'string',
            'max:' . (int) config('magic-starter.support.message_max_length', 2000),
        ];
    }

    /**
     * Rules for a customer's message that may carry files instead of text:
     * the body is required only without `attachments`, with the attachment
     * rules beside it.
     *
     * @return array<string, list<mixed>>
     */
    public static function message(bool $attachments): array
    {
        if (! $attachments) {
            return [
                'body' => self::body(),
            ];
        }

        return [
            'body' => self::bodyBesideFiles(),
            ...SupportAttachmentRules::attachments(),
        ];
    }

    /**
     * Rules for the app's description of where the customer wrote from: each
     * allowlisted key an optional string of at most
     * {@see self::CLIENT_CONTEXT_MAX_LENGTH} characters.
     *
     * @return array<string, list<mixed>>
     */
    public static function clientContext(): array
    {
        $rules = [
            'client_context' => [
                'sometimes',
                'nullable',
                'array',
            ],
        ];

        foreach (self::CLIENT_CONTEXT_KEYS as $key) {
            $rules["client_context.{$key}"] = [
                'sometimes',
                'nullable',
                'string',
                'max:' . self::CLIENT_CONTEXT_MAX_LENGTH,
            ];
        }

        return $rules;
    }

    /**
     * Rules for the address a visitor's thread link is mailed to.
     *
     * @return list<string>
     */
    public static function email(): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
        ];
    }

    /**
     * Rules for the optional name a visitor gives.
     *
     * @return list<string>
     */
    public static function name(): array
    {
        return [
            'nullable',
            'string',
            'max:255',
        ];
    }

    /**
     * Rules for opening a conversation: an optional subject (the opener derives
     * a missing one), body, a topic from `support.topics` no longer than its
     * column, and an optional source limited to the door's own.
     *
     * @param  list<SupportConversationSource>  $sources  The sources this door accepts.
     * @param  bool  $topicRequired  Whether the door demands a topic or, from a client older than the topic
     *                               picker, a subject. Contact sales is filed under `sales` either way, so its
     *                               topic is not even read.
     * @param  bool  $attachments  Whether the door takes `attachments`, which make the body optional.
     * @param  bool  $diagnostics  Whether the door takes the app's `screenshot` and `client_context`.
     * @return array<string, list<mixed>>
     */
    public static function conversation(
        array $sources,
        bool $topicRequired = false,
        bool $attachments = false,
        bool $diagnostics = false,
    ): array {
        $topicPresence = $topicRequired
            ? [
                'exclude_if:source,' . SupportConversationSource::CONTACT_SALES->value,
                'required_without:subject',
            ]
            : [
                'sometimes',
            ];

        return [
            'subject' => [
                'nullable',
                'string',
                'max:255',
            ],
            'body' => $attachments ? self::bodyBesideFiles() : self::body(),
            'topic' => [
                ...$topicPresence,
                'nullable',
                'string',
                'max:' . self::TOPIC_MAX_LENGTH,
                Rule::in(self::topics()),
            ],
            'source' => [
                'sometimes',
                Rule::enum(SupportConversationSource::class)->only($sources),
            ],
            ...($attachments ? SupportAttachmentRules::attachments() : []),
            ...($diagnostics ? [...SupportAttachmentRules::screenshot(), ...self::clientContext()] : []),
        ];
    }

    /**
     * A body that may be left out when files are sent, capped like any other.
     *
     * @return list<string>
     */
    private static function bodyBesideFiles(): array
    {
        return [
            'required_without:attachments',
            ...self::body(required: false),
        ];
    }
}
