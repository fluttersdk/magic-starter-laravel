<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Events\GuestClaimed;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Integration tests for claiming a guest session into an existing account.
 *
 * The case is the one guest conversion cannot serve: the person already has an
 * account, so the guest row and the account row are two different users and
 * everything the guest accumulated is scoped to a user id nobody will
 * authenticate as again.
 */
class GuestClaimTest extends TestCase
{
    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            \Laravel\Sanctum\SanctumServiceProvider::class,
            \FlutterSdk\MagicStarter\MagicStarterServiceProvider::class,
        ];
    }

    /**
     * Configure the features and the user model BEFORE the providers boot.
     *
     * The claim route is registered behind the guest-auth feature gate in the
     * package's own route file, and that file is loaded from the provider's
     * `boot()`. A `config()` call from `setUp()` lands after that, so the gate
     * would read false and the route under test would not exist at all.
     *
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('magic-starter.features', ['guest-auth', 'notifications']);
        $app['config']->set('auth.providers.users.model', GuestClaimTestUser::class);
        $app['config']->set('magic-starter.models.user', GuestClaimTestUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        MagicStarter::reset();

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('email')->unique()->nullable();
            $table->string('password')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->string('device_id', 255)->unique()->nullable();
            $table->string('phone')->unique()->nullable();
            $table->char('phone_country', 2)->nullable();
            $table->string('locale')->default('en');
            $table->string('timezone')->default('UTC');
            $table->string('profile_photo_path', 2048)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuidMorphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });

        // A table for an authenticatable the application issues tokens to and
        // that is NOT the configured user model.
        Schema::create('devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->boolean('is_guest')->default(false);
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->uuidMorphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        MagicStarter::reset();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Start a guest session through the package's own endpoint.
     *
     * @param  string  $deviceId  The device identifier the guest is created for.
     * @return array{token: string, user_id: string} The guest token and user id.
     */
    private function startGuestSession(string $deviceId = 'claim-device-001'): array
    {
        $response = $this->postJson('/auth/guest', [
            'device_id' => $deviceId,
        ]);

        $response->assertSuccessful();

        return [
            'token' => (string) $response->json('data.token'),
            'user_id' => (string) $response->json('data.user.id'),
        ];
    }

    /**
     * Create a registered account and issue it a token.
     *
     * @param  string  $email  The account's email address.
     * @return array{token: string, user: GuestClaimTestUser} The token and the account.
     */
    private function registeredAccount(string $email = 'target@example.com'): array
    {
        /** @var GuestClaimTestUser $user */
        $user = GuestClaimTestUser::create([
            'name' => 'Target',
            'email' => $email,
            'password' => Hash::make('Password123!'),
            'is_guest' => false,
        ]);

        return [
            'token' => (string) $user->createToken('auth_token')->plainTextToken,
            'user' => $user,
        ];
    }

    /**
     * Give a user one database notification row.
     *
     * @param  string  $userId  The notifiable user id.
     * @return string The notification id.
     */
    private function notificationFor(string $userId): string
    {
        $id = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id' => $id,
            'type' => 'Tests\\Notifications\\Welcome',
            'notifiable_type' => GuestClaimTestUser::class,
            'notifiable_id' => $userId,
            'data' => json_encode(['body' => 'Welcome']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * Read the owner of a notification row.
     *
     * @param  string  $notificationId  The notification id.
     * @return string|null The notifiable user id, or null when the row is gone.
     */
    private function notificationOwner(string $notificationId): ?string
    {
        $owner = DB::table('notifications')->where('id', $notificationId)->value('notifiable_id');

        return $owner === null ? null : (string) $owner;
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    /**
     * Test 1: a claim moves the package's own rows and fires the event once.
     */
    public function test_a_claim_moves_package_owned_rows_and_fires_the_event_once(): void
    {
        $guest = $this->startGuestSession();
        $notificationId = $this->notificationFor($guest['user_id']);
        $account = $this->registeredAccount();

        Event::fake([GuestClaimed::class]);

        $this->withToken($account['token'])
            ->postJson('/auth/guest/claim', ['guest_token' => $guest['token']])
            ->assertOk()
            ->assertJsonPath('data.claimed', true)
            ->assertJsonPath('data.user.id', $account['user']->getKey());

        $this->assertSame(
            $account['user']->getKey(),
            $this->notificationOwner($notificationId),
            'The guest notification must now belong to the target account.',
        );

        Event::assertDispatchedTimes(GuestClaimed::class, 1);
        Event::assertDispatched(GuestClaimed::class, function (GuestClaimed $event) use ($guest, $account): bool {
            return $event->guest->getAuthIdentifier() === $guest['user_id']
                && $event->target->getAuthIdentifier() === $account['user']->getKey();
        });
    }

    /**
     * Test 2: a second identical call is a no-op success rather than an error.
     */
    public function test_a_repeated_claim_is_a_no_op_success(): void
    {
        $guest = $this->startGuestSession();
        $account = $this->registeredAccount();

        Event::fake([GuestClaimed::class]);

        $this->withToken($account['token'])
            ->postJson('/auth/guest/claim', ['guest_token' => $guest['token']])
            ->assertOk()
            ->assertJsonPath('data.claimed', true);

        $this->withToken($account['token'])
            ->postJson('/auth/guest/claim', ['guest_token' => $guest['token']])
            ->assertOk()
            ->assertJsonPath('data.claimed', false);

        Event::assertDispatchedTimes(GuestClaimed::class, 1);
    }

    /**
     * Test 3: a claim presented without the guest's own credential is rejected.
     */
    public function test_a_claim_without_the_guest_credential_is_rejected(): void
    {
        $account = $this->registeredAccount();

        Event::fake([GuestClaimed::class]);

        $this->withToken($account['token'])
            ->postJson('/auth/guest/claim', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['guest_token']);

        Event::assertNotDispatched(GuestClaimed::class);
    }

    /**
     * Test 4: a claim whose source is not a guest is rejected.
     */
    public function test_a_claim_whose_source_is_not_a_guest_is_rejected(): void
    {
        $source = $this->registeredAccount('source@example.com');
        $notificationId = $this->notificationFor($source['user']->getKey());
        $account = $this->registeredAccount();

        Event::fake([GuestClaimed::class]);

        $this->withToken($account['token'])
            ->postJson('/auth/guest/claim', ['guest_token' => $source['token']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['guest_token']);

        $this->assertSame(
            $source['user']->getKey(),
            $this->notificationOwner($notificationId),
            'A refused claim must move nothing.',
        );

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $source['user']->getKey(),
        ]);

        Event::assertNotDispatched(GuestClaimed::class);
    }

    /**
     * Test 5: a token issued to something other than the user model is rejected.
     *
     * The two halves of a claim resolve by different routes: the guest comes
     * from the token's own tokenable, and the row the transfer locks comes from
     * the configured user model. This is what stops them disagreeing, so the
     * other tokenable below deliberately carries the guest's own key.
     */
    public function test_a_claim_whose_token_names_another_tokenable_is_rejected(): void
    {
        $guest = $this->startGuestSession();
        $notificationId = $this->notificationFor($guest['user_id']);
        $account = $this->registeredAccount();

        $other = new GuestClaimOtherTokenable;
        $other->forceFill([
            'id' => $guest['user_id'],
            'is_guest' => true,
        ])->save();

        Event::fake([GuestClaimed::class]);

        $this->withToken($account['token'])
            ->postJson('/auth/guest/claim', [
                'guest_token' => (string) $other->createToken('auth_token')->plainTextToken,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['guest_token']);

        $this->assertSame(
            $guest['user_id'],
            $this->notificationOwner($notificationId),
            'A refused claim must move nothing.',
        );

        $this->assertDatabaseHas('users', [
            'id' => $guest['user_id'],
            'device_id' => 'claim-device-001',
            'is_guest' => true,
        ]);

        Event::assertNotDispatched(GuestClaimed::class);
    }

    /**
     * Test 6: a guest cannot claim itself.
     */
    public function test_a_claim_into_the_guest_itself_is_rejected(): void
    {
        $guest = $this->startGuestSession();

        Event::fake([GuestClaimed::class]);

        $this->withToken($guest['token'])
            ->postJson('/auth/guest/claim', ['guest_token' => $guest['token']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['guest_token']);

        Event::assertNotDispatched(GuestClaimed::class);
    }

    /**
     * Test 7: a listener that throws rolls the whole claim back.
     *
     * This is what makes the event a usable seam for a consumer moving its own
     * tables: the listener runs inside the claim's transaction, so a failed move
     * on the consumer's side leaves the guest exactly as it was.
     */
    public function test_a_listener_that_throws_rolls_the_whole_claim_back(): void
    {
        $guest = $this->startGuestSession();
        $notificationId = $this->notificationFor($guest['user_id']);
        $account = $this->registeredAccount();

        Event::listen(GuestClaimed::class, function (): void {
            throw new RuntimeException('The consumer listener failed.');
        });

        $this->withoutExceptionHandling();

        try {
            $this->withToken($account['token'])
                ->postJson('/auth/guest/claim', ['guest_token' => $guest['token']]);

            $this->fail('The listener failure should have propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The consumer listener failed.', $exception->getMessage());
        }

        $this->assertSame(
            $guest['user_id'],
            $this->notificationOwner($notificationId),
            'A rolled back claim must leave the guest rows where they were.',
        );

        $this->assertDatabaseHas('users', [
            'id' => $guest['user_id'],
            'device_id' => 'claim-device-001',
            'is_guest' => true,
        ]);

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $guest['user_id'],
        ]);
    }

    /**
     * Test 8: the claimed guest can never be authenticated again.
     */
    public function test_a_claimed_guest_can_never_be_authenticated_again(): void
    {
        $guest = $this->startGuestSession();
        $account = $this->registeredAccount();

        $this->withToken($account['token'])
            ->postJson('/auth/guest/claim', ['guest_token' => $guest['token']])
            ->assertOk();

        // The guest's own token is gone.
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $guest['user_id'],
        ]);

        // And the row itself is consumed: no device id to be found by, and no
        // guest flag for the guest lookup to match.
        $this->assertDatabaseHas('users', [
            'id' => $guest['user_id'],
            'device_id' => null,
            'is_guest' => false,
        ]);

        // A fresh guest login from the same device reaches a NEW row rather
        // than the consumed one.
        $second = $this->startGuestSession();

        $this->assertNotSame(
            $guest['user_id'],
            $second['user_id'],
            'The consumed guest row must not be reachable by its old device id.',
        );
    }
}

