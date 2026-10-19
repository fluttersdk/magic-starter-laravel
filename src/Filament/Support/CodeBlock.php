<?php

namespace FlutterSdk\MagicStarter\Filament\Support;

/**
 * One value as the panel's code view shows it: pretty-printed JSON when it is
 * structured (an array, or a string holding a JSON object or list), otherwise
 * the text as it was, line breaks kept.
 *
 * Every byte reaches the page through `e()`: {@see html()} escapes each token
 * and each gap between tokens before wrapping it, so a value can colour the
 * view but never inject markup into it.
 */
final class CodeBlock
{
    public const JSON = 'json';

    public const TEXT = 'text';

    /**
     * One JSON token: a string (a key when a colon follows), a number, or a
     * literal. Strings honour backslash escapes, so `\"` does not end one.
     * The string pattern is possessive: a backtracking one ran out of PCRE
     * stack on a string past about 9 KB and left the rest uncoloured.
     */
    private const TOKEN = '/("(?:[^"\\\\]++|\\\\.)*+")(\s*:)?|(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)|\b(true|false|null)\b/';

    /**
     * A JSON string or a run of whitespace, to strip what lies between tokens.
     */
    private const STRING_OR_SPACE = '/"(?:[^"\\\\]++|\\\\.)*+"|\s++/';

    private function __construct(
        public readonly string $language,
        public readonly string $source,
    ) {}

    /**
     * The block for [$state], or null when there is nothing to show.
     */
    public static function from(mixed $state): ?self
    {
        if (blank($state)) {
            return null;
        }

        if (is_array($state) || is_object($state)) {
            return new self(self::JSON, self::pretty($state));
        }

        $text = (string) $state;
        $decoded = self::structured($text);

        if ($decoded === null) {
            return new self(self::TEXT, $text);
        }

        // Pretty-print only what re-encodes to the same JSON. Decoding loses an
        // empty object's braces, a duplicate key and a big integer's digits, and
        // the view and its copy button must show what was stored, so such text
        // is highlighted as it was written.
        return self::compact($text) === self::encode($decoded, 0)
            ? new self(self::JSON, self::encode($decoded, JSON_PRETTY_PRINT))
            : new self(self::JSON, $text);
    }

    /**
     * The escaped HTML of the block: JSON tokens wrapped in `msl-code-*`
     * spans, text as it was.
     */
    public function html(): string
    {
        if ($this->language === self::TEXT) {
            return e($this->source);
        }

        $html = '';
        $offset = 0;

        preg_match_all(self::TOKEN, $this->source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            [$token, $start] = $match[0];
            $html .= e(substr($this->source, $offset, $start - $offset)) . self::span($match);
            $offset = $start + strlen($token);
        }

        return $html . e(substr($this->source, $offset));
    }

    /**
     * How many lines the block holds, for its header.
     */
    public function lines(): int
    {
        return substr_count($this->source, "\n") + 1;
    }

    /**
     * The JSON object or list [$text] holds, or null when it holds anything
     * else. A bare scalar (`42`, `"quoted"`) stays text: highlighting it would
     * only recolour a word.
     *
     * Objects decode as objects, so an empty one stays `{}`.
     *
     * @return array<mixed>|object|null
     */
    private static function structured(string $text): array|object|null
    {
        $trimmed = ltrim($text);

        if ($trimmed === '' || ! in_array($trimmed[0], ['{', '['], true)) {
            return null;
        }

        $decoded = json_decode($text);

        return json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || is_object($decoded))
            ? $decoded
            : null;
    }

    private static function pretty(mixed $value): string
    {
        return self::encode($value, JSON_PRETTY_PRINT);
    }

    /**
     * Invalid UTF-8 is substituted rather than failing the whole encode, which
     * left an empty block.
     */
    private static function encode(mixed $value, int $flags): string
    {
        return (string) json_encode(
            $value,
            $flags | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
                | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }

    /**
     * [$json] with the whitespace between its tokens removed, strings intact.
     */
    private static function compact(string $json): string
    {
        return (string) preg_replace_callback(
            self::STRING_OR_SPACE,
            static fn (array $match): string => $match[0][0] === '"' ? $match[0] : '',
            $json,
        );
    }

    /**
     * @param  array<int, array{0: string, 1: int}>  $match
     */
    private static function span(array $match): string
    {
        // 1. A string, and a key when a colon follows it.
        if (($match[1][0] ?? '') !== '') {
            $isKey = ($match[2][0] ?? '') !== '';
            $class = $isKey ? 'msl-code-key' : 'msl-code-str';

            return '<span class="' . $class . '">' . e($match[1][0]) . '</span>' . e($match[2][0] ?? '');
        }

        // 2. A number.
        if (($match[3][0] ?? '') !== '') {
            return '<span class="msl-code-num">' . e($match[3][0]) . '</span>';
        }

        // 3. true, false or null.
        return '<span class="msl-code-lit">' . e($match[4][0]) . '</span>';
    }
}
