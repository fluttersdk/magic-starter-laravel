<?php

namespace FlutterSdk\MagicStarter\Support;

use FlutterSdk\MagicStarter\Enums\SupportAudience;
use FlutterSdk\MagicStarter\Models\SupportArticle;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The cached knowledge-base corpus of one audience in one locale.
 *
 * Every reader (the AI agent, the public page, in-app suggestions) gets its
 * articles here. The corpus is cached under a key that carries a version token,
 * and an article saved or deleted replaces the token rather than deleting the
 * body, the same scheme as {@see ReleaseFeed}: a rebuild that read the table
 * before a write and stores its body after the write's observer ran stores it
 * under a token nobody reads any more. A superseded body expires with the TTL,
 * which is also the backstop for a write that bypasses model events.
 *
 * The cached body holds every article that has a publish date, including future
 * ones, each tagged with the moment it goes live. The clock is compared on every
 * read, so a future-dated article appears on time with no write and no rebuild.
 */
final class SupportArticleFeed
{
    public const CACHE_KEY = 'magic-starter:support-articles';

    public const VERSION_KEY = 'magic-starter:support-articles:version';

    public const TTL_SECONDS = 3600;

    /**
     * The articles the audience may read now, in the locale, in a fixed order.
     *
     * Ordered by section, sort and id so the same table always yields the same
     * list; an entry carries no timestamp, so the list only changes when the
     * content does.
     *
     * @return list<array{id: string|int, slug: string, section: string, question: string, body: string}>
     */
    public static function corpus(SupportAudience $audience, string $locale): array
    {
        // 1. Read the version first, so a write that lands during the rebuild
        //    leaves this body under the version it replaced.
        $key = implode(':', [self::CACHE_KEY, self::version(), $audience->value, $locale]);

        $entries = Cache::remember($key, self::TTL_SECONDS, static fn (): array => self::entries($audience, $locale));

        // 2. Re-apply the publish date, which the cached body deliberately does not.
        $now = now()->getTimestamp();
        $live = array_filter($entries, static fn (array $entry): bool => $entry['live_from'] <= $now);

        // 3. Drop the internal bookkeeping before the entry leaves.
        return array_values(array_map(static function (array $entry): array {
            unset($entry['live_from']);

            return $entry;
        }, $live));
    }

    /**
     * The audience's articles in the locale, each one without approved text there read in the default locale.
     *
     * A translation that lags never removes an article from a reader. The default locale's corpus
     * fixes the order, so a fallback keeps its place; an article only the requested locale has
     * follows, in that corpus's order. In the default locale this is {@see self::corpus()}.
     *
     * @return list<array{id: string|int, slug: string, section: string, question: string, body: string}>
     */
    public static function readable(SupportAudience $audience, string $locale): array
    {
        $default = (string) config('magic-starter.defaults.locale');

        if ($locale === $default) {
            return self::corpus($audience, $locale);
        }

        $requested = array_column(self::corpus($audience, $locale), null, 'slug');
        $entries = [];

        foreach (self::corpus($audience, $default) as $entry) {
            $entries[] = $requested[$entry['slug']] ?? $entry;
            unset($requested[$entry['slug']]);
        }

        return [
            ...$entries,
            ...array_values($requested),
        ];
    }

    /**
     * Retire every cached corpus: the next read builds a new one from the table.
     *
     * Replaces the version token instead of deleting the bodies, see the class
     * doc block for why.
     */
    public static function forget(): void
    {
        Cache::forever(self::VERSION_KEY, (string) Str::uuid());
    }

    /**
     * The current version token, minted when the store holds none.
     *
     * A token is random rather than a counter, so a token lost to eviction is
     * never minted again and cannot point back at an old body.
     */
    private static function version(): string
    {
        Cache::add(self::VERSION_KEY, (string) Str::uuid());

        return (string) Cache::get(self::VERSION_KEY);
    }

    /**
     * Every dated article the audience may read in the locale, each with the Unix time it goes live.
     *
     * @return list<array{id: string|int, slug: string, section: string, question: string, body: string, live_from: int}>
     */
    private static function entries(SupportAudience $audience, string $locale): array
    {
        return SupportArticle::query()
            ->visibleTo($audience)
            ->approvedIn($locale)
            ->whereNotNull('published_at')
            ->orderBy('section')
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(static fn (SupportArticle $article): array => [
                'id' => $article->getKey(),
                'slug' => $article->slug,
                'section' => $article->section,
                'question' => $article->question[$locale],
                'body' => $article->body[$locale],
                'live_from' => (int) $article->published_at?->getTimestamp(),
            ])
            ->all();
    }
}
