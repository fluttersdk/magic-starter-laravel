<?php

namespace FlutterSdk\MagicStarter\Tests\Traits;

use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Notifications\VerifyEmailNotification;
use FlutterSdk\MagicStarter\Tests\TestCase;
use FlutterSdk\MagicStarter\Traits\MustVerifyEmail;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

final class MustVerifyEmailTest extends TestCase
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
        ]);

        $this->app['db.schema']->create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('email')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_has_verified_email_returns_false_when_not_verified(): void
    {
        $user = MustVerifyEmailTestUser::query()->create([
            'email' => 'test@example.com',
            'email_verified_at' => null,
        ]);

        $this->assertFalse($user->hasVerifiedEmail());
    }

    public function test_has_verified_email_returns_true_when_verified(): void
    {
        $user = MustVerifyEmailTestUser::query()->create([
            'email' => 'test@example.com',
            'email_verified_at' => now(),
        ]);

        $this->assertTrue($user->hasVerifiedEmail());
    }

    public function test_mark_email_as_verified_sets_timestamp(): void
    {
        $user = MustVerifyEmailTestUser::query()->create([
            'email' => 'test@example.com',
            'email_verified_at' => null,
        ]);

        $user->markEmailAsVerified();

        $user->refresh();

        $this->assertNotNull($user->email_verified_at);
    }

    public function test_mark_email_as_verified_returns_true(): void
    {
        $user = MustVerifyEmailTestUser::query()->create([
            'email' => 'test@example.com',
            'email_verified_at' => null,
        ]);

        $this->assertTrue($user->markEmailAsVerified());
    }

    public function test_mark_email_as_verified_dispatches_verified_event(): void
    {
        Event::fake([
            Verified::class,
        ]);

        $user = MustVerifyEmailTestUser::query()->create([
            'email' => 'test@example.com',
            'email_verified_at' => null,
        ]);

        $user->markEmailAsVerified();

        Event::assertDispatched(
            Verified::class,
            static fn (Verified $event): bool => $event->user === $user,
        );
    }

    public function test_get_email_for_verification_returns_email(): void
    {
        $user = MustVerifyEmailTestUser::query()->create([
            'email' => 'test@example.com',
            'email_verified_at' => null,
        ]);

        $this->assertSame('test@example.com', $user->getEmailForVerification());
    }

    public function test_send_email_verification_notification_dispatches_notification(): void
    {
        Notification::fake();

        $user = MustVerifyEmailTestUser::query()->create([
            'email' => 'test@example.com',
            'email_verified_at' => null,
        ]);

        $user->sendEmailVerificationNotification();

        Notification::assertSentTo(
            $user,
            VerifyEmailNotification::class,
        );
    }

    /**
     * Test 7: Guest user bypasses email verification.
     *
     * Guest users have no email to verify, so hasVerifiedEmail() should
     * return true regardless of email_verified_at value.
     */
    public function test_guest_user_bypasses_email_verification(): void
    {
        $guest = MustVerifyEmailTestUser::query()->create([
            'email' => null,
            'email_verified_at' => null,
            'is_guest' => true,
        ]);

        $this->assertTrue($guest->hasVerifiedEmail());
    }

    /**
     * Test 8: Non-guest user is NOT bypassed.
     *
     * Regular users with null email_verified_at must still be unverified.
     */
    public function test_non_guest_user_is_not_bypassed(): void
    {
        $user = MustVerifyEmailTestUser::query()->create([
            'email' => 'regular@example.com',
            'email_verified_at' => null,
            'is_guest' => false,
        ]);

        $this->assertFalse($user->hasVerifiedEmail());
    }
}

final class MustVerifyEmailTestUser extends Authenticatable implements AuthenticatableContract, MustVerifyEmailContract
{
    use \FlutterSdk\MagicStarter\Traits\HasGuestSupport;
    use HasUuids;
    use MustVerifyEmail;
    use Notifiable;

    protected $table = 'users';

    public $incrementing = false;

    protected $keyType = 'string';

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
