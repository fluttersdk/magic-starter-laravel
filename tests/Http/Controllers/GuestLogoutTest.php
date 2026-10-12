<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Integration tests for a guest signing out (issue #40).
 *
 * A guest row has no credential besides its tokens and its device id, so a
 * sign-out that left either behind let the next person on the same device
 * resume the session, by a guest login with the device id or by a claim made
 * with a token the guest still held.
 */
class GuestLogoutTest extends TestCase
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
     * Configure the features and the user model BEFORE the providers boot, so
     * the feature-gated guest routes are registered.
     *
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('magic-starter.features', ['guest-auth', 'notifications']);
        $app['config']->set('auth.providers.users.model', GuestLogoutTestUser::class);
        $app['config']->set('magic-starter.models.user', GuestLogoutTestUser::class);
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
     * @return array{token: string, user_id: string}
     */
    private function startGuestSession(string $deviceId = 'logout-device-001'): array
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
     * @return array{token: string, user: GuestLogoutTestUser}
     */
    private function registeredAccount(string $email = 'target@example.com'): array
    {
        /** @var GuestLogoutTestUser $user */
        $user = GuestLogoutTestUser::create([
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
     * Issue the user a second token, the way an application minting its own
     * sessions would, so one survives the token a sign-out presents.
     */
    private function secondTokenFor(string $userId): string
    {
        /** @var GuestLogoutTestUser $user */
        $user = GuestLogoutTestUser::query()->findOrFail($userId);

        return (string) $user->createToken('auth_token')->plainTextToken;
    }

    /**
     * Sign out with a token, then drop the guard's cached user so the next
     * request in the same test authenticates from scratch.
     */
    private function signOut(string $token): void
    {
        $this->withToken($token)
            ->postJson('/auth/logout')
            ->assertOk();

        $this->app['auth']->forgetGuards();
    }

    /**
     * Give a user one database notification row.
     */
    private function notificationFor(string $userId): string
    {
        $id = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id' => $id,
            'type' => 'Tests\\Notifications\\Welcome',
            'notifiable_type' => GuestLogoutTestUser::class,
            'notifiable_id' => $userId,
            'data' => json_encode(['body' => 'Welcome']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    public function test_a_guest_sign_out_revokes_every_token_and_releases_the_device_id(): void
    {
        $guest = $this->startGuestSession();
        $this->secondTokenFor($guest['user_id']);

        $this->signOut($guest['token']);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $guest['user_id'],
        ]);

        // The row stays a guest, so a job pruning abandoned guests still finds it.
        $this->assertDatabaseHas('users', [
            'id' => $guest['user_id'],
            'device_id' => null,
            'is_guest' => true,
        ]);
    }

    public function test_a_guest_login_after_sign_out_opens_a_new_guest_row(): void
    {
        $guest = $this->startGuestSession();

        $this->signOut($guest['token']);

        $response = $this->postJson('/auth/guest', [
            'device_id' => 'logout-device-001',
        ]);

        $response->assertCreated();

        $this->assertNotSame(
            $guest['user_id'],
            (string) $response->json('data.user.id'),
            'A signed-out guest must not be resumable by its device id.',
        );
    }

    public function test_a_claim_with_a_token_the_signed_out_guest_held_moves_nothing(): void
    {
        $guest = $this->startGuestSession();
        $otherGuestToken = $this->secondTokenFor($guest['user_id']);
        $notificationId = $this->notificationFor($guest['user_id']);
        $account = $this->registeredAccount();

        $this->signOut($guest['token']);

        $this->withToken($account['token'])
            ->postJson('/auth/guest/claim', ['guest_token' => $otherGuestToken])
            ->assertOk()
            ->assertJsonPath('data.claimed', false);

        $this->assertDatabaseHas('notifications', [
            'id' => $notificationId,
            'notifiable_id' => $guest['user_id'],
        ]);
    }

    public function test_a_claim_with_the_token_that_signed_out_moves_nothing(): void
    {
        $guest = $this->startGuestSession();
        $notificationId = $this->notificationFor($guest['user_id']);
        $account = $this->registeredAccount();

        $this->signOut($guest['token']);

        $this->withToken($account['token'])
            ->postJson('/auth/guest/claim', ['guest_token' => $guest['token']])
            ->assertOk()
            ->assertJsonPath('data.claimed', false);

        $this->assertDatabaseHas('notifications', [
            'id' => $notificationId,
            'notifiable_id' => $guest['user_id'],
        ]);
    }

    public function test_an_account_sign_out_revokes_only_the_presented_token(): void
    {
        $account = $this->registeredAccount();
        $otherToken = $this->secondTokenFor($account['user']->getKey());
        $account['user']->forceFill(['device_id' => 'promoted-device-001'])->save();

        $this->signOut($account['token']);

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->withToken($otherToken)
            ->getJson('/auth/user')
            ->assertOk()
            ->assertJsonPath('data.id', $account['user']->getKey());

        $this->assertDatabaseHas('users', [
            'id' => $account['user']->getKey(),
            'device_id' => 'promoted-device-001',
        ]);
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
final class GuestLogoutTestUser extends \Illuminate\Foundation\Auth\User
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
