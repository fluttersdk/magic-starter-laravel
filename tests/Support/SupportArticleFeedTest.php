<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use FlutterSdk\MagicStarter\Enums\SupportArticleVisibility;
use FlutterSdk\MagicStarter\Enums\SupportAudience;
use FlutterSdk\MagicStarter\Models\SupportArticle;
use FlutterSdk\MagicStarter\Support\SupportArticleFeed;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Locks the knowledge-base corpus every reader gets: who sees which article,
 * in which order, and when a write or the clock changes what they see.
 */
class SupportArticleFeedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--path' => __DIR__ . '/../../database/migrations/create_support_articles_table.php',
            '--realpath' => true,
        ]);
    }

    public function test_the_page_corpus_carries_public_articles_only(): void
    {
        $this->makeArticle('public-one', SupportArticleVisibility::PUBLIC);
        $this->makeArticle('ai-one', SupportArticleVisibility::AI_ONLY);
        $this->makeArticle('staff-one', SupportArticleVisibility::STAFF_ONLY);

        $this->assertSame(['public-one'], $this->slugs(SupportAudience::PAGE));
    }

    public function test_the_customer_corpus_adds_ai_only_and_the_staff_corpus_adds_the_rest(): void
    {
        $this->makeArticle('public-one', SupportArticleVisibility::PUBLIC, ['section' => 'a']);
        $this->makeArticle('ai-one', SupportArticleVisibility::AI_ONLY, ['section' => 'b']);
        $this->makeArticle('staff-one', SupportArticleVisibility::STAFF_ONLY, ['section' => 'c']);

        $this->assertSame(['public-one', 'ai-one'], $this->slugs(SupportAudience::CUSTOMER));
        $this->assertSame(['public-one', 'ai-one', 'staff-one'], $this->slugs(SupportAudience::STAFF));
    }

    public function test_a_future_dated_article_appears_on_time_without_a_write(): void
    {
        $this->makeArticle('tomorrow', SupportArticleVisibility::PUBLIC, ['published_at' => now()->addDay()]);

        $this->assertSame([], $this->slugs(SupportAudience::PAGE));

        $this->travel(2)->days();

        $this->assertSame(['tomorrow'], $this->slugs(SupportAudience::PAGE));
    }

    public function test_a_publish_date_inside_the_cache_lifetime_is_applied_to_the_cached_body(): void
    {
        $this->makeArticle('soon', SupportArticleVisibility::PUBLIC, [
            'published_at' => now()->addMinutes(10),
        ]);

        $this->assertSame([], $this->slugs(SupportAudience::PAGE));

        $this->travel(30)->minutes();
        DB::enableQueryLog();

        $this->assertSame(['soon'], $this->slugs(SupportAudience::PAGE));
        $this->assertSame([], DB::getQueryLog(), 'the second read was served from the cache, not rebuilt');
    }

    public function test_an_unpublished_article_never_appears(): void
    {
        $this->makeArticle('unpublished', SupportArticleVisibility::PUBLIC, ['published_at' => null]);

        $this->travel(30)->days();

        $this->assertSame([], $this->slugs(SupportAudience::PAGE));
    }

    public function test_a_locale_still_in_draft_is_left_out_of_that_locale_only(): void
    {
        $this->makeArticle('half-translated', SupportArticleVisibility::PUBLIC, [
            'question' => [
                'en' => 'How do I reset my password?',
                'tr' => 'Sifremi nasil sifirlarim?',
            ],
            'locale_state' => [
                'en' => 'approved',
                'tr' => 'draft',
            ],
        ]);

        $this->assertSame(['half-translated'], $this->slugs(SupportAudience::PAGE, 'en'));
        $this->assertSame([], $this->slugs(SupportAudience::PAGE, 'tr'));
        $this->assertSame(
            'How do I reset my password?',
            SupportArticleFeed::corpus(SupportAudience::PAGE, 'en')[0]['question'],
        );
    }

    public function test_articles_come_in_section_then_sort_then_id_order(): void
    {
        $this->makeArticle('b-first', SupportArticleVisibility::PUBLIC, ['section' => 'b', 'sort' => 1]);
        $this->makeArticle('a-second', SupportArticleVisibility::PUBLIC, ['section' => 'a', 'sort' => 2]);
        $this->makeArticle('a-first', SupportArticleVisibility::PUBLIC, ['section' => 'a', 'sort' => 1]);
        $this->makeArticle('a-tie-late', SupportArticleVisibility::PUBLIC, [
            'id' => '00000000-0000-0000-0000-000000000002',
            'section' => 'a',
            'sort' => 3,
        ]);
        $this->makeArticle('a-tie-early', SupportArticleVisibility::PUBLIC, [
            'id' => '00000000-0000-0000-0000-000000000001',
            'section' => 'a',
            'sort' => 3,
        ]);

        $this->assertSame(
            ['a-first', 'a-second', 'a-tie-early', 'a-tie-late', 'b-first'],
            $this->slugs(SupportAudience::PAGE),
        );
    }

    public function test_an_entry_carries_content_and_no_timestamps(): void
    {
        $this->makeArticle('reset-password', SupportArticleVisibility::PUBLIC);

        $entry = SupportArticleFeed::corpus(SupportAudience::PAGE, 'en')[0];

        $this->assertSame(['id', 'slug', 'section', 'question', 'body'], array_keys($entry));
    }

    public function test_a_save_bumps_the_version_and_the_next_read_sees_the_row(): void
    {
        $article = $this->makeArticle('first', SupportArticleVisibility::PUBLIC);
        $this->assertSame(['first'], $this->slugs(SupportAudience::PAGE));
        $before = Cache::get(SupportArticleFeed::VERSION_KEY);

        // A raw insert fires no model event, so only a stale cache can hide it.
        $this->insertWithoutEvents('second');
        $this->assertSame(['first'], $this->slugs(SupportAudience::PAGE));

        $article->update(['sort' => 9]);

        $this->assertNotSame($before, Cache::get(SupportArticleFeed::VERSION_KEY));
        $this->assertSame(['second', 'first'], $this->slugs(SupportAudience::PAGE));
    }

    public function test_a_delete_bumps_the_version(): void
    {
        $this->makeArticle('keep', SupportArticleVisibility::PUBLIC, ['section' => 'a']);
        $gone = $this->makeArticle('gone', SupportArticleVisibility::PUBLIC, ['section' => 'b']);
        $this->assertSame(['keep', 'gone'], $this->slugs(SupportAudience::PAGE));

        $gone->delete();

        $this->assertSame(['keep'], $this->slugs(SupportAudience::PAGE));
    }

    public function test_readable_fills_a_locale_from_the_default_one_in_the_default_order(): void
    {
        config()->set('magic-starter.defaults.locale', 'en');
        $this->makeArticle('a-english-only', SupportArticleVisibility::PUBLIC, [
            'section' => 'a',
            'question' => ['en' => 'English only?'],
            'body' => ['en' => 'English body.'],
            'locale_state' => ['en' => 'approved'],
        ]);
        $this->makeArticle('b-both', SupportArticleVisibility::PUBLIC, [
            'section' => 'b',
            'question' => ['en' => 'Both?', 'tr' => 'Ikisi de?'],
            'body' => ['en' => 'Both body.', 'tr' => 'Ikisi govde.'],
            'locale_state' => ['en' => 'approved', 'tr' => 'approved'],
        ]);
        $this->makeArticle('0-turkish-only', SupportArticleVisibility::PUBLIC, [
            'section' => '0',
            'question' => ['en' => 'Turkish only draft?', 'tr' => 'Yalniz Turkce?'],
            'body' => ['en' => 'Draft.', 'tr' => 'Turkce govde.'],
            'locale_state' => ['en' => 'draft', 'tr' => 'approved'],
        ]);
        $this->makeArticle('c-hidden', SupportArticleVisibility::STAFF_ONLY, ['section' => 'c']);

        $readable = SupportArticleFeed::readable(SupportAudience::PAGE, 'tr');

        $this->assertSame(['a-english-only', 'b-both', '0-turkish-only'], array_column($readable, 'slug'));
        $this->assertSame(['English only?', 'Ikisi de?', 'Yalniz Turkce?'], array_column($readable, 'question'));
        $this->assertSame(
            SupportArticleFeed::corpus(SupportAudience::PAGE, 'en'),
            SupportArticleFeed::readable(SupportAudience::PAGE, 'en'),
        );
    }

    /**
     * @return list<string>
     */
    private function slugs(SupportAudience $audience, string $locale = 'en'): array
    {
        return array_column(SupportArticleFeed::corpus($audience, $locale), 'slug');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeArticle(
        string $slug,
        SupportArticleVisibility $visibility,
        array $overrides = [],
    ): SupportArticle {
        return SupportArticle::factory()->create([
            'slug' => $slug,
            'visibility' => $visibility,
            ...$overrides,
        ]);
    }

    private function insertWithoutEvents(string $slug): void
    {
        DB::table('support_articles')->insert([
            'id' => '00000000-0000-0000-0000-0000000000ff',
            'slug' => $slug,
            'section' => 'general',
            'sort' => 0,
            'visibility' => SupportArticleVisibility::PUBLIC->value,
            'published_at' => now()->subMinute(),
            'question' => json_encode(['en' => 'Q']),
            'body' => json_encode(['en' => 'B']),
            'locale_state' => json_encode(['en' => 'approved']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
