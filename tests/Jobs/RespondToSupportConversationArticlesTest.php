<?php

namespace FlutterSdk\MagicStarter\Tests\Jobs;

use Closure;
use FlutterSdk\MagicStarter\Contracts\ResolvesSupportArticlePlaceholders;
use FlutterSdk\MagicStarter\Contracts\RespondsToSupport;
use FlutterSdk\MagicStarter\Enums\SupportAiPool;
use FlutterSdk\MagicStarter\Enums\SupportArticleVisibility;
use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Enums\SupportConversationStatus;
use FlutterSdk\MagicStarter\Enums\SupportMessageKind;
use FlutterSdk\MagicStarter\Enums\SupportSenderType;
use FlutterSdk\MagicStarter\Events\SupportConversationNeedsStaff;
use FlutterSdk\MagicStarter\Events\SupportConversationUpdated;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Http\Resources\SupportMessageResource;
use FlutterSdk\MagicStarter\Jobs\RespondToSupportConversation;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Models\SupportArticle;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Models\SupportMessage;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportAnswer;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportTurn;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportUsage;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\SanctumServiceProvider;

/**
 * The knowledge-base cards an AI answer carries.
 *
 * A responder names articles by id, and the model that picked them is not
 * trusted: the job re-checks every id against the table as a customer's public
 * page would read it, in the turn's language, before the answer is stored, so a
 * staff-only, AI-only, unpublished, draft or unknown id never reaches a
 * customer, and the API shows the stored cards and nothing else of `meta`.
 */
class RespondToSupportConversationArticlesTest extends TestCase
{
    private const FAQ_URL = 'https://example.test/faq';

    private const FAQ_URL_TR = 'https://example.test/tr/faq';

    private const DRAFT = 'draft';

