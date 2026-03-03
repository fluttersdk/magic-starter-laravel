<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeamUser;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
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
}
