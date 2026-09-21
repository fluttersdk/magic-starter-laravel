<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Http\Controllers\GuestAuthController;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeamUser;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use FlutterSdk\MagicStarter\Traits\HasGuestSupport;
use FlutterSdk\MagicStarter\Traits\HasProfilePhoto;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as AuthenticatableUser;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * Integration tests for Guest to Registered conversion.
 * Covers profile updates, password setting, and is_guest flip logic.
 */
class GuestConversionTest extends TestCase
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

        config([
            'auth.providers.users.model' => ConcreteUser::class,
            'magic-starter.models.user' => ConcreteUser::class,
            'magic-starter.models.team' => ConcreteTeam::class,
            'magic-starter.models.membership' => ConcreteTeamUser::class,
            'magic-starter.features' => ['guest-auth', 'extended-profile'],
        ]);

        \call_user_func([\call_user_func('app', 'db.schema'), 'create'], 'users', function (Blueprint $table): void {
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
            $table->string('profile_photo_path')->nullable();
            $table->string('current_team_id')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });

        \call_user_func([\call_user_func('app', 'db.schema'), 'create'], 'teams', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('name');
            $table->boolean('personal_team')->default(false);
            $table->string('profile_photo_path', 2048)->nullable();
            $table->timestamps();
        });

        Schema::create('team_user', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->uuid('user_id');
            $table->string('role')->nullable();
            $table->timestamps();
        });

        \call_user_func([\call_user_func('app', 'db.schema'), 'create'], 'personal_access_tokens', function (Blueprint $table): void {
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

        $this->app->instance(
            \FlutterSdk\MagicStarter\Contracts\UpdatesUserProfiles::class,
            $this->app->make(\FlutterSdk\MagicStarter\Actions\UpdateUserProfile::class),
        );
        $this->app->instance(
            \FlutterSdk\MagicStarter\Contracts\UpdatesUserPasswords::class,
            $this->app->make(\FlutterSdk\MagicStarter\Actions\UpdateUserPassword::class),
        );

        \Illuminate\Support\Facades\Route::put('/user/profile', [\FlutterSdk\MagicStarter\Http\Controllers\ProfileController::class, 'update']);
        \Illuminate\Support\Facades\Route::put('/user/password', [\FlutterSdk\MagicStarter\Http\Controllers\ProfileController::class, 'updatePassword']);

        // Public, like the shipped route: guest login carries no credential.
        Route::post('/auth/guest', [GuestAuthController::class, 'login']);
    }

    protected function tearDown(): void
    {
        MagicStarter::reset();
        parent::tearDown();
    }

    /**
     * Test 1: Guest user with email update stays guest without password
     */
    public function test_guest_user_with_email_update_stays_guest_without_password(): void
    {
        /** @var ConcreteUser $user */
        $user = ConcreteUser::create([
            'is_guest' => true,
            'device_id' => 'device-1',
        ]);

        $this->actingAs($user)
            ->putJson('/user/profile', [
                'email' => 'guest@example.com',
            ])
            ->assertOk();

        $fresh = $user->fresh();
        $this->assertTrue((bool) $fresh->is_guest, 'User must remain a guest until password is set');
        $this->assertSame('guest@example.com', $fresh->email);
    }

    /**
     * Test 2: Guest converts after email and password
     */
    public function test_guest_converts_after_email_and_password(): void
    {
        /** @var ConcreteUser $user */
        $user = ConcreteUser::create([
            'is_guest' => true,
            'device_id' => 'device-2',
        ]);

        $this->actingAs($user)
            ->putJson('/user/profile', [
                'email' => 'converted@example.com',
            ])
            ->assertOk();

        $this->actingAs($user)
            ->putJson('/user/password', [
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ])
            ->assertOk();

        $fresh = $user->fresh();
        $this->assertFalse((bool) $fresh->is_guest, 'User must be converted after email and password');
    }

    /**
     * Test 3: Guest user with phone update stays guest without password
     */
    public function test_guest_user_with_phone_update_stays_guest_without_password(): void
    {
        /** @var ConcreteUser $user */
        $user = ConcreteUser::create([
            'is_guest' => true,
            'device_id' => 'device-3',
        ]);

        $this->actingAs($user)
            ->putJson('/user/profile', [
                'phone' => '+14155552671',
            ])
            ->assertOk();

        $fresh = $user->fresh();
        $this->assertTrue((bool) $fresh->is_guest, 'User must remain a guest until password is set');
        $this->assertSame('+14155552671', $fresh->phone);
    }

    /**
     * Test 4: Guest converts after phone and password
     */
    public function test_guest_converts_after_phone_and_password(): void
    {
        /** @var ConcreteUser $user */
        $user = ConcreteUser::create([
            'is_guest' => true,
            'device_id' => 'device-4',
        ]);

        $this->actingAs($user)
            ->putJson('/user/profile', [
                'phone' => '+14155552672',
            ])
            ->assertOk();

        $this->actingAs($user)
            ->putJson('/user/password', [
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ])
            ->assertOk();

        $fresh = $user->fresh();
        $this->assertFalse((bool) $fresh->is_guest, 'User must be converted after phone and password');
    }

    /**
     * Test 5: Non-guest profile update unaffected
     */
    public function test_non_guest_profile_update_unaffected(): void
    {
        /** @var ConcreteUser $user */
        $user = ConcreteUser::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => Hash::make('Password123!'),
            'is_guest' => false,
        ]);

        $this->actingAs($user)
            ->putJson('/user/profile', [
                'name' => 'Jane Doe',
            ])
            ->assertOk();

        $fresh = $user->fresh();
        $this->assertFalse((bool) $fresh->is_guest, 'Regular user must not become a guest');
        $this->assertSame('Jane Doe', $fresh->name);
    }

    /**
     * Test 6: Guest name update only stays guest
     */
    public function test_guest_name_update_only_stays_guest(): void
    {
        /** @var ConcreteUser $user */
        $user = ConcreteUser::create([
            'is_guest' => true,
            'device_id' => 'device-5',
        ]);

        $this->actingAs($user)
            ->putJson('/user/profile', [
                'name' => 'Guest User',
            ])
            ->assertOk();

        $fresh = $user->fresh();
        $this->assertTrue((bool) $fresh->is_guest, 'Guest must remain guest when only updating name');
        $this->assertSame('Guest User', $fresh->name);
    }

    /**
     * Test 7: Guest can set initial password without current_password
     */
    public function test_guest_can_set_initial_password_without_current_password(): void
    {
        /** @var ConcreteUser $user */
        $user = ConcreteUser::create([
            'is_guest' => true,
            'device_id' => 'device-6',
        ]);

        $this->actingAs($user)
            ->putJson('/user/password', [
                // Notice: current_password is intentionally omitted
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ])
            ->assertOk();

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('Password123!', (string) $fresh->password), 'Password should be successfully set');
    }

    /**
     * Test 8: Guest upgrade via single profile update with email + password
     */
    public function test_guest_converts_via_single_profile_update_with_email_and_password(): void
    {
        /** @var ConcreteUser $user */
        $user = ConcreteUser::create([
            'is_guest' => true,
            'device_id' => 'device-8',
        ]);

        $this->actingAs($user)
            ->putJson('/user/profile', [
                'name' => 'Upgraded User',
                'email' => 'upgraded@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ])
            ->assertOk();

        $fresh = $user->fresh();
        $this->assertFalse((bool) $fresh->is_guest, 'User must be converted after single profile update with email + password');
        $this->assertSame('Upgraded User', $fresh->name);
        $this->assertSame('upgraded@example.com', $fresh->email);
        $this->assertTrue(Hash::check('Password123!', (string) $fresh->password), 'Password must be hashed and stored');
    }

    /**
     * Test 9: Guest profile update rejects mismatched password confirmation
     */
    public function test_guest_profile_update_rejects_mismatched_password_confirmation(): void
    {
        /** @var ConcreteUser $user */
        $user = ConcreteUser::create([
            'is_guest' => true,
            'device_id' => 'device-9',
        ]);

        $this->actingAs($user)
            ->putJson('/user/profile', [
                'name' => 'Guest User',
                'email' => 'mismatch@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'DifferentPassword456!',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    /**
     * Test 10: Guest profile update rejects weak password
     */
    public function test_guest_profile_update_rejects_weak_password(): void
    {
        /** @var ConcreteUser $user */
        $user = ConcreteUser::create([
            'is_guest' => true,
            'device_id' => 'device-10',
        ]);

        $this->actingAs($user)
            ->putJson('/user/profile', [
                'name' => 'Guest User',
                'email' => 'weak@example.com',
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    /**
     * Test 11: Non-guest profile update ignores password field entirely
     */
    public function test_non_guest_profile_update_ignores_password_field(): void
    {
        /** @var ConcreteUser $user */
        $user = ConcreteUser::create([
            'name' => 'Regular User',
            'email' => 'regular@example.com',
            'password' => Hash::make('OriginalPassword123!'),
            'is_guest' => false,
        ]);

        $this->actingAs($user)
            ->putJson('/user/profile', [
                'name' => 'Updated Name',
                'password' => 'HackerPassword123!',
                'password_confirmation' => 'HackerPassword123!',
            ])
            ->assertOk();

        $fresh = $user->fresh();
        $this->assertTrue(
            Hash::check('OriginalPassword123!', (string) $fresh->password),
            'Non-guest password must NOT be changed via profile update',
        );
    }

    /**
     * Test 12: Guest profile update with password but no email stays guest (needs identity)
     */
    public function test_guest_profile_update_with_password_only_stays_guest(): void
    {
        /** @var ConcreteUser $user */
        $user = ConcreteUser::create([
            'is_guest' => true,
            'device_id' => 'device-12',
        ]);

        $this->actingAs($user)
            ->putJson('/user/profile', [
                'name' => 'Guest With Password',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ])
            ->assertOk();

        $fresh = $user->fresh();
        // Password should be set, but user needs email or phone to fully convert
        $this->assertTrue(Hash::check('Password123!', (string) $fresh->password), 'Password must be hashed and stored even without email');
        $this->assertTrue((bool) $fresh->is_guest, 'Guest must remain guest without email or phone identity');
    }

    /**
     * Test 13: A promoted account cannot be reached by replaying its device id.
     *
     * The device id is an anonymous session key and POST auth/guest accepts it
     * with no credential beside it, so a row that has stopped being anonymous
     * must never be answered with a session as that account. The row is built
     * here still carrying the identifier, which is the state of every account
     * promoted before the release that frees it.
     */
    public function test_promoted_account_is_not_reachable_through_guest_login(): void
    {
        $this->useStubShapedUserModel();

        $promoted = GuestConversionGuardedUser::query()->newModelInstance();
        $promoted->forceFill([
            'name' => 'Promoted User',
            'email' => 'promoted@example.com',
            'password' => Hash::make('Password123!'),
            'is_guest' => false,
            'device_id' => 'device-13',
        ])->save();

        $response = $this->postJson('/auth/guest', [
            'device_id' => 'device-13',
        ]);

        $response->assertSuccessful();

        $this->assertNotSame(
            $promoted->getKey(),
            $response->json('data.user.id'),
            'Replaying a promoted account device id must not answer with a session as that account',
        );
        $this->assertTrue(
            $response->json('data.user.is_guest'),
            'The caller is a device holding no session, so it must receive a usable guest one',
        );
        $this->assertSame(
            2,
            GuestConversionGuardedUser::query()->count(),
            'The guest must be a new row rather than the promoted one',
        );
        $this->assertNull(
            $promoted->fresh()->device_id,
            'A registered row must release an identifier it no longer answers to, which the unique index also requires',
        );
    }

    /**
     * Test 14: Promotion releases the device id.
     *
     * Runs against a model shaped like the published User stub, where is_guest
     * and device_id sit outside $fillable. The fully unguarded fixture the tests
     * above use cannot tell a persisted promotion from one that mass assignment
     * dropped on the way to the database.
     */
    public function test_promotion_releases_the_device_id(): void
    {
        $this->useStubShapedUserModel();

        $guest = GuestConversionGuardedUser::query()->newModelInstance();
        $guest->forceFill([
            'is_guest' => true,
            'device_id' => 'device-14',
        ])->save();

        $this->actingAs($guest)
            ->putJson('/user/profile', [
                'name' => 'Promoted User',
                'email' => 'promoted-14@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ])
            ->assertOk();

        $fresh = $guest->fresh();

        $this->assertFalse((bool) $fresh->is_guest, 'Promotion must persist past the mass-assignment guard');
        $this->assertNull($fresh->device_id, 'A registered account must stop answering to the device id');
    }

    /**
     * Test 15: Promotion through the password endpoint releases the device id too.
     *
     * A guest that sets an email first and a password second is promoted by
     * UpdateUserPassword rather than UpdateUserProfile, and the identifier has to
     * stop being a credential on whichever of the two paths gets there. Tests 2
     * and 4 walk the same flow on the unguarded fixture, so neither can see a
     * promotion write that mass assignment drops.
     */
    public function test_password_promotion_releases_the_device_id(): void
    {
        $this->useStubShapedUserModel();

        $guest = GuestConversionGuardedUser::query()->newModelInstance();
        $guest->forceFill([
            'is_guest' => true,
            'device_id' => 'device-15',
        ])->save();

        $this->actingAs($guest)
            ->putJson('/user/profile', [
                'email' => 'promoted-15@example.com',
            ])
            ->assertOk();

        $this->assertSame(
            'device-15',
            $guest->fresh()->device_id,
            'An email alone leaves the account a guest, so it keeps answering to the device id',
        );

        $this->actingAs($guest)
            ->putJson('/user/password', [
                // Notice: current_password is intentionally omitted, the guest has none yet.
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ])
            ->assertOk();

        $fresh = $guest->fresh();

        $this->assertFalse((bool) $fresh->is_guest, 'Promotion must persist past the mass-assignment guard');
        $this->assertNull($fresh->device_id, 'A registered account must stop answering to the device id');
    }

    /**
     * Point the package at a user model shaped like the published stub.
     *
     * Every other case in this file runs on ConcreteUser, which is fully
     * unguarded and therefore cannot fail on a write that mass assignment drops.
     * The two cases above are about writes to is_guest and device_id, the exact
     * pair the stub keeps out of $fillable.
     */
    private function useStubShapedUserModel(): void
    {
        MagicStarter::useUserModel(GuestConversionGuardedUser::class);

        config([
            'auth.providers.users.model' => GuestConversionGuardedUser::class,
            'magic-starter.models.user' => GuestConversionGuardedUser::class,
        ]);
    }
}

/**
 * Mirrors the published User stub's mass-assignment surface.
 *
 * is_guest, device_id and current_team_id are system-managed and deliberately
 * absent from $fillable there, so only a forceFill can persist them.
 *
 * @property string|null $device_id
 * @property bool $is_guest
 */
final class GuestConversionGuardedUser extends AuthenticatableUser
{
    use ConditionallyUsesUuids;
    use HasGuestSupport;
    use HasProfilePhoto;

    protected $table = 'users';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'phone_country',
        'locale',
        'timezone',
    ];

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