    /**
     * @param  \Illuminate\Foundation\Application  $app
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
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('magic-starter.features', [
            Features::support(),
        ]);
        $app['config']->set('magic-starter.defaults.locale', 'en');
        $app['config']->set('magic-starter.support.articles.enabled', true);
        $app['config']->set('magic-starter.support.articles.public_url', self::FAQ_URL);
        $app['config']->set('auth.providers.users.model', ConcreteUser::class);
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
            $table->string('locale')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->timestamps();
        });

        foreach ([
            'create_support_conversations_table.php',
            'create_support_messages_table.php',
            'add_ai_columns_to_support_tables.php',
            'create_support_message_attachments_table.php',
            'add_ai_summary_to_support_conversations_table.php',
            'create_support_articles_table.php',
        ] as $file) {
            (require __DIR__ . '/../../database/migrations/' . $file)->up();
        }

        Notification::fake();
        Event::fake([
            SupportConversationNeedsStaff::class,
            SupportConversationUpdated::class,
        ]);
    }

    protected function tearDown(): void
    {
        MagicStarter::reset();

        parent::tearDown();
    }

    public function test_only_the_public_ref_of_a_staff_only_and_an_unknown_one_is_stored(): void
    {
        $public = $this->article('how-to-pay', 'How do I pay?');
        $staffOnly = $this->article('internal-refunds', 'Refund runbook', [
            'visibility' => SupportArticleVisibility::STAFF_ONLY,
        ]);

        $message = $this->turnWithRefs([$public->getKey(), $staffOnly->getKey(), 999999]);

        $this->assertSame([
            [
                'id' => $public->getKey(),
                'slug' => 'how-to-pay',
                'question' => 'How do I pay?',
                'locale' => 'en',
            ],
        ], $message->meta['articles']);
    }

    public function test_an_ai_only_a_future_dated_and_a_draft_ref_never_reach_the_customer(): void
    {
        $aiOnly = $this->article('prompt-notes', 'Prompt notes', [
            'visibility' => SupportArticleVisibility::AI_ONLY,
        ]);
        $future = $this->article('coming-soon', 'Coming soon', [
            'published_at' => now()->addDay(),
        ]);
        $unpublished = $this->article('unpublished', 'Not yet', [
            'published_at' => null,
        ]);
        $draft = $this->article('draft-only', 'A draft', [
            'locale_state' => [
                'en' => self::DRAFT,
            ],
        ]);

        $message = $this->turnWithRefs([
            $aiOnly->getKey(),
            $future->getKey(),
            $unpublished->getKey(),
            $draft->getKey(),
        ]);

        $this->assertSame([], $message->meta['articles']);
    }

    public function test_a_ref_draft_in_the_turn_language_is_read_in_the_default_language_or_dropped(): void
    {
        $fallback = $this->localised('english-only', 'English only', 'Taslak', 'tr', self::DRAFT);
        $neither = $this->localised(
            'draft-everywhere',
            'Draft everywhere',
            'Her yerde taslak',
            'tr',
            self::DRAFT,
            self::DRAFT,
        );
        $translated = $this->localised('translated', 'Translated', 'Cevrilmis', 'tr', SupportArticle::LOCALE_APPROVED);

        $message = $this->turnWithRefs(
            [$fallback->getKey(), $neither->getKey(), $translated->getKey()],
            locale: 'tr',
        );

        $this->assertSame(
            [
                ['english-only', 'English only'],
                ['translated', 'Cevrilmis'],
            ],
            array_map(
                static fn (array $card): array => [$card['slug'], $card['question']],
                $message->meta['articles'],
            ),
        );
    }

    public function test_at_most_three_distinct_refs_are_kept_in_the_responders_order(): void
    {
        $articles = array_map(
            fn (int $n): SupportArticle => $this->article("article-{$n}", "Question {$n}"),
            [1, 2, 3, 4],
        );

        $message = $this->turnWithRefs([
            $articles[3]->getKey(),
            $articles[3]->getKey(),
            $articles[0]->getKey(),
            $articles[2]->getKey(),
            $articles[1]->getKey(),
        ]);

        $this->assertSame(
            ['article-4', 'article-1', 'article-3'],
            array_column($message->meta['articles'], 'slug'),
        );
    }

    public function test_a_cards_question_carries_the_hosts_placeholder_values(): void
    {
        $this->app->instance(ResolvesSupportArticlePlaceholders::class, new class implements ResolvesSupportArticlePlaceholders
        {
            public function resolve(string $markdown, string $locale): string
            {
                return str_replace('[[faq.region_count]]', 'five', $markdown);
            }
        });
        $public = $this->article('regions', 'Why [[faq.region_count]] regions?');

        $message = $this->turnWithRefs([$public->getKey()]);

        $this->assertSame('Why five regions?', $message->meta['articles'][0]['question']);
    }

    public function test_refs_are_ignored_while_the_knowledge_base_is_off(): void
    {
        config()->set('magic-starter.support.articles.enabled', false);
        $public = $this->article('how-to-pay', 'How do I pay?');

        $message = $this->turnWithRefs([$public->getKey()]);

        $this->assertSame([], $message->meta['articles']);
    }

    public function test_the_api_shows_the_cards_of_an_ai_message_and_no_other_meta(): void
    {
        $public = $this->article('how-to-pay', 'How do I pay?');
        $message = $this->turnWithRefs([$public->getKey()]);

        $payload = SupportMessageResource::make($message)->resolve();

        $this->assertSame([
            [
                'slug' => 'how-to-pay',
                'question' => 'How do I pay?',
                'url' => self::FAQ_URL . '#how-to-pay',
            ],
        ], $payload['articles']);
        $this->assertArrayNotHasKey('meta', $payload);
        $this->assertStringNotContainsString('prompt_tokens', json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('confidence', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_a_card_has_no_url_while_no_public_page_is_configured(): void
    {
        config()->set('magic-starter.support.articles.public_url', null);
        $public = $this->article('how-to-pay', 'How do I pay?');
        $message = $this->turnWithRefs([$public->getKey()]);

        $payload = SupportMessageResource::make($message)->resolve();

        $this->assertNull($payload['articles'][0]['url']);
    }

    public function test_a_card_links_the_public_page_of_the_language_it_was_read_in(): void
    {
        config()->set('magic-starter.support.articles.public_url', [
            'en' => self::FAQ_URL,
            'tr' => self::FAQ_URL_TR,
        ]);
        $translated = $this->localised('translated', 'Translated', 'Cevrilmis', 'tr', SupportArticle::LOCALE_APPROVED);
        $fallback = $this->localised('english-only', 'English only', 'Taslak', 'tr', self::DRAFT);

        $message = $this->turnWithRefs([$translated->getKey(), $fallback->getKey()], locale: 'tr');

        $this->assertSame(['tr', 'en'], array_column($message->meta['articles'], 'locale'));
        $this->assertSame(
            [
                self::FAQ_URL_TR . '#translated',
                self::FAQ_URL . '#english-only',
            ],
            array_column(SupportMessageResource::make($message)->resolve()['articles'], 'url'),
        );
    }

    public function test_a_card_stored_before_cards_carried_a_locale_links_the_default_page(): void
    {
        config()->set('magic-starter.support.articles.public_url', [
            'en' => self::FAQ_URL,
            'tr' => self::FAQ_URL_TR,
        ]);
        $message = $this->turnWithRefs([]);
        $message->forceFill([
            'meta' => [
                'articles' => [
                    [
                        'id' => 'x',
                        'slug' => 'old-card',
                        'question' => 'Old card',
                    ],
                ],
            ],
        ]);

        $this->assertSame(
            self::FAQ_URL . '#old-card',
            SupportMessageResource::make($message)->resolve()['articles'][0]['url'],
        );
    }

    public function test_only_an_ai_message_carries_the_articles_key(): void
    {
        $customer = $this->customerMessage($this->pendingConversation()[0], 'Hello.');
        $customer->forceFill([
            'meta' => [
                'articles' => [
                    [
                        'id' => 'x',
                        'slug' => 'forged',
                        'question' => 'Forged',
                    ],
                ],
            ],
        ]);

        $payload = SupportMessageResource::make($customer)->resolve();

        $this->assertArrayNotHasKey('articles', $payload);
    }

    /**
     * Run one turn whose responder answers with `$refs` and return the stored AI message.
     *
     * @param  list<int|string>  $refs
     */
    private function turnWithRefs(array $refs, ?string $locale = null): SupportMessage
    {
        [$conversation, $trigger] = $this->pendingConversation($locale);
        $this->bindResponder(static fn (SupportTurn $turn): SupportAnswer => new SupportAnswer(
            'The payment page has the steps.',
            0.9,
            new SupportUsage(300, 100),
            articleRefs: $refs,
        ));

        $job = new RespondToSupportConversation($conversation->getKey(), $trigger->getKey());
        $job->withFakeQueueInteractions();
        $this->app->call([$job, 'handle']);

        return SupportMessage::query()
            ->where('sender_type', SupportSenderType::AI->value)
            ->sole();
    }

