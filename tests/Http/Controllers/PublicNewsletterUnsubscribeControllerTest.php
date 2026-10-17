<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Audit\Audit;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\NewsletterSubscriber;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\Attributes\DefineEnvironment;

/**
 * The public one-click unsubscribe door, opened from a mail without signing in.
 *
 * The token in the url is the whole credential. A GET only shows a page and
 * changes nothing, because mail scanners and link previews follow every link;
 * the withdrawal is a POST that needs no cookie, session or CSRF token and
 * never redirects (RFC 8058), and the token never reaches the audit trail.
 */
final class PublicNewsletterUnsubscribeControllerTest extends TestCase
{
    private const TOKEN = 'Tok_0123456789abcdefghijklmnopqrstuvwxyz-ABCD';

    private const OTHER_TOKEN = 'Other_0123456789abcdefghijklmnopqrstuvwx-EF';

    private const UNKNOWN_TOKEN = 'Unknown_0123456789abcdefghijklmnopqrstu-GH';

    /**
     * Set by a `#[DefineEnvironment]` method, which Testbench runs BEFORE
     * defineEnvironment(), so writing the config there would be overwritten.
     */
    private bool $newsletterOn = true;

    private string $routePrefix = '';

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Routes and the audit listener are registered at boot, so both features have to be on before it.
        $app['config']->set('magic-starter.features', $this->newsletterOn
            ? [
                Features::newsletterSubscription(),
                Features::audit(),
            ]
            : [
                Features::audit(),
            ]);
        $app['config']->set('magic-starter.route_prefix', $this->routePrefix);
        $app['config']->set('app.name', 'Acme Status');
    }

    protected function newsletterOff(): void
    {
        $this->newsletterOn = false;
    }

    protected function withRoutePrefix(): void
    {
        $this->routePrefix = 'api';
    }

    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::useUserModel(PublicNewsletterUnsubscribeTestUser::class);

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('locale')->nullable();
            $table->timestamps();
        });

        foreach ([
            'create_newsletter_subscribers_table',
            'add_consent_to_newsletter_subscribers_table',
            'create_magic_starter_audits_table',
        ] as $migration) {
            (require __DIR__ . "/../../../database/migrations/{$migration}.php")->up();
        }
    }

    public function test_get_with_a_known_token_shows_the_confirm_page_and_changes_nothing(): void
    {
        $subscriber = $this->subscriber(self::TOKEN);
        $before = $this->rawRow($subscriber);

        // A touched row would carry a later updated_at.
        Carbon::setTestNow(now()->addMinutes(5));

        $response = $this->get($this->url(self::TOKEN));

        $response->assertOk();
        $this->assertNotRedirect($response);
        $response->assertSee('Unsubscribe from the newsletter?');
        $response->assertSee('Acme Status');
        $response->assertSee('method="POST"', false);
        $response->assertSee('action="' . e($this->url(self::TOKEN)) . '"', false);
        $response->assertHeader('Referrer-Policy', 'no-referrer');

        // The form action is the only place the token may appear.
        $this->assertSame(1, substr_count((string) $response->getContent(), self::TOKEN));

        $this->assertSame($before, $this->rawRow($subscriber));
        $this->assertSame(0, Audit::query()->where('event', 'newsletter.unsubscribed')->count());

        $this->writeEvidence($response);
    }

    public function test_get_on_an_unsubscribed_row_shows_the_done_page_without_a_form(): void
    {
        $subscriber = $this->subscriber(self::TOKEN);
        $subscriber->unsubscribe('profile');
        $before = $this->rawRow($subscriber);

        $response = $this->get($this->url(self::TOKEN));

        $response->assertOk();
        $response->assertSee('You are unsubscribed');
        $response->assertDontSee('<form', false);
        $response->assertDontSee(self::TOKEN, false);
        $this->assertSame($before, $this->rawRow($subscriber));
    }

    public function test_get_with_an_unknown_token_is_a_404_page_that_names_nothing(): void
    {
        $this->subscriber(self::TOKEN);

        $response = $this->get($this->url(self::UNKNOWN_TOKEN));

        $response->assertNotFound();
        $this->assertNotRedirect($response);
        $response->assertSee('Link not found');
        $response->assertDontSee('<form', false);
        $response->assertDontSee(self::UNKNOWN_TOKEN, false);
    }

    public function test_get_renders_in_the_owners_locale(): void
    {
        $this->subscriber(self::TOKEN, locale: 'tr');

        $response = $this->get($this->url(self::TOKEN));

        $response->assertOk();
        $response->assertSee('Bültenden çıkmak istiyor musunuz?');
        $response->assertSee('<html lang="tr">', false);
    }

    public function test_one_click_post_without_cookies_unsubscribes_and_answers_200(): void
    {
        $subscriber = $this->subscriber(self::TOKEN);

        $response = $this->oneClick(self::TOKEN);

        $response->assertOk();
        $this->assertNotRedirect($response);
        $response->assertSee('You are unsubscribed');
        $response->assertDontSee(self::TOKEN, false);
        $this->assertSame([], $response->headers->getCookies());

        $subscriber->refresh();
        $this->assertFalse($subscriber->is_active);
        $this->assertNotNull($subscriber->unsubscribed_at);
    }

    public function test_a_second_post_answers_200_and_keeps_the_first_withdrawal_time(): void
    {
        $subscriber = $this->subscriber(self::TOKEN);

        $this->oneClick(self::TOKEN)->assertOk();
        $first = $this->rawRow($subscriber);

        Carbon::setTestNow(now()->addMinutes(5));
        $response = $this->oneClick(self::TOKEN);

        $response->assertOk();
        $this->assertNotRedirect($response);
        $response->assertSee('You are unsubscribed');
        $this->assertSame($first, $this->rawRow($subscriber));
        $this->assertSame(1, Audit::query()->where('event', 'newsletter.unsubscribed')->count());
    }

    public function test_post_accepts_an_empty_body(): void
    {
        $subscriber = $this->subscriber(self::TOKEN);

        $this->post($this->url(self::TOKEN))->assertOk();

        $this->assertNotNull($subscriber->refresh()->unsubscribed_at);
    }

    public function test_post_with_an_unknown_token_is_a_404_and_touches_no_row(): void
    {
        $subscriber = $this->subscriber(self::TOKEN);
        $before = $this->rawRow($subscriber);

        $response = $this->oneClick(self::UNKNOWN_TOKEN);

        $response->assertNotFound();
        $this->assertNotRedirect($response);
        $this->assertSame($before, $this->rawRow($subscriber));
    }

    public function test_post_writes_one_audit_row_and_no_row_carries_the_token(): void
    {
        $this->subscriber(self::TOKEN);

        $this->oneClick(self::TOKEN)->assertOk();

        $rows = Audit::query()->where('event', 'newsletter.unsubscribed')->get();
        $this->assertCount(1, $rows);

        /** @var array<string, mixed> $context */
        $context = $rows->first()?->context;
        $this->assertSame('one_click', $context['via']);
        $this->assertArrayHasKey('url', $context);
        $this->assertNull($context['url']);
        $this->assertSame('magic-starter.newsletter.unsubscribe.store', $context['route']);

        foreach (DB::table('magic_starter_audits')->get() as $row) {
            foreach (['old_values', 'new_values', 'context'] as $column) {
                $this->assertStringNotContainsString(self::TOKEN, (string) $row->{$column}, "{$row->event}.{$column}");
            }
        }
    }

    public function test_the_post_limit_is_per_token_not_per_ip(): void
    {
        $this->subscriber(self::TOKEN);
        $this->subscriber(self::OTHER_TOKEN, email: 'other@example.test');

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->oneClick(self::TOKEN)->assertOk();
        }

        $this->oneClick(self::TOKEN)->assertStatus(429);
        $this->oneClick(self::OTHER_TOKEN)->assertOk();
    }

    public function test_a_token_outside_the_route_pattern_matches_no_route(): void
    {
        $this->get('/newsletter/unsubscribe/short')->assertNotFound();
        $this->post('/newsletter/unsubscribe/has.a.dot.0123456789abcdef')->assertNotFound();
    }

    #[DefineEnvironment('newsletterOff')]
    public function test_the_routes_are_absent_with_the_newsletter_feature_off(): void
    {
        $this->assertFalse(Route::has('magic-starter.newsletter.unsubscribe'));
        $this->assertFalse(Route::has('magic-starter.newsletter.unsubscribe.store'));
    }

    #[DefineEnvironment('withRoutePrefix')]
    public function test_the_routes_ignore_the_route_prefix_and_join_no_middleware_group(): void
    {
        $show = Route::getRoutes()->getByName('magic-starter.newsletter.unsubscribe');
        $store = Route::getRoutes()->getByName('magic-starter.newsletter.unsubscribe.store');

        $this->assertNotNull($show);
        $this->assertNotNull($store);
        $this->assertSame('newsletter/unsubscribe/{token}', $show->uri());
        $this->assertSame(['throttle:magic-starter-newsletter-unsubscribe-page'], $show->gatherMiddleware());
        $this->assertSame(['throttle:magic-starter-newsletter-unsubscribe'], $store->gatherMiddleware());
    }

    private function subscriber(
        string $token,
        string $email = 'reader@example.test',
        ?string $locale = null,
    ): NewsletterSubscriber {
        $user = PublicNewsletterUnsubscribeTestUser::query()->create([
            'name' => 'Reader',
            'email' => $email,
            'locale' => $locale,
        ]);

        $subscriber = new NewsletterSubscriber([
            'user_id' => $user->getKey(),
            'email' => $email,
            'is_active' => true,
            'source' => 'registration',
            'consented_at' => now(),
            'consent_version' => '1',
            'consent_source' => 'registration',
        ]);
        $subscriber->unsubscribe_token = $token;
        $subscriber->save();

        return $subscriber;
    }

    /**
     * @return array<string, mixed>
     */
    private function rawRow(NewsletterSubscriber $subscriber): array
    {
        return (array) DB::table('newsletter_subscribers')->where('id', $subscriber->getKey())->first();
    }

    private function url(string $token): string
    {
        return route('magic-starter.newsletter.unsubscribe', ['token' => $token]);
    }

    /**
     * The request a mail provider sends for the one-click header: a form body, no cookie.
     *
     * @return TestResponse<\Illuminate\Http\Response>
     */
    private function oneClick(string $token): TestResponse
    {
        return $this->call(
            'POST',
            route('magic-starter.newsletter.unsubscribe.store', ['token' => $token]),
            ['List-Unsubscribe' => 'One-Click'],
            [],
            [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
        );
    }

    /**
     * @param  TestResponse<\Illuminate\Http\Response>  $response
     */
    private function assertNotRedirect(TestResponse $response): void
    {
        $this->assertFalse($response->isRedirect(), 'An unsubscribe response must never redirect.');
    }

    /**
     * QA evidence: `NEWSLETTER_PAGE_EVIDENCE=<file>` writes the rendered confirm page there.
     *
     * @param  TestResponse<\Illuminate\Http\Response>  $response
     */
    private function writeEvidence(TestResponse $response): void
    {
        $path = getenv('NEWSLETTER_PAGE_EVIDENCE');

        if (! is_string($path) || $path === '') {
            return;
        }

        file_put_contents($path, (string) $response->getContent());
    }
}

final class PublicNewsletterUnsubscribeTestUser extends Authenticatable implements HasLocalePreference
{
    use HasUuids;
    use Notifiable;

    /** @var string */
    protected $table = 'users';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'locale',
    ];

    public function preferredLocale(): ?string
    {
        return $this->locale;
    }
}
