<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Http\Controllers\OtpController;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeamUser;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * Integration tests for the OtpController.
 *
 * Covers sending OTPs, logging codes, verifying valid/invalid codes,
 * and checking feature-flag gating.
 */
class OtpControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        MagicStarter::reset();

        config([
            'auth.providers.users.model' => ConcreteUser::class,
            'magic-starter.models.user' => ConcreteUser::class,
            'magic-starter.models.team' => ConcreteTeam::class,
            'magic-starter.models.membership' => ConcreteTeamUser::class,
            'magic-starter.features' => ['phone-otp', 'phone-auth'],
        ]);

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
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table): void {
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

        Route::post('/auth/otp/send', [OtpController::class, 'send']);
        Route::post('/auth/otp/verify', [OtpController::class, 'verify']);
    }

    protected function tearDown(): void
    {
        MagicStarter::reset();
        parent::tearDown();
    }

    /**
     * Test 1: Send returns 200 and caches OTP code.
     */
    public function test_otp_send_returns_200_and_caches_otp(): void
    {
        $response = $this->postJson('/auth/otp/send', [
            'phone' => '+14155552671',
        ]);

        $response
            ->assertOk()
            ->assertJson([
                'message' => 'OTP sent successfully',
            ]);

        $this->assertTrue(Cache::has('otp_+14155552671'), 'OTP must be cached for the phone number.');
    }

    /**
     * Test 2: Send logs the OTP code via the LogOtpProvider.
     */
    public function test_otp_send_logs_via_log_otp_provider(): void
    {
        Log::spy();

        $this->postJson('/auth/otp/send', [
            'phone' => '+14155552671',
        ])->assertOk();

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(fn ($msg) => str_contains((string) $msg, 'OTP for +14155552671: '));
    }

    /**
     * Test 3: Verify with correct code returns token for existing user.
     */
    public function test_otp_verify_with_correct_code_returns_token(): void
    {
        ConcreteUser::create([
            'phone' => '+14155552671',
        ]);

        Cache::put('otp_+14155552671', '123456', 300);

        $response = $this->postJson('/auth/otp/verify', [
            'phone' => '+14155552671',
            'code' => '123456',
        ]);

        $response
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'user',
                    'token',
                ],
            ]);

        $this->assertFalse(Cache::has('otp_+14155552671'), 'OTP must be consumed after successful verification.');
    }

    /**
     * Test 4: Verify with wrong code returns 401.
     */
    public function test_otp_verify_with_wrong_code_returns_401(): void
    {
        Cache::put('otp_+14155552671', '123456', 300);

        $this->postJson('/auth/otp/verify', [
            'phone' => '+14155552671',
            'code' => '654321',
        ])
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'Invalid or expired OTP',
            ]);
    }

    /**
     * Test 5: Verify with consumed OTP returns 401.
     */
    public function test_otp_verify_with_consumed_otp_returns_401(): void
    {
        ConcreteUser::create([
            'phone' => '+14155552671',
        ]);

        Cache::put('otp_+14155552671', '123456', 300);

        // First verification succeeds
        $this->postJson('/auth/otp/verify', [
            'phone' => '+14155552671',
            'code' => '123456',
        ])->assertOk();

        // Second verification with the same code fails (it was consumed)
        $this->postJson('/auth/otp/verify', [
            'phone' => '+14155552671',
            'code' => '123456',
        ])->assertUnauthorized();
    }

    /**
     * Test 6: Verify with non-existent phone returns 404 User not found.
     */
    public function test_otp_verify_with_nonexistent_phone_returns_404(): void
    {
        // OTP is cached, meaning it's valid, but no user has this phone number.
        Cache::put('otp_+14155552671', '123456', 300);

        $this->postJson('/auth/otp/verify', [
            'phone' => '+14155552671',
            'code' => '123456',
        ])
            ->assertNotFound()
            ->assertJson([
                'message' => 'User not found',
            ]);
    }

    /**
     * Test 7: Send returns 404 when phone-otp feature is disabled.
     */
    public function test_otp_send_returns_404_when_feature_disabled(): void
    {
        config(['magic-starter.features' => []]);
        MagicStarter::reset();
        $this->refreshApplication();

        $this->postJson('/auth/otp/send-disabled', [
            'phone' => '+14155552671',
        ])->assertNotFound();
    }

    /**
     * Test 8: Verify returns 404 when phone-otp feature is disabled.
     */
    public function test_otp_verify_returns_404_when_feature_disabled(): void
    {
        config(['magic-starter.features' => []]);
        MagicStarter::reset();
        $this->refreshApplication();

        $this->postJson('/auth/otp/verify-disabled', [
            'phone' => '+14155552671',
            'code' => '123456',
        ])->assertNotFound();
    }
}