    /**
     * A public, live article approved in English; `$attributes` overrides what a test is about.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function article(string $slug, string $question, array $attributes = []): SupportArticle
    {
        return SupportArticle::factory()->create([
            'slug' => $slug,
            'question' => [
                'en' => $question,
            ],
            'body' => [
                'en' => $question,
            ],
            ...$attributes,
        ]);
    }

    /**
     * An article written in English and one more locale, each with its own state.
     */
    private function localised(
        string $slug,
        string $english,
        string $other,
        string $locale,
        string $otherState,
        string $englishState = SupportArticle::LOCALE_APPROVED,
    ): SupportArticle {
        return $this->article($slug, $english, [
            'question' => [
                'en' => $english,
                $locale => $other,
            ],
            'body' => [
                'en' => $english,
                $locale => $other,
            ],
            'locale_state' => [
                'en' => $englishState,
                $locale => $otherState,
            ],
        ]);
    }

    /**
     * @return array{SupportConversation, SupportMessage}
     */
    private function pendingConversation(?string $locale = null): array
    {
        $customer = ConcreteUser::create([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'secret',
            'email_verified_at' => now(),
            'locale' => $locale,
        ]);
        $conversation = SupportConversation::create([
            'user_id' => $customer->getKey(),
            'email' => 'ada@example.com',
            'subject' => 'Paying',
            'source' => SupportConversationSource::IN_APP,
            'status' => SupportConversationStatus::PENDING,
        ]);
        $trigger = $this->customerMessage($conversation, 'How do I pay for my plan?');
        $conversation->forceFill([
            'last_customer_message_id' => $trigger->getKey(),
        ])->save();

        return [
            $conversation,
            $trigger,
        ];
    }

    private function customerMessage(SupportConversation $conversation, string $body): SupportMessage
    {
        return $conversation->messages()->create([
            'sender_type' => SupportSenderType::CUSTOMER,
            'kind' => SupportMessageKind::MESSAGE,
            'body' => $body,
        ]);
    }

    private function bindResponder(Closure $respond): void
    {
        $this->app->instance(RespondsToSupport::class, new class($respond) implements RespondsToSupport
        {
            public function __construct(
                private readonly Closure $respond,
            ) {}

            public function respond(SupportTurn $turn): SupportAnswer
            {
                return ($this->respond)($turn);
            }

            public function pool(SupportConversation $conversation): SupportAiPool
            {
                return SupportAiPool::FREE;
            }
        });
    }
}
