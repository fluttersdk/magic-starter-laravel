<?php

namespace FlutterSdk\MagicStarter\Tests\Models;

use FlutterSdk\MagicStarter\Enums\SupportArticleVisibility;
use FlutterSdk\MagicStarter\Enums\SupportAudience;
use FlutterSdk\MagicStarter\Models\SupportArticle;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Locks the casts of a knowledge-base article and the three scopes every
 * reader (AI agent, public page, in-app suggestions) filters through.
 */
class SupportArticleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--path' => __DIR__ . '/../../database/migrations/create_support_articles_table.php',
            '--realpath' => true,
        ]);
    }

    public function test_it_casts_its_columns(): void
    {
        $article = SupportArticle::factory()->create([
            'visibility' => SupportArticleVisibility::AI_ONLY,
            'sort' => '3',
            'published_at' => '2026-10-01 09:30:00',
            'question' => [
                'en' => 'How do I reset my password?',
                'tr' => 'Sifremi nasil sifirlarim?',
            ],
            'locale_state' => [
                'en' => 'approved',
                'tr' => 'draft',
            ],
        ])->fresh();

        $this->assertSame(SupportArticleVisibility::AI_ONLY, $article->visibility);
        $this->assertSame(3, $article->sort);
        $this->assertInstanceOf(Carbon::class, $article->published_at);
        $this->assertSame('How do I reset my password?', $article->question['en']);
        $this->assertSame('draft', $article->locale_state['tr']);
    }

    public function test_page_audience_excludes_ai_only_and_staff_only(): void
    {
        $this->makeArticle('public-one', SupportArticleVisibility::PUBLIC);
        $this->makeArticle('ai-one', SupportArticleVisibility::AI_ONLY);
        $this->makeArticle('staff-one', SupportArticleVisibility::STAFF_ONLY);

        $slugs = SupportArticle::query()->visibleTo(SupportAudience::PAGE)->pluck('slug')->all();

        $this->assertSame(['public-one'], $slugs);
    }

    public function test_customer_audience_excludes_staff_only(): void
    {
        $this->makeArticle('public-one', SupportArticleVisibility::PUBLIC);
        $this->makeArticle('ai-one', SupportArticleVisibility::AI_ONLY);
        $this->makeArticle('staff-one', SupportArticleVisibility::STAFF_ONLY);

        $slugs = SupportArticle::query()->visibleTo(SupportAudience::CUSTOMER)->pluck('slug')->sort()->values()->all();

        $this->assertSame(['ai-one', 'public-one'], $slugs);
    }

    public function test_staff_audience_sees_every_visibility(): void
    {
        $this->makeArticle('public-one', SupportArticleVisibility::PUBLIC);
        $this->makeArticle('ai-one', SupportArticleVisibility::AI_ONLY);
        $this->makeArticle('staff-one', SupportArticleVisibility::STAFF_ONLY);

        $this->assertSame(3, SupportArticle::query()->visibleTo(SupportAudience::STAFF)->count());
    }

    public function test_live_hides_a_future_publish_date_until_it_passes_without_a_write(): void
    {
        $this->makeArticle('tomorrow', SupportArticleVisibility::PUBLIC, [
            'published_at' => now()->addDay(),
        ]);

        $this->assertSame(0, SupportArticle::query()->live()->count());

        $this->travel(2)->days();

        $this->assertSame(['tomorrow'], SupportArticle::query()->live()->pluck('slug')->all());
    }

    public function test_live_excludes_an_unpublished_article(): void
    {
        $this->makeArticle('unpublished', SupportArticleVisibility::PUBLIC, [
            'published_at' => null,
        ]);

        $this->assertSame(0, SupportArticle::query()->live()->count());
    }

    public function test_approved_in_excludes_a_locale_that_is_still_a_draft(): void
    {
        $this->makeArticle('half-translated', SupportArticleVisibility::PUBLIC, [
            'locale_state' => [
                'en' => 'approved',
                'tr' => 'draft',
            ],
        ]);

        $this->assertSame(1, SupportArticle::query()->approvedIn('en')->count());
        $this->assertSame(0, SupportArticle::query()->approvedIn('tr')->count());
        $this->assertSame(0, SupportArticle::query()->approvedIn('de')->count());
    }

    public function test_it_refuses_a_second_article_with_the_same_slug(): void
    {
        $this->makeArticle('reset-password', SupportArticleVisibility::PUBLIC);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->makeArticle('reset-password', SupportArticleVisibility::PUBLIC);
    }

    public function test_visibility_and_audience_values_are_the_stored_words(): void
    {
        $this->assertSame('public', SupportArticleVisibility::PUBLIC->value);
        $this->assertSame('ai_only', SupportArticleVisibility::AI_ONLY->value);
        $this->assertSame('staff_only', SupportArticleVisibility::STAFF_ONLY->value);
        $this->assertSame(
            ['customer', 'staff', 'page'],
            array_map(static fn (SupportAudience $audience): string => $audience->value, SupportAudience::cases()),
        );
    }

    public function test_a_single_public_url_serves_every_locale(): void
    {
        config()->set('magic-starter.support.articles.public_url', 'https://example.com/faq');

        $this->assertSame('https://example.com/faq#alerts', SupportArticle::publicUrl('alerts', 'en'));
        $this->assertSame('https://example.com/faq#alerts', SupportArticle::publicUrl('alerts', 'tr'));
    }

    public function test_a_public_url_per_locale_picks_the_locale_then_the_default_locale(): void
    {
        config()->set('magic-starter.defaults.locale', 'en');
        config()->set('magic-starter.support.articles.public_url', [
            'en' => 'https://example.com/faq',
            'tr' => 'https://example.com/tr/faq',
        ]);

        $this->assertSame('https://example.com/tr/faq#alerts', SupportArticle::publicUrl('alerts', 'tr'));
        $this->assertSame('https://example.com/faq#alerts', SupportArticle::publicUrl('alerts', 'en'));
        $this->assertSame('https://example.com/faq#alerts', SupportArticle::publicUrl('alerts', 'de'));
    }

    public function test_there_is_no_public_url_while_none_is_configured_for_the_locale_or_the_default(): void
    {
        config()->set('magic-starter.defaults.locale', 'en');

        config()->set('magic-starter.support.articles.public_url', null);
        $this->assertNull(SupportArticle::publicUrl('alerts', 'en'));

        config()->set('magic-starter.support.articles.public_url', '');
        $this->assertNull(SupportArticle::publicUrl('alerts', 'en'));

        config()->set('magic-starter.support.articles.public_url', ['tr' => 'https://example.com/tr/faq']);
        $this->assertNull(SupportArticle::publicUrl('alerts', 'en'));
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
}
