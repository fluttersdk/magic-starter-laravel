<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Audit\Audit;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Http\Controllers\NewsletterController;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\NewsletterSubscriber;
use FlutterSdk\MagicStarter\Notifications\NewsletterSubscribedNotification;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Notification;

final class NewsletterControllerTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The audit listener is registered at boot, so the feature has to be on before it.
        $app['config']->set('magic-starter.features', [
            Features::newsletterSubscription(),
            Features::audit(),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::reset();

        \call_user_func('config', ['database.default' => 'testing']);
        \call_user_func('config', ['database.connections.testing' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);
        \call_user_func('config', [
            'magic-starter.models.user' => NewsletterControllerTestUser::class,
        ]);

        \call_user_func([\call_user_func('app', 'db.schema'), 'create'], 'users', function ($table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });

        foreach ([
            'create_newsletter_subscribers_table',
            'add_consent_to_newsletter_subscribers_table',
            'create_magic_starter_audits_table',
        ] as $migration) {
            (require __DIR__ . "/../../../database/migrations/{$migration}.php")->up();
        }

        $router = \call_user_func('app', 'router');
        $router->get('/user/newsletter', [NewsletterController::class, 'show'])->middleware('auth');
        $router->put('/user/newsletter', [NewsletterController::class, 'update'])->middleware('auth');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // show() tests
    // ─────────────────────────────────────────────────────────────────────────

    public function test_show_returns_subscribed_true_when_active(): void
    {
        $user = NewsletterControllerTestUser::query()->create([
            'name' => 'Newsletter User',
            'email' => 'newsletter@example.test',
        ]);

        NewsletterSubscriber::query()->create([
            'email' => 'newsletter@example.test',
            'is_active' => true,
            'source' => 'registration',
        ]);

        $response = $this->actingAs($user)->getJson('/user/newsletter');

        $response->assertStatus(200);
        $response->assertJsonFragment(['subscribed' => true]);
        $response->assertJsonFragment(['source' => 'registration']);
        $this->assertArrayHasKey('subscribed_at', $response->json());
    }

    public function test_show_returns_subscribed_false_when_inactive(): void
    {
        $user = NewsletterControllerTestUser::query()->create([
            'name' => 'Newsletter User',
            'email' => 'inactive@example.test',
        ]);

        NewsletterSubscriber::query()->create([
            'email' => 'inactive@example.test',
            'is_active' => false,
            'source' => 'profile',
        ]);

        $response = $this->actingAs($user)->getJson('/user/newsletter');

        $response->assertStatus(200);
        $response->assertJsonFragment(['subscribed' => false]);
    }

    public function test_show_returns_subscribed_false_when_no_record(): void
    {
        $user = NewsletterControllerTestUser::query()->create([
            'name' => 'No Sub User',
            'email' => 'nosub@example.test',
        ]);

        $response = $this->actingAs($user)->getJson('/user/newsletter');

        $response->assertStatus(200);
        $response->assertExactJson(['subscribed' => false]);
    }

    public function test_show_requires_auth(): void
    {
        $response = $this->getJson('/user/newsletter');

        $response->assertStatus(401);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // update() tests
    // ─────────────────────────────────────────────────────────────────────────

    public function test_update_subscribes_user(): void
    {
        Notification::fake();

        $user = NewsletterControllerTestUser::query()->create([
            'name' => 'Subscribe User',
            'email' => 'subscribe@example.test',
        ]);

        $response = $this->actingAs($user)->putJson('/user/newsletter', [
            'subscribe' => true,
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['subscribed' => true]);

        $this->assertDatabaseHas('newsletter_subscribers', [
            'email' => 'subscribe@example.test',
            'is_active' => 1,
        ]);
    }

    public function test_update_unsubscribes_user(): void
    {
        Notification::fake();

        $user = NewsletterControllerTestUser::query()->create([
            'name' => 'Unsubscribe User',
            'email' => 'unsub@example.test',
        ]);

        NewsletterSubscriber::query()->create([
            'email' => 'unsub@example.test',
            'is_active' => true,
            'source' => 'registration',
        ]);

        $response = $this->actingAs($user)->putJson('/user/newsletter', [
            'subscribe' => false,
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['subscribed' => false]);

        $this->assertDatabaseHas('newsletter_subscribers', [
            'email' => 'unsub@example.test',
            'is_active' => 0,
        ]);
    }

    public function test_update_creates_record_if_not_exists(): void
    {
        Notification::fake();

        $user = NewsletterControllerTestUser::query()->create([
            'name' => 'New Sub User',
            'email' => 'newsub@example.test',
        ]);

        $response = $this->actingAs($user)->putJson('/user/newsletter', [
            'subscribe' => true,
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('newsletter_subscribers', [
            'email' => 'newsub@example.test',
            'source' => 'profile',
            'is_active' => 1,
        ]);
    }

    public function test_update_requires_auth(): void
    {
        $response = $this->putJson('/user/newsletter', [
            'subscribe' => true,
        ]);

        $response->assertStatus(401);
    }

    public function test_update_returns_400_when_no_email(): void
    {
        Notification::fake();

        $user = NewsletterControllerTestUser::query()->create([
            'name' => 'No Email User',
            'email' => null,
        ]);

        $response = $this->actingAs($user)->putJson('/user/newsletter', [
            'subscribe' => true,
        ]);

        $response->assertStatus(400);
        $response->assertJsonFragment([
            'message' => 'Email address required for newsletter subscription.',
        ]);
        Notification::assertNothingSent();
    }

    public function test_update_validates_subscribe_field(): void
    {
        $user = NewsletterControllerTestUser::query()->create([
            'name' => 'Validate User',
            'email' => 'validate@example.test',
        ]);

        $response = $this->actingAs($user)->putJson('/user/newsletter', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['subscribe']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Consent record
    // ─────────────────────────────────────────────────────────────────────────

    public function test_update_subscribe_records_owner_and_consent_and_sends_the_confirmation_once(): void
    {
        Notification::fake();
        $this->travelTo('2026-03-04 10:00:00');
        $user = $this->makeUser('consent@example.test');

        $this->actingAs($user)->putJson('/user/newsletter', ['subscribe' => true])->assertOk();

        $row = NewsletterSubscriber::query()->sole();
        $this->assertSame($user->getKey(), $row->user_id);
        $this->assertSame('consent@example.test', $row->email);
        $this->assertTrue($row->is_active);
        $this->assertNull($row->unsubscribed_at);
        $this->assertSame('2026-03-04 10:00:00', $row->consented_at?->toDateTimeString());
        $this->assertSame('1', $row->consent_version);
        $this->assertSame('profile', $row->consent_source);
        $this->assertSame('profile', $row->source);
        $this->assertNotEmpty($row->unsubscribe_token);
        Notification::assertSentToTimes($user, NewsletterSubscribedNotification::class, 1);
    }

    public function test_the_consent_version_comes_from_config(): void
    {
        Notification::fake();
        config()->set('magic-starter.newsletter.consent_version', '2026-05');
        $user = $this->makeUser('versioned@example.test');

        $this->actingAs($user)->putJson('/user/newsletter', ['subscribe' => true])->assertOk();

        $this->assertSame('2026-05', NewsletterSubscriber::query()->sole()->consent_version);
    }

    public function test_the_default_consent_version_is_one(): void
    {
        $this->assertSame('1', config('magic-starter.newsletter.consent_version'));
    }

    public function test_subscribing_while_already_subscribed_sends_nothing_more(): void
    {
        Notification::fake();
        $user = $this->makeUser('twice@example.test');

        $this->actingAs($user)->putJson('/user/newsletter', ['subscribe' => true])->assertOk();
        $this->actingAs($user)->putJson('/user/newsletter', ['subscribe' => true])->assertOk();

        $this->assertSame(1, NewsletterSubscriber::query()->count());
        Notification::assertSentToTimes($user, NewsletterSubscribedNotification::class, 1);
    }

    public function test_resubscribing_keeps_the_token_and_rewrites_the_consent(): void
    {
        Notification::fake();
        $this->travelTo('2026-03-04 10:00:00');
        $user = $this->makeUser('back@example.test');

        $this->actingAs($user)->putJson('/user/newsletter', ['subscribe' => true])->assertOk();
        $token = NewsletterSubscriber::query()->sole()->unsubscribe_token;

        $this->travelTo('2026-03-05 10:00:00');
        $this->actingAs($user)->putJson('/user/newsletter', ['subscribe' => false])->assertOk();

        $this->travelTo('2026-03-06 10:00:00');
        config()->set('magic-starter.newsletter.consent_version', '2');
        $this->actingAs($user)->putJson('/user/newsletter', ['subscribe' => true])->assertOk();

        $row = NewsletterSubscriber::query()->sole();
        $this->assertSame($token, $row->unsubscribe_token);
        $this->assertTrue($row->is_active);
        $this->assertNull($row->unsubscribed_at);
        $this->assertSame('2026-03-06 10:00:00', $row->consented_at?->toDateTimeString());
        $this->assertSame('2', $row->consent_version);
        Notification::assertSentToTimes($user, NewsletterSubscribedNotification::class, 2);
    }

    public function test_a_numeric_or_string_flag_means_what_it_says(): void
    {
        Notification::fake();
        $user = $this->makeUser('numeric@example.test');

        $this->actingAs($user)->putJson('/user/newsletter', ['subscribe' => 1])
            ->assertOk()
            ->assertJsonFragment(['subscribed' => true]);

        $this->actingAs($user)->putJson('/user/newsletter', ['subscribe' => '0'])
            ->assertOk()
            ->assertJsonFragment(['subscribed' => false]);

        $this->assertFalse(NewsletterSubscriber::query()->sole()->is_active);
    }

    public function test_a_row_the_previous_release_reactivated_can_be_withdrawn_again(): void
    {
        Notification::fake();
        $user = $this->makeUser('again@example.test');
        $this->actingAs($user)->putJson('/user/newsletter', ['subscribe' => true])->assertOk();

        // The previous release flips is_active back on and knows nothing of unsubscribed_at.
        NewsletterSubscriber::query()->update([
            'is_active' => true,
            'unsubscribed_at' => '2026-03-01 10:00:00',
        ]);

        $this->actingAs($user)->putJson('/user/newsletter', ['subscribe' => false])
            ->assertOk()
            ->assertJsonFragment(['subscribed' => false]);

        $this->assertFalse(NewsletterSubscriber::query()->sole()->is_active);
    }

    public function test_unsubscribing_records_the_withdrawal_and_is_idempotent(): void
    {
        Notification::fake();
        $this->travelTo('2026-03-04 10:00:00');
        $user = $this->makeUser('leave@example.test');
        $this->actingAs($user)->putJson('/user/newsletter', ['subscribe' => true])->assertOk();

        $this->travelTo('2026-03-05 10:00:00');
        $this->actingAs($user)->putJson('/user/newsletter', ['subscribe' => false])
            ->assertOk()
            ->assertJsonFragment(['subscribed' => false]);

        $this->travelTo('2026-03-06 10:00:00');
        $this->actingAs($user)->putJson('/user/newsletter', ['subscribe' => false])->assertOk();

        $row = NewsletterSubscriber::query()->sole();
        $this->assertFalse($row->is_active);
        $this->assertSame('2026-03-05 10:00:00', $row->unsubscribed_at?->toDateTimeString());
        $this->assertSame(1, Audit::query()->where('event', 'newsletter.unsubscribed')->count());
    }

    public function test_unsubscribing_without_a_row_changes_nothing(): void
    {
        $user = $this->makeUser('never@example.test');

        $this->actingAs($user)->putJson('/user/newsletter', ['subscribe' => false])
            ->assertOk()
            ->assertExactJson(['subscribed' => false]);

        $this->assertSame(0, NewsletterSubscriber::query()->count());
    }

    public function test_a_row_written_by_address_alone_is_claimed_and_consent_is_recorded_on_subscribe(): void
    {
        Notification::fake();
        $user = $this->makeUser('legacy@example.test');
        NewsletterSubscriber::query()->create([
            'email' => 'Legacy@Example.test',
            'is_active' => true,
            'source' => 'registration',
        ]);
        $token = NewsletterSubscriber::query()->sole()->unsubscribe_token;

        $this->actingAs($user)->putJson('/user/newsletter', ['subscribe' => true])->assertOk();

        $row = NewsletterSubscriber::query()->sole();
        $this->assertSame($user->getKey(), $row->user_id);
        $this->assertSame($token, $row->unsubscribe_token);
        $this->assertNotNull($row->consented_at);
        $this->assertSame('profile', $row->consent_source);
        Notification::assertSentToTimes($user, NewsletterSubscribedNotification::class, 1);
    }

    public function test_show_reports_the_consent_date_as_subscribed_at(): void
    {
        Notification::fake();
        $user = $this->makeUser('date@example.test');
        $this->travelTo('2026-03-04 10:00:00');
        NewsletterSubscriber::subscribeUser($user, 'profile');
        $this->travelTo('2026-04-01 10:00:00');

        $response = $this->actingAs($user)->getJson('/user/newsletter');

        $response->assertOk();
        $this->assertSame(['subscribed', 'source', 'subscribed_at'], array_keys($response->json()));
        $this->assertSame('2026-03-04', substr((string) $response->json('subscribed_at'), 0, 10));
    }

    public function test_show_falls_back_to_the_creation_date_without_a_consent_date(): void
    {
        $user = $this->makeUser('old@example.test');
        $this->travelTo('2026-02-01 10:00:00');
        NewsletterSubscriber::query()->create([
            'email' => 'old@example.test',
            'is_active' => true,
            'source' => 'registration',
        ]);

        $response = $this->actingAs($user)->getJson('/user/newsletter');

        $this->assertSame('2026-02-01', substr((string) $response->json('subscribed_at'), 0, 10));
    }

    public function test_update_responds_with_the_same_keys_as_show(): void
    {
        Notification::fake();
        $user = $this->makeUser('shape@example.test');

        $response = $this->actingAs($user)->putJson('/user/newsletter', ['subscribe' => true]);

        $this->assertSame(['subscribed', 'source', 'subscribed_at'], array_keys($response->json()));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Unsubscribe audit
    // ─────────────────────────────────────────────────────────────────────────

    public function test_unsubscribe_writes_exactly_one_audit_row_and_none_from_the_model_listener(): void
    {
        Notification::fake();
        $user = $this->makeUser('audit@example.test');
        $row = NewsletterSubscriber::subscribeUser($user, 'profile');
        $updatedBefore = Audit::query()->where('event', 'updated')->count();

        $row->unsubscribe('profile');

        $audits = Audit::query()->where('event', 'newsletter.unsubscribed')->get();
        $this->assertCount(1, $audits);
        $this->assertSame('profile', $audits->first()?->context['via']);
        $this->assertSame((string) $row->getKey(), $audits->first()?->auditable_id);
        $this->assertSame($updatedBefore, Audit::query()->where('event', 'updated')->count());
    }

    public function test_unsubscribing_twice_writes_one_audit_row(): void
    {
        Notification::fake();
        $row = NewsletterSubscriber::subscribeUser($this->makeUser('twice-audit@example.test'), 'profile');

        $row->unsubscribe('profile');
        $row->unsubscribe('profile');

        $this->assertSame(1, Audit::query()->where('event', 'newsletter.unsubscribed')->count());
    }

    public function test_the_audit_context_overrides_the_request_context_and_never_holds_the_token(): void
    {
        Notification::fake();
        $row = NewsletterSubscriber::subscribeUser($this->makeUser('url@example.test'), 'profile');
        $token = $row->unsubscribe_token;

        $row->unsubscribe('link', ['url' => null]);

        $audit = Audit::query()->where('event', 'newsletter.unsubscribed')->sole();
        $this->assertSame('link', $audit->context['via']);
        $this->assertArrayHasKey('url', $audit->context);
        $this->assertNull($audit->context['url']);
        $this->assertStringNotContainsString((string) $token, (string) json_encode(Audit::query()->get()->toArray()));
    }

    public function test_subscribing_is_audited_with_the_consent_version_and_source(): void
    {
        Notification::fake();
        $row = NewsletterSubscriber::subscribeUser($this->makeUser('subscribed@example.test'), 'profile');

        $audit = Audit::query()->where('event', 'newsletter.subscribed')->sole();

        $this->assertSame((string) $row->getKey(), $audit->auditable_id);
        $this->assertSame('1', $audit->context['consent_version']);
        $this->assertSame('profile', $audit->context['source']);
    }

    private function makeUser(string $email): NewsletterControllerTestUser
    {
        return NewsletterControllerTestUser::query()->create([
            'name' => 'Newsletter User',
            'email' => $email,
        ]);
    }

    public function test_routes_not_registered_when_feature_disabled(): void
    {
        \call_user_func('config', ['magic-starter.features' => []]);

        $this->assertFalse(Features::hasNewsletterSubscriptionFeatures());
    }
}

/**
 * Inline test user for NewsletterControllerTest.
 *
 * Uses UUID primary keys and provides email column.
 */
final class NewsletterControllerTestUser extends Authenticatable
{
    use HasUuids;
    use Notifiable;

    /** @var string */
    protected $table = 'users';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
    ];
}
