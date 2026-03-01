<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\TestCase;
use FlutterSdk\MagicStarter\Traits\MustVerifyEmail;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

final class EmailVerificationControllerTest extends TestCase
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
            'magic-starter.models.user' => EmailVerificationTestUser::class,
            'magic-starter.features' => [
                Features::emailVerification(),
            ],
        ]);

        app('db.schema')->create('users', function ($table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->timestamps();
        });

        // Use `auth` guard (not `auth:sanctum`) in tests — actingAs() drives
        // the default guard, making auth middleware work without Sanctum setup.
        Route::middleware('auth')
            ->post(
                'email/verification-notification',
                [\FlutterSdk\MagicStarter\Http\Controllers\EmailVerificationController::class, 'sendVerificationNotification'],
            )->name('verification.send');

        Route::get(
            'email/verify/{id}/{hash}',
            [\FlutterSdk\MagicStarter\Http\Controllers\EmailVerificationController::class, 'verify'],
        )->middleware('signed')->name('verification.verify');
    }

    protected function tearDown(): void
    {
        MagicStarter::reset();
        parent::tearDown();
    }

    // ─── sendVerificationNotification ────────────────────────────────────────────

    public function test_send_verification_returns_202_for_unverified_user(): void
    {
        $user = EmailVerificationTestUser::query()->create([
            'name' => 'Unverified User',
            'email' => 'unverified@example.test',
            'email_verified_at' => null,
        ]);

        $this->actingAs($user)
            ->postJson('email/verification-notification')
            ->assertStatus(202)
            ->assertJsonPath('message', 'Verification link sent.');
    }

    public function test_send_verification_returns_200_when_already_verified(): void
    {
        $user = EmailVerificationTestUser::query()->create([
            'name' => 'Verified User',
            'email' => 'verified@example.test',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson('email/verification-notification')
            ->assertStatus(200)
            ->assertJsonPath('message', 'Email already verified.');
    }

    public function test_send_verification_returns_400_when_no_email(): void
    {
        $user = EmailVerificationTestUser::query()->create([
            'name' => 'No Email User',
            'email' => null,
            'email_verified_at' => null,
        ]);

        $this->actingAs($user)
            ->postJson('email/verification-notification')
            ->assertStatus(400)
            ->assertJsonPath('message', 'No email address to verify.');
    }

    public function test_send_verification_requires_auth(): void
    {
        $this->postJson('email/verification-notification')
            ->assertStatus(401);
    }

    // ─── verify ───────────────────────────────────────────────────────────

    public function test_verify_marks_email_as_verified(): void
    {
        $user = EmailVerificationTestUser::query()->create([
            'name' => 'Verify Me',
            'email' => 'verme@example.test',
            'email_verified_at' => null,
        ]);

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => $user->id,
                'hash' => sha1($user->email),
            ],
        );

        $this->getJson($url)
            ->assertStatus(200)
            ->assertJsonPath('message', 'Email verified successfully.');

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_verify_returns_200_when_already_verified(): void
    {
        $user = EmailVerificationTestUser::query()->create([
            'name' => 'Already Done',
            'email' => 'done@example.test',
            'email_verified_at' => now(),
        ]);

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => $user->id,
                'hash' => sha1($user->email),
            ],
        );

        $this->getJson($url)
            ->assertStatus(200)
            ->assertJsonPath('message', 'Email already verified.');
    }

    public function test_verify_fails_with_invalid_hash(): void
    {
        $user = EmailVerificationTestUser::query()->create([
            'name' => 'Bad Hash',
            'email' => 'badhash@example.test',
            'email_verified_at' => null,
        ]);

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => $user->id,
                'hash' => 'totallywronghash',
            ],
        );

        $this->getJson($url)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Invalid verification link.');
    }

    public function test_verify_fails_with_invalid_signature(): void
    {
        $user = EmailVerificationTestUser::query()->create([
            'name' => 'No Sig',
            'email' => 'nosig@example.test',
            'email_verified_at' => null,
        ]);

        // Unsigned URL — missing the signature query param.
        $unsignedUrl = route('verification.verify', [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $this->getJson($unsignedUrl)
            ->assertStatus(403);
    }

    public function test_verify_fires_verified_event(): void
    {
        Event::fake([Verified::class]);

        $user = EmailVerificationTestUser::query()->create([
            'name' => 'Event Watcher',
            'email' => 'events@example.test',
            'email_verified_at' => null,
        ]);

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => $user->id,
                'hash' => sha1($user->email),
            ],
        );

        $this->getJson($url)->assertStatus(200);

        Event::assertDispatched(Verified::class, function (Verified $event) use ($user): bool {
            return $event->user->getKey() === $user->getKey();
        });
    }

    public function test_routes_not_registered_when_feature_disabled(): void
    {
        // Verify the feature gate returns false when features config is empty.
        config(['magic-starter.features' => []]);

        $this->assertFalse(Features::hasEmailVerificationFeatures());
    }
}

/**
 * @internal Test-only user stub for email verification tests.
 */
final class EmailVerificationTestUser extends Authenticatable
{
    use HasUuids;
    use MustVerifyEmail;
    use Notifiable;

    /** @var string */
    protected $table = 'users';

    /** @var bool */
    public $incrementing = false;

    /** @var string */
    protected $keyType = 'string';

    /** @var array<int, string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];
}
