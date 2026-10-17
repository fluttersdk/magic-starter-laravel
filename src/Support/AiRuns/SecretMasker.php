<?php

namespace FlutterSdk\MagicStarter\Support\AiRuns;

/**
 * Scrubs secrets out of an AI run body before it is written, so a prompt that
 * quoted a header or a tool call that carried a password never reaches the log
 * in clear.
 *
 * Two passes. On an array, every entry whose key is a sensitive name (compared
 * case-insensitively) has its whole value replaced, whatever its type; the
 * other entries are walked. On every string, each pattern replaces its named
 * group `secret` when it has one, else its whole match. One of those patterns
 * is built from the same key names, so `password=...`, `"token": "..."` and
 * `X-Api-Key: ...` written as text are caught too. Other scalars pass through.
 *
 * The defaults live here, like {@see \FlutterSdk\MagicStarter\Audit\Redactor}'s:
 * `magic-starter.ai_runs.mask.extra_keys` and `extra_patterns` EXTEND them and
 * can never shorten them, so a published config cannot quietly start storing
 * credentials. An extra pattern that does not compile refuses the provider's
 * boot rather than failing every write.
 *
 * Fails closed: a string the regex engine gives up on (a backtracking or JIT
 * limit) is replaced whole, because returning it unmasked would leak exactly the
 * oversized body most likely to carry a pasted secret. Each pattern runs in one
 * linear pass, so the cost grows with the length of the text and no more.
 */
final class SecretMasker
{
    public const PLACEHOLDER = '[redacted]';

    /**
     * Patterns applied to every string, delimiters and flags included.
     *
     * @var list<string>
     */
    public const DEFAULT_PATTERNS = [
        '/\bsk-(?:ant-|or-|proj-)?[A-Za-z0-9_\-]{16,}/',
        '/\b[rs]k_(?:live|test)_[A-Za-z0-9]{16,}\b/',
        '/\b(?:ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9]{20,}\b/',
        '/\bgithub_pat_[A-Za-z0-9_]{20,}\b/',
        '/\bAKIA[0-9A-Z]{16}\b/',
        // Not a trailing \b: a key ending in `-` has no word boundary before a space.
        '/\bAIza[0-9A-Za-z_\-]{35}(?![0-9A-Za-z_\-])/',
        '/\bxox[abprs]-[A-Za-z0-9-]{10,}\b/',
        '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/',
        '/\b(?:Bearer|Basic)(?:\s*:\s*|\s+)(?<secret>[A-Za-z0-9._~+\/=-]{8,})/i',
        // The user may be empty and the password may hold an `@`: the greedy run backs off to the last one.
        '/(?<=:\/\/)(?<secret>[^\s:@\/]*:[^\s\/?#]+)@/',
        '/[?&](?:api[_-]?key|access[_-]?token|refresh[_-]?token|id[_-]?token|client[_-]?secret|token|secret'
            . '|password|passwd|signature|sig|auth)=(?<secret>[^&\s#]+)/i',
        // A key pasted without its END line is masked to the end of the text.
        '/-----BEGIN [A-Z ]*PRIVATE KEY(?: BLOCK)?-----[\s\S]*?(?:-----END [A-Z ]*PRIVATE KEY(?: BLOCK)?-----|\z)/',
    ];

    /**
     * Key names whose values are replaced, in an array and written as text.
     *
     * @var list<string>
     */
    public const DEFAULT_KEYS = [
        'authorization',
        'proxy-authorization',
        'password',
        'passwd',
        'secret',
        'token',
        'auth_token',
        'x-auth-token',
        'api_key',
        'api-key',
        'apikey',
        'x-api-key',
        'access_token',
        'refresh_token',
        'id_token',
        'client_secret',
        'private_key',
        'cookie',
        'set-cookie',
    ];

    /**
     * Sensitive key names, lowercased, as array keys for a constant-time lookup.
     *
     * @var array<string, true>
     */
    private readonly array $keys;

