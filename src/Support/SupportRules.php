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
     * @return list<string>
     */
    public static function body(): array
    {
        return [
            'required',
            'string',
            'max:' . (int) config('magic-starter.support.message_max_length', 2000),
        ];
    }

    /**
     * Rules for opening a conversation: an optional subject (the opener derives
     * a missing one), body, a topic from `support.topics`, and an optional
     * source limited to the door's own.
     *
     * @param  list<SupportConversationSource>  $sources  The sources this door accepts.
     * @param  bool  $topicRequired  Whether the door demands a topic; Contact sales is filed under `sales` either way.
     * @return array<string, list<mixed>>
     */
    public static function conversation(array $sources, bool $topicRequired = false): array
    {
        return [
            'subject' => [
                'nullable',
                'string',
                'max:255',
            ],
            'body' => self::body(),
            'topic' => [
                $topicRequired
                    ? 'required_unless:source,' . SupportConversationSource::CONTACT_SALES->value
                    : 'sometimes',
                'nullable',
                'string',
                Rule::in(self::topics()),
            ],
            'source' => [
                'sometimes',
                Rule::enum(SupportConversationSource::class)->only($sources),
            ],
        ];
    }
}
