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
     * Rules for opening a conversation: subject, body, an optional topic from
     * `support.topics`, and an optional source limited to the door's own.
     *
     * @param  list<SupportConversationSource>  $sources  The sources this door accepts.
     * @return array<string, list<mixed>>
     */
    public static function conversation(array $sources): array
    {
        return [
            'subject' => [
                'required',
                'string',
                'max:255',
            ],
            'body' => self::body(),
            'topic' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in((array) config('magic-starter.support.topics', [])),
            ],
            'source' => [
                'sometimes',
                Rule::enum(SupportConversationSource::class)->only($sources),
            ],
        ];
    }
}