    /**
     * The defaults, the key-value pattern, then the extras.
     *
     * @var list<string>
     */
    private readonly array $patterns;

    /**
     * @param  list<string>  $extraKeys  Key names masked on top of {@see self::DEFAULT_KEYS}.
     * @param  list<string>  $extraPatterns  PCRE patterns applied after {@see self::DEFAULT_PATTERNS}.
     */
    public function __construct(array $extraKeys = [], array $extraPatterns = [])
    {
        $keys = array_values(array_unique(array_map(strtolower(...), [...self::DEFAULT_KEYS, ...$extraKeys])));

        $this->keys = array_fill_keys($keys, true);
        $this->patterns = [
            ...self::DEFAULT_PATTERNS,
            self::keyValuePattern($keys),
            ...$extraPatterns,
        ];
    }

    /**
     * A masker over the defaults and the configured extras.
     */
    public static function fromConfig(): self
    {
        /** @var list<string> $keys */
        $keys = config('magic-starter.ai_runs.mask.extra_keys', []);
        /** @var list<string> $patterns */
        $patterns = config('magic-starter.ai_runs.mask.extra_patterns', []);

        return new self($keys, $patterns);
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

    /**
     * `key: value`, `key=value`, `"key": "value"` and `'key': 'value'`, with an
     * optional auth scheme before the value. The longest name is tried first,
     * and a name glued to a longer word (`tokens:`, `x-token:`) is not one.
     *
     * @param  list<string>  $keys  Lowercased.
     */
    private static function keyValuePattern(array $keys): string
    {
        usort($keys, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $names = implode('|', array_map(static fn (string $key): string => preg_quote($key, '/'), $keys));

        // A quoted value is masked whole, spaces included; an unquoted one runs to the end of
        // its line or the next separator, so a passphrase of several words is not half kept.
        return '/(?<![\w-])["\']?(?:' . $names . ')["\']?\s*[:=]\s*'
            . '(?|"(?:(?:Bearer|Token|Basic)\s+)?(?<secret>[^"]+)"'
            . '|\'(?:(?:Bearer|Token|Basic)\s+)?(?<secret>[^\']+)\''
            // A quote that never closes: output cut at max_tokens, or a body cut at the cap.
            . '|["\'](?:(?:Bearer|Token|Basic)\s+)?(?<secret>[^"\'\r\n,;&}]*[^"\'\s,;&}])'
            . '|(?:(?:Bearer|Token|Basic)\s+)?(?<secret>[^"\'\r\n,;&}]*[^"\'\s,;&}]))/i';
    }

    private function maskString(string $text): string
    {
        foreach ($this->patterns as $pattern) {
            $masked = $this->replaceAll($pattern, $text);

            if ($masked === null) {
                return self::PLACEHOLDER;
            }

            $text = $masked;
        }

        return $text;
    }

    /**
     * Every match of one pattern replaced in a single left-to-right pass that
     * appends to one buffer, so the cost stays linear however many secrets the
     * text holds and only one match is held in memory at a time.
     *
     * @return string|null Null when the regex engine gave up.
     */
    private function replaceAll(string $pattern, string $text): ?string
    {
        $masked = '';
        $offset = 0;
        $length = strlen($text);

        while ($offset <= $length) {
            $found = preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $offset);

            if ($found === false) {
                return null;
            }

            if ($found === 0) {
                break;
            }

            [$whole, $start] = $match[0];
            $secret = $match['secret'] ?? null;
            $replacement = $secret === null || $secret[0] === null || $secret[0] === ''
                ? self::PLACEHOLDER
                : substr_replace($whole, self::PLACEHOLDER, $secret[1] - $start, strlen($secret[0]));

            $masked .= substr($text, $offset, $start - $offset) . $replacement;
            $offset = $start + strlen($whole);

            // An empty match would be found again at the same offset: step over one byte.
            if ($whole === '') {
                $masked .= substr($text, $offset, 1);
                $offset++;
            }
        }

        return $masked . substr($text, $offset);
    }
}
