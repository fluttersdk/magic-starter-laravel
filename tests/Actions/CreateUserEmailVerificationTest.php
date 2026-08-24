<?php

namespace FlutterSdk\MagicStarter\Tests\Actions;

use FlutterSdk\MagicStarter\Actions\CreateUser;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Notifications\VerifyEmailNotification;
use FlutterSdk\MagicStarter\Tests\TestCase;
use FlutterSdk\MagicStarter\Traits\MustVerifyEmail;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Event;
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
     * THE REGRESSION. A real registration goes through BOTH send paths: the
     * action sends explicitly (the case above), and `AuthController::register()`
     * then fires `Registered`, whose framework listener
     * (`Illuminate\Auth\Listeners\SendEmailVerificationNotification`) sends
     * again. Measured live on a consumer app: two identical "Verify Email
     * Address" mails, same signed URL, 47ms apart, on every sign-up.
     *
     * Both callers are legitimate and neither can simply go: the action owns the
     * intent (and the three cases around this one test it in isolation), while
     * `Registered` cannot stop firing because `CreatePersonalTeamListener` hangs
     * off it too. So the invariant lives where both paths funnel:
     * `MustVerifyEmail::sendEmailVerificationNotification()`.
     */
    public function test_a_registration_that_also_fires_registered_sends_exactly_one(): void
    {
        Notification::fake();

        config(['magic-starter.features' => [Features::emailVerification()]]);

        // Testbench's minimal app registers NO listeners for `Registered`, so the
        // duplicate cannot reproduce here without wiring the one a real Laravel app
        // has. That it is there in a real app is measured, not assumed: on the
        // consumer app `artisan event:list` shows `Registered` carrying both
        // `CreatePersonalTeamListener` and this framework listener.
        Event::listen(Registered::class, SendEmailVerificationNotification::class);

        $action = new CreateUser;

        // The exact sequence AuthController::register() runs, including handing the
        // SAME instance to the event, which is what the guard keys on.
        $user = $action->create([
            'name' => 'Erin',
            'email' => 'erin@example.com',
            'password' => 'Secret123!',
        ]);
        event(new Registered($user));

        Notification::assertSentToTimes($user, VerifyEmailNotification::class, 1);
    }

    /**
     * The guard is per-INSTANCE, and that boundary is the whole design.
     *
     * Two sends on ONE instance is the duplicate (the action plus the framework
     * listener, both holding the object `create()` returned), so it collapses to
     * one. A LATER request loads its own instance, which must still be able to
     * send: `POST /email/verification-notification` exists precisely so a customer
     * who lost the mail can ask again.
     *
     * A static keyed by user id would also kill the duplicate and would be WRONG
     * here: static state survives between requests under Octane, so the resend
     * would stay muted for the life of the worker.
     */
    public function test_the_guard_is_per_instance_so_a_later_request_can_resend(): void
    {
        Notification::fake();

        config(['magic-starter.features' => [Features::emailVerification()]]);

        // Built directly rather than through the action, so this test is about the
        // trait's guard and nothing else.
        $user = CreateUserVerificationTestUser::query()->create([
            'name' => 'Frank',
            'email' => 'frank@example.com',
            'password' => 'irrelevant',
        ]);

        // Twice on the same instance: the duplicate's exact shape.
        $user->sendEmailVerificationNotification();
        $user->sendEmailVerificationNotification();
        Notification::assertSentToTimes($user, VerifyEmailNotification::class, 1);

        // A new instance is a new request, and it must not be muted.
        $reloaded = CreateUserVerificationTestUser::query()->findOrFail($user->getKey());
        $reloaded->sendEmailVerificationNotification();
        Notification::assertSentToTimes($reloaded, VerifyEmailNotification::class, 2);
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
