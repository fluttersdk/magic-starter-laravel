<?php

namespace FlutterSdk\MagicStarter\Support\AiRuns;

/**
 * Scrubs secrets out of an AI run body before it is written, so a prompt that
 * quoted a header or a tool call that carried a password never reaches the log
 * in clear.
 *
 * Two passes. On an array, every entry whose key is a sensitive name
 * (`magic-starter.ai_runs.mask.keys`, compared case-insensitively) has its whole
 * value replaced, whatever its type; the other entries are walked. On every
 * string, each pattern (`mask.patterns` then `mask.extra_patterns`) replaces its
 * named group `secret` when it has one, else its whole match. Other scalars pass
 * through untouched.
 *
 * Fails closed: a string the regex engine gives up on (a backtracking or JIT
 * limit) is replaced whole, because returning it unmasked would leak exactly the
 * oversized body most likely to carry a pasted secret.
 */
final class SecretMasker
{
    public const PLACEHOLDER = '[redacted]';

    /**
     * Sensitive key names, lowercased, as array keys for a constant-time lookup.
     *
     * @var array<string, true>
     */
    private readonly array $keys;

    /**
     * @param  list<string>  $keys  Array keys whose values are replaced outright.
     * @param  list<string>  $patterns  PCRE patterns, delimiters and flags included.
     */
    public function __construct(
        array $keys,
        private readonly array $patterns,
    ) {
        $this->keys = array_fill_keys(array_map(strtolower(...), $keys), true);
    }

    /**
     * A masker over the configured keys, default patterns and extra patterns.
     */
    public static function fromConfig(): self
    {
        /** @var list<string> $keys */
        $keys = config('magic-starter.ai_runs.mask.keys', []);
        /** @var list<string> $patterns */
        $patterns = config('magic-starter.ai_runs.mask.patterns', []);
        /** @var list<string> $extra */
        $extra = config('magic-starter.ai_runs.mask.extra_patterns', []);

        return new self($keys, [...$patterns, ...$extra]);
    }

    /**
     * Mask a body of any shape: arrays keep their keys and nesting.
     */
    public function mask(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->maskString($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = is_string($key) && isset($this->keys[strtolower($key)])
                ? self::PLACEHOLDER
                : $this->mask($item);
        }

        return $value;
    }

    private function maskString(string $text): string
    {
        foreach ($this->patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
                return self::PLACEHOLDER;
            }

            // Last match first, so splicing one never shifts the offsets of those before it.
            foreach (array_reverse($matches) as $match) {
                $secret = $match['secret'] ?? null;

                [$value, $offset] = $secret !== null && $secret[1] >= 0 && $secret[0] !== '' ? $secret : $match[0];

                $text = substr_replace($text, self::PLACEHOLDER, $offset, strlen($value));
            }
        }

        return $text;
    }
}
