<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Models\PushDevice;
use FlutterSdk\MagicStarter\Tests\TestCase;
use FlutterSdk\MagicStarter\Traits\HasNotifications;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/**
 * The push device report and release endpoints.
 *
 * THE CLAIM THIS FILE EXISTS FOR is that a caller can only ever write, or
 * remove, a device row of their own. The row decides whether an escalation
 * counts a person as reachable, so a report written under somebody else, or a
 * release that reaches somebody else's row, silently changes who gets paged.
 *
 * Every test drives the routes the PACKAGE registers, because the auth gate and
 * the feature gate live on the route and a hand-registered path would assert
 * nothing about either.
 */
final class PushDeviceControllerTest extends TestCase
{
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
            'magic-starter.use_uuids' => true,
            'magic-starter.models.user' => PushDeviceUser::class,
            'magic-starter.route_prefix' => '',
            'auth.providers.users' => [
                'driver' => 'eloquent',
                'model' => PushDeviceUser::class,
            ],
            // Sanctum's token driver is not registered in a Testbench skeleton,
            // so the guard NAME points at the session driver. The route's
            // `auth:sanctum` string stays under test.
            'auth.guards.sanctum' => [
                'driver' => 'session',
                'provider' => 'users',
            ],
        ]);

        app('db.schema')->create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });

        $this->artisan('migrate', [
            '--path' => __DIR__ . '/../../../database/migrations/create_push_devices_table.php',
            '--realpath' => true,
        ]);

        $this->bootPackageRoutes();
    }

    protected function tearDown(): void
    {
        MagicStarter::reset();

        parent::tearDown();
    }

    public function test_a_report_upserts_one_row_per_device_for_the_session_user(): void
    {
        $user = $this->createUser('ada@example.test');

        $this->report($user, $this->snapshot($user, reachability: 'off'))->assertNoContent();
        $this->report($user, $this->snapshot($user, reachability: 'on'))->assertNoContent();

        $device = PushDevice::query()->sole();
        $this->assertSame((string) $user->getKey(), (string) $device->user_id);
        $this->assertSame('sub-1', $device->subscription_id);
        $this->assertSame('user_' . $user->getKey(), $device->external_id);
        $this->assertSame('on', $device->reachability);
        $this->assertNotNull($device->reported_at);
    }

    public function test_a_second_device_gets_its_own_row(): void
    {
        $user = $this->createUser('ada@example.test');

        $this->report($user, $this->snapshot($user, subscriptionId: 'phone'))->assertNoContent();
        $this->report($user, $this->snapshot($user, subscriptionId: 'laptop'))->assertNoContent();

        $this->assertSame(2, PushDevice::query()->where('user_id', $user->getKey())->count());
    }

    public function test_a_blank_subscription_id_is_stored_as_null(): void
    {
        $user = $this->createUser('ada@example.test');

        $this->report($user, $this->snapshot($user, subscriptionId: '  '))->assertNoContent();

        $this->assertNull(PushDevice::query()->sole()->subscription_id);
    }

    public function test_a_body_user_id_is_ignored(): void
    {
        $caller = $this->createUser('caller@example.test');
        $victim = $this->createUser('victim@example.test');

        $this->report($caller, [
            ...$this->snapshot($caller),
            'user_id' => $victim->getKey(),
        ])->assertNoContent();

        $this->assertSame((string) $caller->getKey(), (string) PushDevice::query()->sole()->user_id);
        $this->assertFalse(PushDevice::query()->where('user_id', $victim->getKey())->exists());
    }

    public function test_a_body_naming_another_users_alias_is_refused(): void
    {
        $caller = $this->createUser('caller@example.test');
        $victim = $this->createUser('victim@example.test');

        $this->report($caller, $this->snapshot($victim))
            ->assertStatus(422)
            ->assertJsonValidationErrors('external_id');

        $this->assertSame(0, PushDevice::query()->count());
    }

    public function test_a_device_subscribed_as_nobody_is_reportable(): void
    {
        $user = $this->createUser('ada@example.test');

        $this->report($user, [
            ...$this->snapshot($user),
            'external_id' => null,
        ])->assertNoContent();

        $this->assertNull(PushDevice::query()->sole()->external_id);
    }

    public function test_a_report_validates_its_body(): void
    {
        $user = $this->createUser('ada@example.test');

        $this->report($user, [
            'reachability' => 'loud',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'external_id',
                'subscription_id',
                'reachability',
                'captured_at',
            ]);
    }

    public function test_release_removes_only_the_named_device_of_the_caller(): void
    {
        $user = $this->createUser('ada@example.test');
        $this->report($user, $this->snapshot($user, subscriptionId: 'phone'))->assertNoContent();
        $this->report($user, $this->snapshot($user, subscriptionId: 'laptop'))->assertNoContent();

        $this->release($user, 'laptop')->assertNoContent();

        $this->assertSame(
            [
                'phone',
            ],
            PushDevice::query()->pluck('subscription_id')->all(),
        );
    }

    public function test_release_of_another_users_device_is_404_and_leaves_the_row(): void
    {
        $owner = $this->createUser('owner@example.test');
        $intruder = $this->createUser('intruder@example.test');
        $this->report($owner, $this->snapshot($owner, reachability: 'on', subscriptionId: 'owner-phone'))
            ->assertNoContent();
        $before = PushDevice::query()->sole()->getAttributes();

        $this->release($intruder, 'owner-phone')->assertNotFound();

        $this->assertSame($before, PushDevice::query()->sole()->getAttributes());
        $this->assertTrue(PushDevice::canReachByPush($owner));
    }

    public function test_release_requires_a_subscription_id(): void
    {
        $user = $this->createUser('ada@example.test');

        $this->actingAs($user->fresh(), 'sanctum')
            ->postJson('/devices/push-state/release', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('subscription_id');
    }

    public function test_a_fresh_report_vouches_and_a_release_withdraws_it(): void
    {
        $user = $this->createUser('ada@example.test');

        $this->report($user, $this->snapshot($user, reachability: 'on'))->assertNoContent();
        $this->assertTrue(PushDevice::canReachByPush($user));

        $this->travel(PushDevice::FRESH_FOR_HOURS + 1)->hours();
        $this->assertFalse(PushDevice::canReachByPush($user));
        $this->travelBack();

        $this->release($user, 'sub-1')->assertNoContent();
        $this->assertFalse(PushDevice::canReachByPush($user));
    }

    public function test_an_unauthenticated_caller_is_refused(): void
    {
        $this->postJson('/devices/push-state', [])->assertUnauthorized();
        $this->postJson('/devices/push-state/release', [])->assertUnauthorized();
    }

    public function test_the_routes_carry_package_scoped_names(): void
    {
        $this->assertSame(
            'devices/push-state',
            Route::getRoutes()->getByName('magic-starter.devices.push-state')?->uri(),
        );
        $this->assertSame(
            'devices/push-state/release',
            Route::getRoutes()->getByName('magic-starter.devices.push-state.release')?->uri(),
        );
    }

    public function test_no_route_exists_while_the_onesignal_feature_is_off(): void
    {
        $this->bootPackageRoutes([
            Features::notifications(),
        ]);
        $user = $this->createUser('ada@example.test');

        $this->report($user, $this->snapshot($user))->assertNotFound();
        $this->release($user, 'sub-1')->assertNotFound();
        $this->assertNull(Route::getRoutes()->getByName('magic-starter.devices.push-state'));
        $this->assertNull(Route::getRoutes()->getByName('magic-starter.devices.push-state.release'));
    }

    /**
     * Register the package routes under the given features.
     *
     * @param  list<string>|null  $features
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

        // The name index is built lazily, so a lookup would otherwise answer
        // null for every route and the name assertions would pass vacuously.
        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    /**
     * The body `PushDeliverySnapshot.toMap()` posts for a device subscribed as [$subscribedAs].
     *
     * @return array<string, mixed>
     */
    private function snapshot(
        Model $subscribedAs,
        string $reachability = 'on',
        string $subscriptionId = 'sub-1',
    ): array {
        return [
            'external_id' => 'user_' . $subscribedAs->getKey(),
            'subscription_id' => $subscriptionId,
            'reachability' => $reachability,
            'captured_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function report(Model $user, array $payload): TestResponse
    {
        return $this->actingAs($user->fresh(), 'sanctum')->postJson('/devices/push-state', $payload);
    }

    private function release(Model $user, string $subscriptionId): TestResponse
    {
        return $this->actingAs($user->fresh(), 'sanctum')->postJson('/devices/push-state/release', [
            'subscription_id' => $subscriptionId,
        ]);
    }

    private function createUser(string $email): PushDeviceUser
    {
        return PushDeviceUser::query()->create([
            'name' => 'Push Device User',
            'email' => $email,
        ]);
    }
}

/**
 * The user this file acts as: UUID keyed, carrying the OneSignal alias trait.
 */
class PushDeviceUser extends Authenticatable
{
    use HasNotifications;
    use HasUuids;
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';
}
