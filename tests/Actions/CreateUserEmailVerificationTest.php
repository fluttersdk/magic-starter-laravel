<?php

namespace FlutterSdk\MagicStarter\Tests\Actions;

use FlutterSdk\MagicStarter\Actions\CreateUser;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Notifications\VerifyEmailNotification;
use FlutterSdk\MagicStarter\Tests\TestCase;
use FlutterSdk\MagicStarter\Traits\MustVerifyEmail;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Notification;

/**
 * Integration tests for email verification triggered by CreateUser action.
 *
 * Verifies that the registration flow sends (or skips) the
 * VerifyEmailNotification under each feature-gate condition.
 */
final class CreateUserEmailVerificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::reset();
        MagicStarter::useUserModel(CreateUserVerificationTestUser::class);

        \call_user_func('config', ['database.default' => 'testing']);
        \call_user_func('config', ['database.connections.testing' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);

        \call_user_func([\call_user_func('app', 'db.schema'), 'create'], 'users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique()->nullable();
            $table->string('password');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('locale')->default('en');
            $table->string('timezone')->default('UTC');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        MagicStarter::reset();

        parent::tearDown();
    }

    /**
     * When email verification is enabled, CreateUser should send the
     * VerifyEmailNotification to a newly registered user whose email
     * is unverified (email_verified_at = null).
     */
    public function test_registration_sends_verification_when_feature_enabled(): void
    {
        Notification::fake();

        config(['magic-starter.features' => [Features::emailVerification()]]);

        $action = new CreateUser;

        $user = $action->create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'Secret123!',
        ]);

        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    /**
     * When the email verification feature is disabled, no verification
     * notification should be dispatched regardless of the user's email state.
     */
    public function test_registration_skips_verification_when_feature_disabled(): void
    {
        Notification::fake();

        config(['magic-starter.features' => []]);

        $action = new CreateUser;

        $action->create([
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => 'Secret123!',
        ]);

        Notification::assertNothingSent();
    }

    /**
     * Social login users arrive with email_verified_at already set.
     * The verification notification must be skipped for them.
     *
     * Requires extended_profile to be enabled so that email_verified_at is
     * accepted as a valid registration input.
     */
    public function test_registration_skips_verification_for_social_login(): void
    {
        Notification::fake();

        config([
            'magic-starter.features' => [
                Features::emailVerification(),
                Features::extendedProfile(),
            ],
        ]);

        $action = new CreateUser;

        $action->create([
            'name' => 'Carol',
            'email' => 'carol@example.com',
            'password' => 'Secret123!',
            'email_verified_at' => now()->toDateTimeString(),
        ]);

        Notification::assertNothingSent();
    }

    /**
     * Phone-based users have no email address.
     * The verification notification must be skipped for them.
     */
    public function test_registration_skips_verification_when_no_email(): void
    {
        Notification::fake();

        config(['magic-starter.features' => [Features::emailVerification()]]);

        \call_user_func([\call_user_func('app', 'db.schema'), 'table'], 'users', function (Blueprint $table): void {
            $table->string('phone')->unique()->nullable();
        });

        $action = new CreateUser;

        $action->create([
            'name' => 'Dave',
            'phone' => '+14155552671',
            'password' => 'Secret123!',
        ]);

        Notification::assertNothingSent();
    }
}

/**
 * @property string|null $email
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 */
final class CreateUserVerificationTestUser extends Authenticatable implements AuthenticatableContract, MustVerifyEmailContract
{
    use HasUuids;
    use MustVerifyEmail;
    use Notifiable;

    protected $table = 'users';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'email_verified_at' => 'datetime',
    ];
}
