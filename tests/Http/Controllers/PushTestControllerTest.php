<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Tests\TestCase;
use FlutterSdk\MagicStarter\Traits\HasNotifications;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

/**
 * The self-addressed push test endpoint.
 *
 * THE CLAIM THIS FILE EXISTS FOR is that a signed-in caller can page their own
 * device and nobody else's. This is an on-call product: a push that reaches the
 * wrong person during an outage is worse than a push that never arrives, so the
 * addressing is not an implementation detail of the send, it IS the endpoint.
 * The recipient-shaped body test is therefore the load-bearing one; the others
 * bound what a caller can spend and what an unprovisioned deployment answers.
 *
 * Every test drives the route the PACKAGE registers rather than one the test
 * registers itself, because two of the four guards (the auth gate and the named
 * limiter) live on the route and a hand-registered path would assert nothing
 * about either.
 */
final class PushTestControllerTest extends TestCase
{
    /**
     * The OneSignal application id a provisioned deployment carries.
     */
    private const APP_ID = 'push-test-app-id';

    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::reset();

        config([
            'database.default' => 'testing',
            'database.connections.testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'magic-starter.models.user' => PushTestUser::class,
            'magic-starter.route_prefix' => '',
            'magic-starter.onesignal.app_id' => null,
            'auth.providers.users' => [
                'driver' => 'eloquent',
                'model' => PushTestUser::class,
            ],
            // The same harness shim the billing tests carry: the route file puts
            // this endpoint behind `auth:sanctum` and Sanctum's token driver is
            // not registered in a Testbench skeleton, so the guard NAME points at
            // the session driver. The middleware string stays under test;
            // Sanctum's own token resolution is not this step's subject.
            'auth.guards.sanctum' => [
                'driver' => 'session',
                'provider' => 'users',
            ],
        ]);

