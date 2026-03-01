<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Http\Controllers\NewsletterController;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\NewsletterSubscriber;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;

final class NewsletterControllerTest extends TestCase
{
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
        \call_user_func('config', [
            'magic-starter.features' => [Features::newsletterSubscription()],
        ]);

        \call_user_func([\call_user_func('app', 'db.schema'), 'create'], 'users', function ($table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });

        \call_user_func([\call_user_func('app', 'db.schema'), 'create'], 'newsletter_subscribers', function ($table): void {
            $table->uuid('id')->primary();
            $table->string('email')->unique();
            $table->boolean('is_active')->default(true);
            $table->string('source')->default('registration');
            $table->timestamps();
        });

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

    /** @var string */
    protected $table = 'users';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
    ];
}
