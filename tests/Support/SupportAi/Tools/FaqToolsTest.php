<?php

namespace FlutterSdk\MagicStarter\Tests\Support\SupportAi\Tools;

use FlutterSdk\MagicStarter\Enums\SupportArticleVisibility;
use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Enums\SupportConversationStatus;
use FlutterSdk\MagicStarter\Models\SupportArticle;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Support\SupportAi\Tools\GetFaqArticleTool;
use FlutterSdk\MagicStarter\Support\SupportAi\Tools\SearchFaqTool;
use FlutterSdk\MagicStarter\Support\SupportAi\Tools\SupportToolContext;
use FlutterSdk\MagicStarter\Tests\TestCase;

/**
 * The two knowledge-base tools the support AI reads with: the audience comes
 * from the context alone, a hidden article answers exactly like a missing one,
 * and no image url or link target ever reaches the model.
 */
class FaqToolsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.name' => 'Acme',
        ]);

        foreach ([
            'create_support_conversations_table.php',
            'create_support_articles_table.php',
        ] as $file) {
            (require __DIR__ . '/../../../../database/migrations/' . $file)->up();
        }
    }

    public function test_a_customer_gets_the_same_sentence_for_a_staff_only_article_as_for_a_missing_one(): void
    {
        $staffOnly = $this->staffOnlyArticle();
        $tool = $this->app->make(GetFaqArticleTool::class);
        $context = SupportToolContext::forConversation($this->conversation());

        $hidden = $tool->handle($context, ['id' => $staffOnly->id]);
        $missing = $tool->handle($context, ['id' => 999999]);

        $this->assertSame($missing, $hidden);
        $this->assertStringNotContainsString('Internal steps', $hidden);
    }

    public function test_staff_read_a_staff_only_article(): void
    {
        $staffOnly = $this->staffOnlyArticle();

        $result = $this->app->make(GetFaqArticleTool::class)
            ->handle(SupportToolContext::forStaff($this->conversation()), ['id' => $staffOnly->id]);

        $this->assertStringContainsString('Internal steps.', $result);
        $this->assertStringStartsWith('Acme knowledge-base article, staff-authored', $result);
    }

    public function test_the_page_context_never_reads_an_ai_only_article(): void
    {
        $aiOnly = $this->article(
            'ai-note',
            'Retry policy details?',
            'We retry twice.',
            SupportArticleVisibility::AI_ONLY,
        );
        $tool = $this->app->make(GetFaqArticleTool::class);

        $this->assertSame(
            $tool->handle(SupportToolContext::none(), ['id' => 999999]),
            $tool->handle(SupportToolContext::none(), ['id' => $aiOnly->id]),
        );
    }

    public function test_an_audience_argument_is_ignored(): void
    {
        $staffOnly = $this->staffOnlyArticle();
        $context = SupportToolContext::forConversation($this->conversation());

        $article = $this->app->make(GetFaqArticleTool::class)->handle($context, [
            'id' => $staffOnly->id,
            'audience' => 'staff',
        ]);
        $search = $this->app->make(SearchFaqTool::class)->handle($context, [
            'query' => 'refund override',
            'audience' => 'staff',
        ]);

        $this->assertStringNotContainsString('Internal steps', $article);
        $this->assertStringNotContainsString('internal', $search);
    }

    public function test_an_unusable_id_is_refused(): void
    {
        $tool = $this->app->make(GetFaqArticleTool::class);
        $context = SupportToolContext::forConversation($this->conversation());

        $this->assertSame($tool->handle($context, []), $tool->handle($context, ['id' => ['1']]));
        $this->assertStringNotContainsString('{', $tool->handle($context, []));
    }

    public function test_the_article_body_is_text_with_alt_text_and_no_url(): void
    {
        $article = $this->article('setup', 'How do I add a monitor?', implode("\n", [
            '# Adding a monitor',
            '',
            'Open the **Monitors** tab. ![The monitors tab](https://cdn.example.com/tab.png)',
            'See [the guide](https://docs.example.com/guide) or [the faq][faq].',
            '<img src="https://cdn.example.com/x.png" alt="An inline screenshot">',
            'Mail us at <https://help.example.com> or www.example.com/help.',
            '',
            '[faq]: https://docs.example.com/faq',
        ]));

        $result = $this->app->make(GetFaqArticleTool::class)
            ->handle(SupportToolContext::forConversation($this->conversation()), ['id' => $article->id]);

        $this->assertStringContainsString('The monitors tab', $result);
        $this->assertStringContainsString('An inline screenshot', $result);
        $this->assertStringContainsString('the guide', $result);
        $this->assertStringContainsString('Monitors', $result);
        $this->assertStringNotContainsString('example.com', $result);
        $this->assertStringNotContainsString('](', $result);
        $this->assertStringNotContainsString('**', $result);
        $this->assertStringNotContainsString('<img', $result);
    }

    public function test_the_article_body_is_capped(): void
    {
        $article = $this->article('long', 'A long article?', str_repeat('word ', 5000));

        $result = $this->app->make(GetFaqArticleTool::class)
            ->handle(SupportToolContext::forConversation($this->conversation()), ['id' => $article->id]);

        $this->assertLessThan(5000, mb_strlen($result));
    }

    public function test_search_lists_id_slug_question_and_a_short_plain_excerpt(): void
    {
        $article = $this->article('frequency', 'What is the check frequency?', implode(' ', [
            '![Chart](https://cdn.example.com/chart.png)',
            str_repeat('Checks run on your plan interval. ', 20),
        ]));

        $result = $this->app->make(SearchFaqTool::class)
            ->handle(SupportToolContext::forConversation($this->conversation()), ['query' => 'check frequency']);

        [$line, $json] = explode("\n", $result, 2);
        $entries = json_decode($json, true);

        $this->assertSame('Acme knowledge-base article, staff-authored.', $line);
        $this->assertSame($article->id, $entries[0]['id']);
        $this->assertSame('frequency', $entries[0]['slug']);
        $this->assertSame('What is the check frequency?', $entries[0]['question']);
        $this->assertLessThanOrEqual(240, mb_strlen($entries[0]['excerpt']));
        $this->assertStringStartsWith('Chart Checks run', $entries[0]['excerpt']);
        $this->assertStringNotContainsString('example.com', $result);
        $this->assertArrayNotHasKey('body', $entries[0]);
    }

    public function test_search_for_a_customer_leaves_staff_only_out_and_staff_get_it(): void
    {
        $this->staffOnlyArticle();
        $conversation = $this->conversation();
        $tool = $this->app->make(SearchFaqTool::class);

        $customer = $tool->handle(SupportToolContext::forConversation($conversation), [
            'query' => 'refund override',
        ]);
        $staff = $tool->handle(SupportToolContext::forStaff($conversation), ['query' => 'refund override']);

        $this->assertStringNotContainsString('internal', $customer);
        $this->assertStringContainsString('"slug":"internal"', $staff);
    }

    public function test_search_honours_the_limit_argument(): void
    {
        foreach (range(1, 4) as $index) {
            $this->article("monitor-{$index}", "Monitor question {$index}?", 'About monitors.');
        }

        $result = $this->app->make(SearchFaqTool::class)
            ->handle(SupportToolContext::forConversation($this->conversation()), [
                'query' => 'monitor',
                'limit' => 2,
            ]);

        $this->assertCount(2, json_decode(explode("\n", $result, 2)[1], true));
    }

    public function test_search_without_a_match_or_a_query_answers_a_sentence(): void
    {
        $tool = $this->app->make(SearchFaqTool::class);
        $context = SupportToolContext::forConversation($this->conversation());

        $this->assertStringNotContainsString('{', $tool->handle($context, ['query' => 'nothing here']));
        $this->assertStringNotContainsString('{', $tool->handle($context, []));
    }

    public function test_the_tools_read_the_app_locale(): void
    {
        SupportArticle::factory()->create([
            'slug' => 'frequency',
            'question' => [
                'en' => 'What is the check frequency?',
                'tr' => 'İzleme sıklığı nedir?',
            ],
            'body' => [
                'en' => 'How often checks run.',
                'tr' => 'Kontrollerin ne sıklıkla çalıştığı.',
            ],
            'locale_state' => [
                'en' => SupportArticle::LOCALE_APPROVED,
                'tr' => SupportArticle::LOCALE_APPROVED,
            ],
        ]);
        $this->app->setLocale('tr');

        $result = $this->app->make(SearchFaqTool::class)
            ->handle(SupportToolContext::forConversation($this->conversation()), ['query' => 'izleme sıklığı']);

        $this->assertStringContainsString('İzleme sıklığı nedir?', $result);
    }

    public function test_the_tool_shapes_are_stable(): void
    {
        $search = $this->app->make(SearchFaqTool::class);
        $get = $this->app->make(GetFaqArticleTool::class);

        $this->assertSame('search_faq', $search->name());
        $this->assertSame('get_faq_article', $get->name());
        $this->assertSame(['query'], $search->schema()['required']);
        $this->assertSame(['id'], $get->schema()['required']);
    }

    private function article(
        string $slug,
        string $question,
        string $body,
        SupportArticleVisibility $visibility = SupportArticleVisibility::PUBLIC,
    ): SupportArticle {
        return SupportArticle::factory()->create([
            'slug' => $slug,
            'visibility' => $visibility,
            'question' => [
                'en' => $question,
            ],
            'body' => [
                'en' => $body,
            ],
        ]);
    }

    private function staffOnlyArticle(): SupportArticle
    {
        return $this->article(
            'internal',
            'Refund override procedure?',
            'Internal steps.',
            SupportArticleVisibility::STAFF_ONLY,
        );
    }

    private function conversation(): SupportConversation
    {
        return SupportConversation::create([
            'email' => 'visitor@example.com',
            'subject' => 'Question',
            'source' => SupportConversationSource::IN_APP,
            'status' => SupportConversationStatus::PENDING,
        ]);
    }
}
