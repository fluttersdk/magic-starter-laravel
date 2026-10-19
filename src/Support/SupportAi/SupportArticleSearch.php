<?php

namespace FlutterSdk\MagicStarter\Support\SupportAi;

use FlutterSdk\MagicStarter\Contracts\SearchesSupportArticles;
use FlutterSdk\MagicStarter\Enums\SupportAudience;
use FlutterSdk\MagicStarter\Support\SupportArticleFeed;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;

/**
 * The package's knowledge-base search: token overlap over the cached corpus,
 * in PHP, with no database full-text index.
 *
 * Every read goes through {@see SupportArticleFeed::corpus()} for the caller's
 * audience, so an article the audience may not read is never even scored, and
 * {@see self::article()} looks an id up inside that same list rather than
 * loading the row and checking it afterwards.
 *
 * Both the question and the article are folded the same way: ASCII before
 * lower case, because `mb_strtolower('İ')` is a dotted `i` of two code points
 * that never equals the `i` a person types, while `Str::ascii` turns `İ` and
 * `ı` into `I` and `i` first. The same pass drops the other diacritics, so
 * `siklik` finds `sıklık`, the spelling a Turkish customer types most often.
 */
final class SupportArticleSearch implements SearchesSupportArticles
{
    public const MAX_RESULTS = 5;

    /** Points a query word scores when the article's question carries it. */
    private const QUESTION_WEIGHT = 3;

    /** Points a query word scores when the article's body carries it. */
    private const BODY_WEIGHT = 1;

    /**
     * Words that carry no topic in English or Turkish, already folded.
     */
    private const STOPWORDS = [
        'a', 'about', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'can', 'do', 'does', 'for', 'from', 'how',
        'i', 'in', 'is', 'it', 'me', 'my', 'of', 'on', 'or', 'the', 'this', 'that', 'to', 'we', 'what', 'when',
        'where', 'which', 'who', 'why', 'with', 'you', 'your',
        'ama', 'bir', 'bu', 'da', 'de', 'gibi', 'hangi', 'icin', 'ile', 'ki', 'mi', 'mu', 'ne', 'neden',
        'nedir', 'nasil', 'niye', 'o', 'su', 've', 'veya', 'ya',
    ];

    public function __construct(
        private readonly Repository $config,
    ) {}

    public function search(string $query, SupportAudience $audience, string $locale, int $limit): array
    {
        // 1. A question with no topic word would score every article zero.
        $words = $this->words($query);

        if ($words === []) {
            return [];
        }

        // 2. Score each readable article; one that shares no word is no answer.
        $scored = [];

        foreach ($this->corpus($audience, $locale) as $entry) {
            $score = $this->score($words, $entry['question'], $entry['body']);

            if ($score > 0) {
                $scored[] = [
                    'score' => $score,
                    'entry' => $entry,
                ];
            }
        }

        // 3. Best first; the sort is stable, so a tie keeps the corpus order.
        usort($scored, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        return array_column(
            array_slice($scored, 0, max(1, min($limit, self::MAX_RESULTS))),
            'entry',
        );
    }

    /**
     * One article the audience may read, by id, or null for a hidden and a
     * missing id alike.
     *
     * The id is compared as a string, since a model sends a key as either and
     * the key type depends on `use_uuids`.
     *
     * @return array{id: string|int, slug: string, section: string, question: string, body: string}|null
     */
    public function article(int|string $id, SupportAudience $audience, string $locale): ?array
    {
        foreach ($this->corpus($audience, $locale) as $entry) {
            if ((string) $entry['id'] === (string) $id) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * The audience's articles in the locale, each one without approved text
     * there read in the default locale instead.
     *
     * @return list<array{id: string|int, slug: string, section: string, question: string, body: string}>
     */
    private function corpus(SupportAudience $audience, string $locale): array
    {
        $entries = SupportArticleFeed::corpus($audience, $locale);
        $defaultLocale = (string) $this->config->get('magic-starter.defaults.locale');

        if ($locale === $defaultLocale) {
            return $entries;
        }

        $present = array_flip(array_map(static fn (array $entry): string => (string) $entry['id'], $entries));

        foreach (SupportArticleFeed::corpus($audience, $defaultLocale) as $entry) {
            if (! isset($present[(string) $entry['id']])) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @param  list<string>  $words
     */
    private function score(array $words, string $question, string $body): int
    {
        $questionWords = array_flip($this->words($question));
        $bodyWords = array_flip($this->words($body));
        $score = 0;

        foreach ($words as $word) {
            $score += isset($questionWords[$word]) ? self::QUESTION_WEIGHT : 0;
            $score += isset($bodyWords[$word]) ? self::BODY_WEIGHT : 0;
        }

        return $score;
    }

    /**
     * The distinct topic words of a text, folded, with stopwords dropped.
     *
     * @return list<string>
     */
    private function words(string $text): array
    {
        $folded = Str::lower(Str::ascii($text));
        $tokens = preg_split('/[^a-z0-9]+/', $folded, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_diff(array_unique($tokens), self::STOPWORDS));
    }
}
