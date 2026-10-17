<?php

namespace FlutterSdk\MagicStarter\Support\AiPrompts;

/**
 * The one check of whether a prompt body still carries the literals its
 * registration requires, shared by the registry, the resolver and the save path.
 *
 * Runs of whitespace collapse to one space on both sides, so a line wrap in an
 * edited body does not count as a missing phrase; the match is case-sensitive,
 * because the phrases are vocabulary the model's output is parsed against.
 */
final class RequiredPhrases
{
    /**
     * @param  list<string>  $phrases
     * @return list<string> The phrases the body does not contain, in the given order.
     */
    public static function missing(string $body, array $phrases): array
    {
        $body = self::normalise($body);

        return array_values(array_filter(
            $phrases,
            static fn (string $phrase): bool => ! str_contains($body, self::normalise($phrase)),
        ));
    }

    /**
     * The phrases as one readable list for a message, each in brackets: `[a], [b]`.
     *
     * @param  list<string>  $missing
     */
    public static function describe(array $missing): string
    {
        return implode(', ', array_map(static fn (string $phrase): string => "[{$phrase}]", $missing));
    }

    private static function normalise(string $text): string
    {
        return (string) preg_replace('/\s+/', ' ', $text);
    }
}