        app('db.schema')->create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->timestamps();
        });

        $this->bootPackageRoutes();
    }

    protected function tearDown(): void
    {
        MagicStarter::reset();

        parent::tearDown();
    }

    /**
     * An unprovisioned deployment refuses instead of throwing.
     *
     * This is the DEFAULT configuration: `magic-starter.onesignal.app_id` reads
     * an env key most adopters have never set, and `OneSignalChannel::send()`
     * throws on a null app id. Without the gate the shipped default answers 500.
     */
    public function test_it_refuses_with_409_when_push_is_not_provisioned(): void
    {
        Notification::fake();

        $user = $this->createUser('unprovisioned@example.test');

        $this->push($user)
            ->assertStatus(409)
            ->assertJsonStructure(['message']);

        Notification::assertNothingSent();
    }

    /**
     * The onesignal FEATURE being off is the same refusal, for the same reason.
     *
     * Without the feature the package never extends the channel manager, so a
     * send would die on `Driver [onesignal] not supported` rather than on the
     * app id. Both are "push is not provisioned here" and both must answer 409.
     */
    public function test_it_refuses_with_409_when_the_onesignal_feature_is_off(): void
    {
        Notification::fake();

        $this->bootPackageRoutes([Features::notifications()]);
        config(['magic-starter.onesignal.app_id' => self::APP_ID]);

        $user = $this->createUser('feature-off@example.test');

        $this->push($user)->assertStatus(409);

        Notification::assertNothingSent();
    }

    /**
     * A provisioned deployment sends exactly one notification, to the caller.
     */
    public function test_it_sends_one_notification_addressed_to_the_authenticated_caller(): void
    {
        Notification::fake();
        $this->provision();

        $user = $this->createUser('caller@example.test');

        $this->push($user, [
            'title' => 'Uptizm',
            'body' => 'Push is working on this device.',
            'data' => ['type' => 'push_test'],
        ])
            ->assertStatus(202)
            ->assertExactJson(['delivered' => true]);

        Notification::assertCount(1);

        $record = $this->onlySentRecord($user);

        $this->assertTrue($record['notifiable']->is($user));
        $this->assertSame(['onesignal'], $record['channels']);

        // The addressing the channel will actually use, read off the recorded
        // notifiable rather than assumed: `user_<key>`, the same external id the
        // Flutter client registers with OneSignal.
        $this->assertSame(
            ['external_id' => ['user_' . $user->getKey()]],
            $record['notifiable']->routeNotificationForOneSignal(),
        );

        $payload = $record['notification']->toOneSignal($user);

        $this->assertSame('Uptizm', $payload->getHeadings()->getEn());
        $this->assertSame('Push is working on this device.', $payload->getContents()->getEn());
        $this->assertSame(
            ['type' => 'push_test', 'subject' => 'user_' . $user->getKey()],
            (array) $payload->getData(),
        );
    }

    /**
     * A recipient-shaped body reaches the caller and nobody else.
     *
     * Four separate attempts in one request: a top-level `user_id`, an
     * `external_id`, a `to`, and a `subject` planted INSIDE the forwarded data
     * map. The last is the dangerous one, because `subject` is the key the
     * client's receive-side guard trusts to decide a notification is for the
     * signed-in account; a caller who could set it could make a push aimed at
     * their own device claim to be somebody else's.
     */
    public function test_a_recipient_shaped_body_still_reaches_only_the_caller(): void
    {
        Notification::fake();
        $this->provision();

        $caller = $this->createUser('caller@example.test');
        $other = $this->createUser('other@example.test');

        $this->push($caller, [
            'title' => 'Uptizm',
            'body' => 'Push is working on this device.',
            'user_id' => (string) $other->getKey(),
            'external_id' => 'user_' . $other->getKey(),
            'to' => 'user_' . $other->getKey(),
            'data' => [
                'type' => 'push_test',
                'subject' => 'user_' . $other->getKey(),
            ],
        ])->assertStatus(202);

        Notification::assertCount(1);
        Notification::assertNothingSentTo($other);

        $record = $this->onlySentRecord($caller);

        $this->assertTrue($record['notifiable']->is($caller));

        $payload = $record['notification']->toOneSignal($caller);
        $data = (array) $payload->getData();

        $this->assertSame('user_' . $caller->getKey(), $data['subject']);
        $this->assertArrayNotHasKey('user_id', $data);
        $this->assertArrayNotHasKey('external_id', $data);
        $this->assertArrayNotHasKey('to', $data);
    }

    /**
     * The named limiter refuses well before the eleventh call in a minute.
     *
     * Asserted at FIRE time and by counting the sends: a route that merely
     * carries a `throttle:` string would pass an assertion about middleware
     * names while spending an unbounded number of pushes.
     */
    public function test_the_named_limiter_refuses_the_eleventh_call_in_a_minute(): void
    {
        Notification::fake();
        $this->provision();

        $user = $this->createUser('noisy@example.test');

        $statuses = [];

        for ($call = 1; $call <= 11; $call++) {
            $statuses[] = $this->push($user)->getStatusCode();
        }

        $this->assertSame(202, $statuses[0], 'The first call must be accepted.');
        $this->assertSame(429, $statuses[10], 'The eleventh call must be refused.');

        $accepted = count(array_filter($statuses, static fn (int $status): bool => $status === 202));

        $this->assertLessThan(10, $accepted, 'The limiter must bound the burst to single digits.');
        Notification::assertCount($accepted);
    }

    /**
     * A guest may not trigger an outbound send.
     *
     * Guest auth hands a Sanctum token to anybody who asks, so for an adopter
     * with that feature on this endpoint would otherwise be anonymously
     * reachable. uptizm has guest auth off, which is exactly why the guard has
     * to be tested here rather than trusted to the deployment.
     */
    public function test_it_refuses_a_guest_user(): void
    {
        Notification::fake();
        $this->provision();

        $guest = $this->createUser('guest@example.test', ['is_guest' => true]);

        $this->push($guest)->assertForbidden();

        Notification::assertNothingSent();
    }

    /**
     * An unauthenticated caller never reaches the controller.
     */
    public function test_it_refuses_an_unauthenticated_caller(): void
    {
        Notification::fake();
        $this->provision();

        $this->postJson('/notifications/push-test', [
            'title' => 'Uptizm',
            'body' => 'Push is working on this device.',
        ])->assertUnauthorized();

        Notification::assertNothingSent();
    }

    /**
     * The body is validated before anything is sent.
     */
    public function test_it_validates_the_body(): void
    {
        Notification::fake();
        $this->provision();

        $user = $this->createUser('invalid@example.test');

        $this->push($user, ['body' => 'No title.'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title']);

        $this->push($user, ['title' => str_repeat('a', 121), 'body' => 'Too long a title.'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title']);

        $this->push($user, ['title' => 'Uptizm', 'body' => str_repeat('b', 501)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['body']);

        Notification::assertNothingSent();
    }

    /**
     * Nothing push-shaped is registered while the notifications feature is off.
     */
    public function test_no_route_exists_while_the_notifications_feature_is_off(): void
    {
        Notification::fake();
        $this->provision();
        $this->bootPackageRoutes([]);

        $user = $this->createUser('feature-off@example.test');

        $this->push($user)->assertNotFound();

        Notification::assertNothingSent();
    }

    /**
     * Give the deployment an app id, so the provisioning gate opens.
     */
    private function provision(): void
    {
        config(['magic-starter.onesignal.app_id' => self::APP_ID]);
    }

    /**
     * Re-register the package's own routes for a feature set.
     *
     * @param  array<int, string>|null  $features
     */
    private function bootPackageRoutes(?array $features = null): void
    {
        config([
            'magic-starter.features' => $features ?? [
                Features::notifications(),
                Features::onesignal(),
            ],
        ]);

        $this->app['router']->setRoutes(new RouteCollection);

        (new MagicStarterServiceProvider($this->app))->boot();
    }

    /**
     * Drive the endpoint as the given caller.
     *
     * The acting instance is re-read rather than reused, because a real request
     * resolves its own model and this harness would otherwise carry a relation
     * loaded by an earlier call in the same test.
     *
     * @param  array<string, mixed>|null  $payload
     */
    private function push(Model $user, ?array $payload = null): TestResponse
    {
        return $this->actingAs($user->fresh(), 'sanctum')->postJson(
            '/notifications/push-test',
            $payload ?? ['title' => 'Uptizm', 'body' => 'Push is working on this device.'],
        );
    }

    /**
     * Create one user.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createUser(string $email, array $attributes = []): PushTestUser
    {
        return PushTestUser::query()->create(array_merge([
            'name' => 'Push Test User',
            'email' => $email,
        ], $attributes));
    }

    /**
     * The single notification record recorded for one notifiable.
     *
     * Read out of the fake's own ledger rather than through `assertSentTo()`,
     * because the notification the controller sends is an anonymous class and
     * `assertSentTo()` keys on a class NAME.
     *
     * @return array{notification: mixed, channels: array<int, string>, notifiable: mixed, locale: string|null}
     */
    private function onlySentRecord(Model $notifiable): array
    {
        $sent = Notification::sentNotifications();

        $this->assertArrayHasKey($notifiable::class, $sent);
        $this->assertSame([(string) $notifiable->getKey()], array_keys($sent[$notifiable::class]));

        $byClass = array_values($sent[$notifiable::class][(string) $notifiable->getKey()]);

        $this->assertCount(1, $byClass, 'Exactly one notification class must have been sent.');
        $this->assertCount(1, $byClass[0], 'The notification must have been sent exactly once.');

        return $byClass[0][0];
    }
}

/**
 * A consumer's user model: notifiable, uuid-keyed, guest-capable.
 */
class PushTestUser extends Authenticatable
{
    use HasNotifications;
    use HasUuids;
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = [
        'is_guest' => 'boolean',
    ];
}
