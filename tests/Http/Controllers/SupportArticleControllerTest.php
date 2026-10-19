<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Contracts\ResolvesSupportArticlePlaceholders;
use FlutterSdk\MagicStarter\Enums\SupportArticleVisibility;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Models\SupportArticle;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\SanctumServiceProvider;
use Orchestra\Testbench\Attributes\DefineEnvironment;

/**
 * The in-app article suggestions a signed-in customer sees while writing to support.
 *
 * The audience is the public page's, never the customer's: the endpoint backs a
 * link to the public FAQ, so an `ai_only` article that page cannot show must
 * not be suggested. Every hidden case asserts the visible article beside it, so
 * a route that finds nothing at all cannot pass.
 */
#[DefineEnvironment('withArticlesEnabled')]
class SupportArticleControllerTest extends TestCase
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            SanctumServiceProvider::class,
            MagicStarterServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('magic-starter.features', [
            Features::support(),
        ]);
        $app['config']->set('auth.providers.users.model', ConcreteUser::class);
    }

    /**
     * @param  Application  $app
     */
    protected function withArticlesEnabled($app): void
    {
        $app['config']->set('magic-starter.support.articles.enabled', true);
    }

    /**
     * @param  Application  $app
     */
    protected function withArticlesDisabled($app): void
    {
        $app['config']->set('magic-starter.support.articles.enabled', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique()->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->string('current_team_id')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->timestamps();
        });

        (require __DIR__ . '/../../../database/migrations/create_support_articles_table.php')->up();
    }

    protected function tearDown(): void
    {
        MagicStarter::reset();

        parent::tearDown();
    }

    public function test_a_guest_gets_401(): void
    {
        $this->article('public', 'How do alerts work?');

        $this->getJson('/support/articles?search=alerts')->assertUnauthorized();
    }

    public function test_only_the_public_article_is_suggested_whatever_else_matches(): void
    {
        $this->article('public', 'How do alerts work?');
        $this->article('ai-only', 'Alerts for the AI?', visibility: SupportArticleVisibility::AI_ONLY);
        $this->article('staff-only', 'Alerts for staff?', visibility: SupportArticleVisibility::STAFF_ONLY);

        $this->signIn();
        $response = $this->getJson('/support/articles?search=alerts');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('public', $response->json('data.0.slug'));
        $this->assertStringNotContainsString('ai-only', (string) $response->getContent());
        $this->assertStringNotContainsString('staff-only', (string) $response->getContent());
    }

    public function test_an_article_dated_in_the_future_is_hidden_until_it_goes_live(): void
    {
        $this->article('live', 'How do alerts work?');
        $this->article('later', 'Alerts next month?', publishedAt: now()->addDay());

        $this->signIn();

        $this->getJson('/support/articles?search=alerts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'live');
    }

    public function test_the_suggestion_carries_plain_text_and_no_markdown_or_image(): void
    {
        $this->article(
            'screenshots',
            'How do I read the dashboard?',
            body: "## Dashboard\n\nOpen the **dashboard** first.\n\n![Overview chart](https://cdn.example.com/chart.png)\n\n"
                . 'See [the guide](https://example.com/guide) for more.',
        );

        $this->signIn();
        $response = $this->getJson('/support/articles?search=dashboard')->assertOk();
        $suggestion = $response->json('data.0');

        $this->assertSame(['id', 'slug', 'question', 'excerpt', 'url'], array_keys($suggestion));
        $this->assertSame(
            'Dashboard Open the dashboard first. Overview chart See the guide for more.',
            $suggestion['excerpt'],
        );
        $this->assertStringNotContainsString('![', (string) $response->getContent());
        $this->assertStringNotContainsString('cdn.example.com', (string) $response->getContent());
        $this->assertStringNotContainsString('example.com/guide', (string) $response->getContent());
    }

    public function test_the_excerpt_carries_the_hosts_placeholder_values_when_a_resolver_is_bound(): void
    {
        $this->article('regions', 'Where are monitors checked from?', body: 'From [[faq.region_count]] regions.');
        $this->app->instance(ResolvesSupportArticlePlaceholders::class, new class implements ResolvesSupportArticlePlaceholders
        {
            public function resolve(string $markdown, string $locale): string
            {
                return str_replace('[[faq.region_count]]', "five ({$locale})", $markdown);
            }
        });

        $this->signIn();

        $this->getJson('/support/articles?search=regions&locale=en')
            ->assertOk()
            ->assertJsonPath('data.0.excerpt', 'From five (en) regions.');
    }

    public function test_the_url_is_the_public_page_with_the_slug_as_anchor_or_null_when_unset(): void
    {
        $this->article('alerts', 'How do alerts work?');
        $this->signIn();

        $this->getJson('/support/articles?search=alerts')->assertOk()->assertJsonPath('data.0.url', null);

        config()->set('magic-starter.support.articles.public_url', 'https://example.com/help');

        $this->getJson('/support/articles?search=alerts')
            ->assertOk()
            ->assertJsonPath('data.0.url', 'https://example.com/help#alerts');
    }

    public function test_at_most_five_suggestions_come_back(): void
    {
        foreach (range(1, 7) as $index) {
            $this->article("monitor-{$index}", "Monitor question {$index}?");
        }

        $this->signIn();

        $this->getJson('/support/articles?search=monitor')->assertOk()->assertJsonCount(5, 'data');
    }

    public function test_the_locale_picks_the_text_and_an_unapproved_one_falls_back_to_the_default(): void
    {
        $this->article('frequency', 'What is the check frequency?', texts: [
            'tr' => ['İzleme sıklığı nedir?', 'Kontrollerin sıklığı.'],
        ]);
        $this->article('draft', 'What is the retry policy?', texts: [
            'tr' => ['Yeniden deneme nedir?', 'Taslak.'],
        ], trState: 'draft');

        $this->signIn();

        $this->getJson('/support/articles?search=izleme&locale=tr')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.question', 'İzleme sıklığı nedir?');
        $this->getJson('/support/articles?search=retry+policy&locale=tr')
            ->assertOk()
            ->assertJsonPath('data.0.question', 'What is the retry policy?');
    }

    public function test_the_search_and_the_locale_are_validated(): void
    {
        $this->article('alerts', 'How do alerts work?');
        $this->signIn();

        $this->getJson('/support/articles?search=a')->assertUnprocessable()->assertJsonValidationErrors('search');
        $this->getJson('/support/articles')->assertUnprocessable()->assertJsonValidationErrors('search');
        $this->getJson('/support/articles?search=' . str_repeat('a', 201))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('search');
        $this->getJson('/support/articles?search=alerts&locale=xx')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('locale');
        $this->getJson('/support/articles?search=alerts')->assertOk()->assertJsonCount(1, 'data');
    }

    #[DefineEnvironment('withArticlesDisabled')]
    public function test_the_route_does_not_exist_while_the_knowledge_base_is_off(): void
    {
        $this->article('alerts', 'How do alerts work?');
        $this->signIn();

        $this->getJson('/support/articles?search=alerts')->assertNotFound();
    }

    private function signIn(): void
    {
        $this->actingAs(ConcreteUser::create([
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'password' => 'secret',
        ]), 'sanctum');
    }

    /**
     * A live, approved English article unless told otherwise.
     *
     * @param  array<string, array{0: string, 1: string}>  $texts  Question and body of further locales.
     */
    private function article(
        string $slug,
        string $question,
        SupportArticleVisibility $visibility = SupportArticleVisibility::PUBLIC,
        mixed $publishedAt = null,
        string $body = 'Everything about it.',
        array $texts = [],
        string $trState = SupportArticle::LOCALE_APPROVED,
    ): SupportArticle {
        return SupportArticle::factory()->create([
            'slug' => $slug,
            'visibility' => $visibility,
            'published_at' => $publishedAt ?? now()->subMinute(),
            'question' => [
                'en' => $question,
            ] + array_map(static fn (array $text): string => $text[0], $texts),
            'body' => [
                'en' => $body,
            ] + array_map(static fn (array $text): string => $text[1], $texts),
            'locale_state' => [
                'en' => SupportArticle::LOCALE_APPROVED,
            ] + array_map(static fn (): string => $trState, $texts),
        ]);
    }
}
