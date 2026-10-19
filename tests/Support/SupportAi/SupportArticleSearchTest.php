<?php

namespace FlutterSdk\MagicStarter\Tests\Support\SupportAi;

use FlutterSdk\MagicStarter\Contracts\SearchesSupportArticles;
use FlutterSdk\MagicStarter\Enums\SupportArticleVisibility;
use FlutterSdk\MagicStarter\Enums\SupportAudience;
use FlutterSdk\MagicStarter\Models\SupportArticle;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportArticleSearch;
use FlutterSdk\MagicStarter\Tests\TestCase;

/**
 * Locks how the knowledge base answers a question: who may see which article,
 * which locale the text comes from, and the order a Turkish or English question
 * ranks the corpus in.
 */
class SupportArticleSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (require __DIR__ . '/../../../database/migrations/create_support_articles_table.php')->up();
    }

    public function test_the_contract_is_bound_to_the_php_scorer(): void
    {
        $this->assertInstanceOf(SupportArticleSearch::class, $this->app->make(SearchesSupportArticles::class));
    }

    public function test_a_turkish_question_ranks_the_matching_title_first(): void
    {
        $this->article('body-mention', [
            'tr' => ['Bildirimler nasıl çalışır?', 'İzleme sıklığı planınıza göre değişir.'],
        ]);
        $this->article('frequency', [
            'tr' => ['İzleme sıklığı nedir?', 'Kontrollerin ne kadar sık çalıştığını belirler.'],
        ]);
        $this->article('unrelated', [
            'tr' => ['Fatura nasıl indirilir?', 'Ayarlar sayfasından indirin.'],
        ]);

        $results = $this->search('izleme sıklığı', SupportAudience::CUSTOMER, 'tr');

        $this->assertSame(['frequency', 'body-mention'], array_column($results, 'slug'));
    }

    public function test_the_fold_matches_any_spelling_of_the_turkish_i(): void
    {
        $this->article('istanbul', [
            'tr' => ['İSTANBUL bölgesi var mı?', 'Evet.'],
        ]);

        $this->assertSame(['istanbul'], $this->slugs('ıstanbul', SupportAudience::CUSTOMER, 'tr'));
        $this->assertSame(['istanbul'], $this->slugs('istanbul', SupportAudience::CUSTOMER, 'tr'));
    }

    public function test_a_question_hit_outranks_a_body_hit(): void
    {
        $this->article('in-body', [
            'en' => ['How do alerts work?', 'Alerts follow your check interval.'],
        ]);
        $this->article('in-question', [
            'en' => ['What is the check interval?', 'It is how often we probe.'],
        ]);

        $this->assertSame(
            ['in-question', 'in-body'],
            array_column($this->search('check interval', SupportAudience::CUSTOMER, 'en'), 'slug'),
        );
    }

    public function test_a_question_of_stopwords_alone_finds_nothing(): void
    {
        $this->article('what', [
            'en' => ['What is this and how?', 'It is what it is.'],
        ]);

        $this->assertSame([], $this->search('what is the', SupportAudience::CUSTOMER, 'en'));
        $this->assertSame([], $this->search('   ', SupportAudience::CUSTOMER, 'en'));
    }

    public function test_an_article_with_no_shared_word_is_left_out(): void
    {
        $this->article('billing', [
            'en' => ['How do I pay?', 'With a card.'],
        ]);

        $this->assertSame([], $this->search('status page', SupportAudience::CUSTOMER, 'en'));
    }

    public function test_one_shared_body_word_is_no_answer_while_a_question_word_or_two_body_words_are(): void
    {
        $this->article('data-deletion', [
            'en' => ['How do I get my data deleted?', 'Ask the owner of your team to remove the account.'],
        ]);
        $this->article('invitations', [
            'en' => ['How do I invite a teammate?', 'Open the members page.'],
        ]);
        $this->article('roles', [
            'en' => ['What can an admin change?', 'Admins can invite members and manage the team.'],
        ]);

        $this->assertSame(
            ['invitations', 'roles'],
            $this->slugs('how do I invite a teammate to my team', SupportAudience::CUSTOMER, 'en'),
        );
    }

    public function test_the_limit_is_capped_at_five(): void
    {
        foreach (range(1, 7) as $index) {
            $this->article("monitor-{$index}", [
                'en' => ["Monitor question {$index}?", 'About monitors.'],
            ]);
        }

        $this->assertCount(5, $this->search('monitor', SupportAudience::CUSTOMER, 'en', 50));
        $this->assertCount(2, $this->search('monitor', SupportAudience::CUSTOMER, 'en', 2));
        $this->assertCount(1, $this->search('monitor', SupportAudience::CUSTOMER, 'en', 0));
    }

    public function test_the_customer_never_finds_a_staff_only_article_and_staff_do(): void
    {
        $this->article('internal', [
            'en' => ['Refund override procedure?', 'Internal steps.'],
        ], SupportArticleVisibility::STAFF_ONLY);

        $this->assertSame([], $this->search('refund override', SupportAudience::CUSTOMER, 'en'));
        $this->assertSame(['internal'], $this->slugs('refund override', SupportAudience::STAFF, 'en'));
    }

    public function test_the_page_never_finds_an_ai_only_article_and_the_customer_does(): void
    {
        $this->article('ai-note', [
            'en' => ['Retry policy details?', 'We retry twice.'],
        ], SupportArticleVisibility::AI_ONLY);

        $this->assertSame([], $this->search('retry policy', SupportAudience::PAGE, 'en'));
        $this->assertSame(['ai-note'], $this->slugs('retry policy', SupportAudience::CUSTOMER, 'en'));
    }

    public function test_an_unapproved_locale_falls_back_to_the_default_locale(): void
    {
        $this->article('frequency', [
            'en' => ['What is the check frequency?', 'How often checks run.'],
            'tr' => ['İzleme sıklığı nedir?', 'Taslak metin.'],
        ], localeState: [
            'en' => SupportArticle::LOCALE_APPROVED,
            'tr' => 'draft',
        ]);

        $results = $this->search('check frequency', SupportAudience::CUSTOMER, 'tr');

        $this->assertSame(['What is the check frequency?'], array_column($results, 'question'));
        $this->assertSame([], $this->search('izleme sıklığı', SupportAudience::CUSTOMER, 'tr'));
    }

    public function test_an_approved_locale_is_read_in_that_locale(): void
    {
        $this->article('frequency', [
            'en' => ['What is the check frequency?', 'How often checks run.'],
            'tr' => ['İzleme sıklığı nedir?', 'Kontrollerin sıklığı.'],
        ]);

        $results = $this->search('izleme sıklığı', SupportAudience::CUSTOMER, 'tr');

        $this->assertSame(['İzleme sıklığı nedir?'], array_column($results, 'question'));
    }

    public function test_article_finds_by_id_only_inside_the_audience(): void
    {
        $public = $this->article('public', [
            'en' => ['Public question?', 'Public body.'],
        ]);
        $internal = $this->article('internal', [
            'en' => ['Internal question?', 'Internal body.'],
        ], SupportArticleVisibility::STAFF_ONLY);
        $search = $this->app->make(SupportArticleSearch::class);

        $customer = SupportAudience::CUSTOMER;

        $this->assertSame('public', $search->article($public->getKey(), $customer, 'en')['slug'] ?? null);
        $this->assertSame('public', $search->article((string) $public->getKey(), $customer, 'en')['slug'] ?? null);
        $this->assertNull($search->article($internal->getKey(), $customer, 'en'));
        $this->assertSame(
            'internal',
            $search->article($internal->getKey(), SupportAudience::STAFF, 'en')['slug'] ?? null,
        );
        $this->assertNull($search->article(999999, SupportAudience::STAFF, 'en'));
    }

    public function test_article_falls_back_to_the_default_locale(): void
    {
        $article = $this->article('only-english', [
            'en' => ['English question?', 'English body.'],
        ]);

        $found = $this->app->make(SupportArticleSearch::class)
            ->article($article->getKey(), SupportAudience::CUSTOMER, 'tr');

        $this->assertSame('English body.', $found['body'] ?? null);
    }

    /**
     * @return list<array{id: string|int, slug: string, section: string, question: string, body: string}>
     */
    private function search(string $query, SupportAudience $audience, string $locale, int $limit = 5): array
    {
        return $this->app->make(SearchesSupportArticles::class)->search($query, $audience, $locale, $limit);
    }

    /**
     * @return list<string>
     */
    private function slugs(string $query, SupportAudience $audience, string $locale): array
    {
        return array_column($this->search($query, $audience, $locale), 'slug');
    }

    /**
     * @param  array<string, array{0: string, 1: string}>  $texts  Question and body, keyed by locale.
     * @param  array<string, string>|null  $localeState  Every locale approved when null.
     */
    private function article(
        string $slug,
        array $texts,
        SupportArticleVisibility $visibility = SupportArticleVisibility::PUBLIC,
        ?array $localeState = null,
    ): SupportArticle {
        return SupportArticle::factory()->create([
            'slug' => $slug,
            'visibility' => $visibility,
            'question' => array_map(static fn (array $text): string => $text[0], $texts),
            'body' => array_map(static fn (array $text): string => $text[1], $texts),
            'locale_state' => $localeState
                ?? array_map(static fn (): string => SupportArticle::LOCALE_APPROVED, $texts),
        ]);
    }
}
