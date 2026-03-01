<?php

namespace FlutterSdk\MagicStarter\Tests\Actions;

use FlutterSdk\MagicStarter\Actions\UpdateUserProfile;
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
 * Integration tests for email verification triggered by UpdateUserProfile action.
 *
 * Verifies that changing a user's email correctly nullifies email_verified_at
 * and dispatches a new VerifyEmailNotification when the feature is enabled.
 */
final class UpdateUserProfileEmailVerificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::reset();
        MagicStarter::useUserModel(UpdateUserProfileVerificationTestUser::class);

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
            $table->string('password')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        MagicStarter::reset();

        parent::tearDown();
    }

    /**
     * When the user changes their email and the feature is enabled,
     * email_verified_at must be set to null.
     */
    public function test_email_change_nullifies_verified_at_when_feature_enabled(): void
    {
        Notification::fake();

        config(['magic-starter.features' => [Features::emailVerification()]]);

        /** @var UpdateUserProfileVerificationTestUser $user */
        $user = UpdateUserProfileVerificationTestUser::query()->create([
            'name' => 'Eve',
            'email' => 'eve@example.com',
            'email_verified_at' => now(),
        ]);

        $this->assertNotNull($user->email_verified_at);

        $action = new UpdateUserProfile;
        $action->update($user, ['name' => 'Eve', 'email' => 'eve-new@example.com']);

        $user->refresh();

        $this->assertNull($user->email_verified_at);
    }

    /**
     * When the user changes their email and the feature is enabled,
     * a new VerifyEmailNotification must be dispatched.
     */
    public function test_email_change_sends_new_verification_when_feature_enabled(): void
    {
        Notification::fake();

        config(['magic-starter.features' => [Features::emailVerification()]]);

        /** @var UpdateUserProfileVerificationTestUser $user */
        $user = UpdateUserProfileVerificationTestUser::query()->create([
            'name' => 'Frank',
            'email' => 'frank@example.com',
            'email_verified_at' => now(),
        ]);

        $action = new UpdateUserProfile;
        $action->update($user, ['name' => 'Frank', 'email' => 'frank-new@example.com']);

        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    /**
     * When the user submits the same email (no change), email_verified_at
     * must remain intact — verification should not be triggered again.
     */
    public function test_same_email_does_not_nullify_verified_at(): void
    {
        Notification::fake();

        config(['magic-starter.features' => [Features::emailVerification()]]);

        $verifiedAt = now()->subDay();

        /** @var UpdateUserProfileVerificationTestUser $user */
        $user = UpdateUserProfileVerificationTestUser::query()->create([
            'name' => 'Grace',
            'email' => 'grace@example.com',
            'email_verified_at' => $verifiedAt,
        ]);

        $action = new UpdateUserProfile;
        $action->update($user, ['name' => 'Grace Updated', 'email' => 'grace@example.com']);

        $user->refresh();

        $this->assertNotNull($user->email_verified_at);
        Notification::assertNothingSent();
    }

    /**
     * When the email verification feature is disabled, changing the email
     * must not alter email_verified_at or send a notification.
     */
    public function test_email_change_ignored_when_feature_disabled(): void
    {
        Notification::fake();

        config(['magic-starter.features' => []]);

        $verifiedAt = now()->subDay();

        /** @var UpdateUserProfileVerificationTestUser $user */
        $user = UpdateUserProfileVerificationTestUser::query()->create([
            'name' => 'Hank',
            'email' => 'hank@example.com',
            'email_verified_at' => $verifiedAt,
        ]);

        $action = new UpdateUserProfile;
        $action->update($user, ['name' => 'Hank', 'email' => 'hank-new@example.com']);

        $user->refresh();

        $this->assertNotNull($user->email_verified_at);
        Notification::assertNothingSent();
    }
}

/**
 * @property string|null $email
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 */
final class UpdateUserProfileVerificationTestUser extends Authenticatable implements AuthenticatableContract, MustVerifyEmailContract
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