// ---------------------------------------------------------------------------
// Test fixture: a user model carrying real Sanctum tokens.
// ---------------------------------------------------------------------------

/**
 * @property string $id
 * @property string|null $name
 * @property string|null $email
 * @property string|null $password
 * @property bool $is_guest
 * @property string|null $device_id
 * @property string|null $phone
 * @property string $locale
 * @property string $timezone
 */
final class GuestClaimTestUser extends \Illuminate\Foundation\Auth\User
{
    use \FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
    use \FlutterSdk\MagicStarter\Traits\HasGuestSupport;
    use \FlutterSdk\MagicStarter\Traits\HasNotifications;
    use \FlutterSdk\MagicStarter\Traits\HasProfilePhoto;
    use \Laravel\Sanctum\HasApiTokens;

    protected $table = 'users';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_guest' => 'boolean',
            'email_verified_at' => 'datetime',
        ];
    }
}

/**
 * A second authenticatable the application issues Sanctum tokens to.
 *
 * Authenticatable and flagged as a guest, so the only thing that separates it
 * from a claimable guest is that it is not the configured user model.
 *
 * @property string $id
 * @property bool $is_guest
 */
final class GuestClaimOtherTokenable extends \Illuminate\Foundation\Auth\User
{
    use \FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
    use \Laravel\Sanctum\HasApiTokens;

    protected $table = 'devices';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_guest' => 'boolean',
        ];
    }
}
