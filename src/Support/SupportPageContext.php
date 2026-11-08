<?php

namespace FlutterSdk\MagicStarter\Support;

use FlutterSdk\MagicStarter\Contracts\DescribesSupportPage;

/**
 * The page references a customer's message carries: which screen of the host
 * app they wrote from, as `{kind, id}` pairs the host resolves through
 * {@see DescribesSupportPage}.
 *
 * The input is customer-supplied and optional, so it is never a validation
 * error: whatever is malformed, of a kind not listed in
 * `support.page_context.kinds` or past `support.page_context.max_refs` is
 * dropped. With no kind listed (the default) nothing is kept at all.
 */
final class SupportPageContext
{
    /**
     * An id is a uuid, an integer key or a slug: nothing that could carry a path, a query or markup.
     */
    private const ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/D';

    /**
     * The well-formed, allowed and distinct refs of `$input`, in the order sent, each with `kind` and `id` only.
     *
     * Read on the way in and again before a host sees them, so a kind removed from the config since is dropped.
     *
     * @return list<array{kind: string, id: string}>
     */
    public static function sanitise(mixed $input): array
    {
        // A published config predating the key replaces the whole `support` block, so both reads carry a default.
        $kinds = (array) config('magic-starter.support.page_context.kinds', []);
        $max = (int) config('magic-starter.support.page_context.max_refs', 3);

        if ($kinds === [] || $max < 1 || ! is_array($input)) {
            return [];
        }

        $refs = [];

        foreach ($input as $entry) {
            if (! self::isRef($entry, $kinds)) {
                continue;
            }

            $ref = [
                'kind' => $entry['kind'],
                'id' => $entry['id'],
            ];

            if (in_array($ref, $refs, true)) {
                continue;
            }

            $refs[] = $ref;

            if (count($refs) >= $max) {
                break;
            }
        }

        return $refs;
    }

    /**
     * @param  array<mixed>  $kinds
     *
     * @phpstan-assert-if-true array{kind: string, id: string} $entry
     */
    private static function isRef(mixed $entry, array $kinds): bool
    {
        return is_array($entry)
            && is_string($entry['kind'] ?? null)
            && in_array($entry['kind'], $kinds, true)
            && is_string($entry['id'] ?? null)
            && preg_match(self::ID_PATTERN, $entry['id']) === 1;
    }
}
