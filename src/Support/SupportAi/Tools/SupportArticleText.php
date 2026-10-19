<?php

namespace FlutterSdk\MagicStarter\Support\SupportAi\Tools;

use Illuminate\Contracts\Config\Repository;

/**
 * The door knowledge-base articles walk through on their way into a tool
 * result the model reads.
 *
 * Not {@see UntrustedText}: that one labels customer-authored data and caps
 * every string at 500 characters, while an article is staff-authored and its
 * body is the answer itself. The result has the same shape, a provenance line
 * followed by JSON, so a string stays bounded by its quotes.
 *
 * The Markdown is reduced to text in a fixed order:
 *
 * 1. `mb_scrub`, so no `/u` pattern below returns null on invalid bytes, and
 *    invisible format characters removed.
 * 2. An image becomes its alt text and a link its label: the model reads what
 *    a picture shows but never the url behind it. A reference definition
 *    (`[faq]: https://...`) is dropped and an HTML `<img>` keeps its alt.
 * 3. An autolink and any remaining url become `[link]`, then every HTML tag
 *    is stripped.
 * 4. Heading, emphasis and code markers go, the words stay.
 * 5. Control characters become spaces, newline excepted, and blank-line runs
 *    collapse, so the body keeps its paragraphs.
 * 6. The cap, in characters, with the trailing `…` inside it.
 */
final class SupportArticleText
{
    /** Cap on a single line: a question, or a search result's excerpt. */
    public const LINE_MAX_LENGTH = 240;

    /** Cap on an article body, long enough for any article written as an answer. */
    public const BODY_MAX_LENGTH = 4000;

    private const LINK_MARKER = '[link]';

    private const ELLIPSIS = '…';

    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_THROW_ON_ERROR;

    public function __construct(
        private readonly Repository $config,
    ) {}

    /**
     * Markdown as one line of plain text: a question, or a body's excerpt for a search result.
     */
    public function line(string $markdown): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $this->plain($markdown)));

        return $this->cap($text, self::LINE_MAX_LENGTH);
    }

    /**
     * The article's body as plain text that keeps its paragraphs.
     */
    public function body(string $markdown): string
    {
        return $this->cap($this->plain($markdown), self::BODY_MAX_LENGTH);
    }

    /**
     * The payload as a provenance line naming the app and a JSON document.
     *
     * Pass only text already reduced by {@see self::line()} or {@see self::body()}.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public function render(array $payload): string
    {
        $app = (string) $this->config->get('app.name');
        $app = trim((string) preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $app));

        return ($app === '' ? 'Knowledge-base article' : "{$app} knowledge-base article") . ', staff-authored.'
            . "\n"
            . json_encode($payload, self::JSON_FLAGS);
    }

    private function plain(string $markdown): string
    {
        // 1. Valid bytes before any /u pattern, and no invisible characters.
        $text = (string) preg_replace('/\p{Cf}/u', '', mb_scrub($markdown, 'UTF-8'));

        // 2. Pictures and links keep their words and lose their targets.
        $text = (string) preg_replace('/!\[([^\]]*)\](?:\([^)]*\)|\[[^\]]*\])/u', '$1', $text);
        $text = (string) preg_replace('/\[([^\]]+)\](?:\([^)]*\)|\[[^\]]*\])/u', '$1', $text);
        $text = (string) preg_replace('/^ {0,3}\[[^\]]+\]:.*$/mu', '', $text);
        $text = (string) preg_replace('/<img\b[^>]*?\balt\s*=\s*(["\'])(.*?)\1[^>]*>/iu', '$2', $text);

        // 3. No url survives, then no tag does.
        $text = (string) preg_replace('/<(?:[a-z][a-z0-9+.\-]*:|www\.)[^>\s]*>/iu', self::LINK_MARKER, $text);
        $text = (string) preg_replace('~\b[a-z][a-z0-9+.\-]*://\S+|\bwww\.\S+~iu', self::LINK_MARKER, $text);
        $text = strip_tags($text);

        // 4. Markup goes, the words stay.
        $text = (string) preg_replace('/^ {0,3}#{1,6}\s+/mu', '', $text);
        $text = (string) preg_replace('/(\*\*|__)(.+?)\1/u', '$2', $text);
        $text = str_replace('`', '', $text);

        // 5. One kind of line break, paragraphs kept.
        $text = (string) preg_replace('/(?!\n)[\p{Cc}\p{Zl}\p{Zp}]/u', ' ', str_replace("\r\n", "\n", $text));
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    private function cap(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength - 1) . self::ELLIPSIS;
    }
}
