<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Requests;

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Social\StepUpConfirmations;
use FlutterSdk\MagicStarter\Support\TwoFactorAuthenticationProvider;
use FlutterSdk\MagicStarter\Tests\TestCase;
use FlutterSdk\MagicStarter\Traits\HasSocialAccounts;
use FlutterSdk\MagicStarter\Traits\TwoFactorAuthenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\SanctumServiceProvider;

/**
 * The step-up every password-gated endpoint asks of an account without a password.
 *
 * Driven through the package's own routes with a real bearer, so the request
 * classes are exercised exactly as a client meets them. A password user and a
 * password-less guest must see today's behaviour; a social-only account must
 * prove itself with a TOTP code (only when 2FA is confirmed) or a single-use
 * confirmation token minted by the social confirm flow.
 *
 * Stable codes asserted here: `step_up_required` (with `accepts`) when the
 * proof is missing or spent, and `password_not_set` when a social-only account
 * asks to change a password it does not have.
 */
class StepUpTest extends TestCase
{
    private const PASSWORD = 'Password123';

    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            SanctumServiceProvider::class,
            MagicStarterServiceProvider::class,
        ];
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The 2FA secret and recovery codes are encrypted.
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('magic-starter.features', [
            Features::twoFactorAuthentication(),
        ]);
        $app['config']->set('auth.providers.users.model', StepUpTestUser::class);
        $app['config']->set('magic-starter.models.user', StepUpTestUser::class);
        $app['config']->set('magic-starter.social.cache_store', null);
    }

    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::useUserModel(StepUpTestUser::class);

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamp('deletion_scheduled_at')->nullable();
            $table->timestamp('orphaned_at')->nullable();
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
    }

    protected function tearDown(): void
    {
        MagicStarter::reset();

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // A password user: unchanged
    // ---------------------------------------------------------------------

    public function test_a_password_user_disables_two_factor_with_the_password(): void
    {
        $user = $this->makePasswordUser(twoFactor: true);

        $this->sendAs($user, 'DELETE', '/two-factor-authentication', [
            'password' => self::PASSWORD,
        ])->assertOk();

        $this->assertNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_a_password_user_cannot_swap_the_password_for_a_confirmation_token(): void
    {
        $user = $this->makePasswordUser(twoFactor: true);

        $this->sendAs($user, 'DELETE', '/two-factor-authentication', [
            'confirmation_token' => $this->mint($user),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_a_password_user_with_a_wrong_password_is_refused(): void
    {
        $user = $this->makePasswordUser(twoFactor: true);

        $this->sendAs($user, 'DELETE', '/user', [
            'password' => 'wrong-password',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        $this->assertNull($user->fresh()->deletion_scheduled_at);
    }

    // ---------------------------------------------------------------------
    // A guest without a password: unchanged bypass
    // ---------------------------------------------------------------------

    public function test_a_guest_without_a_password_deletes_without_proof(): void
    {
        $user = StepUpTestUser::query()->create([
            'name' => 'Guest',
            'is_guest' => true,
        ]);

        $this->sendAs($user, 'DELETE', '/user')->assertStatus(202);

        $this->assertNotNull($user->fresh()->deletion_scheduled_at);
    }

    public function test_a_guest_without_a_password_sets_a_password_without_a_current_one(): void
    {
        $user = StepUpTestUser::query()->create([
            'name' => 'Guest',
            'is_guest' => true,
        ]);

        $this->sendAs($user, 'PUT', '/user/password', [
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ])->assertOk();

        $this->assertTrue(Hash::check('NewPassword123', (string) $user->fresh()->password));
    }

    // ---------------------------------------------------------------------
    // A social-only user with confirmed 2FA
    // ---------------------------------------------------------------------

    public function test_a_social_only_user_disabling_two_factor_without_proof_must_step_up(): void
    {
        $user = $this->makeSocialUser(twoFactor: true);

        $this->sendAs($user, 'DELETE', '/two-factor-authentication')
            ->assertStatus(422)
            ->assertJsonPath('code', 'step_up_required')
            ->assertJsonPath('message', __('magic-starter::social.step_up_required'))
            ->assertJsonPath('accepts', [
                'code',
                'confirmation_token',
            ]);

        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_a_social_only_user_disables_two_factor_with_a_valid_totp_code(): void
    {
        $user = $this->makeSocialUser(twoFactor: true);

        $this->sendAs($user, 'DELETE', '/two-factor-authentication', [
            'code' => $this->currentCode($user),
        ])->assertOk();

        $fresh = $user->fresh();
        $this->assertNull($fresh->two_factor_confirmed_at);
        $this->assertNull($fresh->two_factor_secret);
    }

    public function test_a_social_only_user_with_a_wrong_totp_code_is_refused(): void
    {
        $user = $this->makeSocialUser(twoFactor: true);

        $this->sendAs($user, 'DELETE', '/two-factor-authentication', [
            'code' => $this->currentCode($user) === '000000' ? '111111' : '000000',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);

        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_a_social_only_user_with_a_used_confirmation_token_must_step_up(): void
    {
        $user = $this->makeSocialUser(twoFactor: true);
        $token = $this->mint($user);
        $this->assertTrue(app(StepUpConfirmations::class)->consume($user, $token));

        $this->sendAs($user, 'DELETE', '/two-factor-authentication', [
            'confirmation_token' => $token,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'step_up_required');

        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_a_confirmation_token_minted_for_another_user_confirms_nothing(): void
    {
        $user = $this->makeSocialUser(twoFactor: true);
        $other = $this->makeSocialUser(twoFactor: false, email: 'other@example.test');

        $this->sendAs($user, 'DELETE', '/two-factor-authentication', [
            'confirmation_token' => $this->mint($other),
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'step_up_required');

        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_a_confirmation_token_is_single_use(): void
    {
        $user = $this->makeSocialUser(twoFactor: true);
        $token = $this->mint($user);

        $this->sendAs($user, 'POST', '/two-factor-recovery-codes/show', [
            'confirmation_token' => $token,
        ])->assertOk();

        $this->sendAs($user, 'POST', '/two-factor-recovery-codes/show', [
            'confirmation_token' => $token,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'step_up_required');
    }

    public function test_recovery_codes_are_gated_by_the_same_step_up(): void
    {
        $user = $this->makeSocialUser(twoFactor: true);

        $this->sendAs($user, 'POST', '/two-factor-recovery-codes/show')
            ->assertStatus(422)
            ->assertJsonPath('code', 'step_up_required')
            ->assertJsonMissingPath('data');

        $this->sendAs($user, 'POST', '/two-factor-recovery-codes')
            ->assertStatus(422)
            ->assertJsonPath('code', 'step_up_required');
        $this->assertSame([
            'old-1',
            'old-2',
        ], $user->fresh()->recoveryCodes());

        $this->sendAs($user, 'POST', '/two-factor-recovery-codes/show', [
            'code' => $this->currentCode($user),
        ])
            ->assertOk()
            ->assertJsonPath('data', [
                'old-1',
                'old-2',
            ]);
    }

    // ---------------------------------------------------------------------
    // A social-only user without 2FA
    // ---------------------------------------------------------------------

    public function test_a_social_only_user_without_two_factor_enables_it_with_a_fresh_token(): void
    {
        $user = $this->makeSocialUser(twoFactor: false);

        $this->sendAs($user, 'POST', '/two-factor-authentication', [
            'confirmation_token' => $this->mint($user),
        ])
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'secret',
                    'recovery_codes',
                ],
            ]);

        $fresh = $user->fresh();
        $this->assertNotNull($fresh->two_factor_secret);
        $this->assertNull($fresh->two_factor_confirmed_at);
    }

    public function test_a_social_only_user_without_two_factor_is_offered_only_a_confirmation_token(): void
    {
        $user = $this->makeSocialUser(twoFactor: false);

        $this->sendAs($user, 'POST', '/two-factor-authentication', [
            'code' => '123456',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'step_up_required')
            ->assertJsonPath('accepts', [
                'confirmation_token',
            ]);

        $this->assertNull($user->fresh()->two_factor_secret);
    }

    public function test_a_confirmation_token_survives_a_request_refused_for_another_reason(): void
    {
        $user = $this->makeSocialUser(twoFactor: false);
        $token = $this->mint($user);

        $this->sendAs($user, 'POST', '/two-factor-authentication', [
            'code' => [
                'not-a-string',
            ],
            'confirmation_token' => $token,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);

        $this->sendAs($user, 'POST', '/two-factor-authentication', [
            'confirmation_token' => $token,
        ])->assertOk();
    }

    // ---------------------------------------------------------------------
    // Account deletion and password change for a social-only user
    // ---------------------------------------------------------------------

    public function test_a_social_only_user_schedules_deletion_with_a_fresh_token(): void
    {
        $user = $this->makeSocialUser(twoFactor: false);

        $this->sendAs($user, 'DELETE', '/user', [
            'confirmation_token' => $this->mint($user),
        ])
            ->assertStatus(202)
            ->assertJsonPath('message', __('magic-starter::social.deletion_scheduled', ['days' => 30]));

        $this->assertNotNull($user->fresh()->deletion_scheduled_at);
    }

    public function test_a_social_only_user_cannot_schedule_deletion_without_proof(): void
    {
        $user = $this->makeSocialUser(twoFactor: false);

        $this->sendAs($user, 'DELETE', '/user')
            ->assertStatus(422)
            ->assertJsonPath('code', 'step_up_required')
            ->assertJsonPath('accepts', [
                'confirmation_token',
            ]);

        $this->assertNull($user->fresh()->deletion_scheduled_at);
    }

    public function test_a_social_only_user_changing_a_password_is_pointed_at_set_password(): void
    {
        $user = $this->makeSocialUser(twoFactor: false);

        $this->sendAs($user, 'PUT', '/user/password', [
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
            'confirmation_token' => $this->mint($user),
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'password_not_set')
            ->assertJsonValidationErrors(['current_password']);

        // The code points a client at set-password; the sentence is for a person.
        $this->sendAs($user, 'PUT', '/user/password', [
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ])
            ->assertJsonPath('code', 'password_not_set')
            ->assertJsonPath('message', __('magic-starter::social.password_not_set'));
        $this->assertNull($user->fresh()->password);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Send the request with a real Sanctum bearer for the user.
     *
     * @param  array<string, mixed>  $data
     */
    private function sendAs(StepUpTestUser $user, string $method, string $uri, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('test')->plainTextToken)->json($method, $uri, $data);
    }

    private function mint(StepUpTestUser $user): string
    {
        return app(StepUpConfirmations::class)->mint($user);
    }

    private function currentCode(StepUpTestUser $user): string
    {
        $provider = app(TwoFactorAuthenticationProvider::class);

        return $provider->engine->getCurrentOtp((string) $user->fresh()->twoFactorSecret());
    }

    private function makePasswordUser(bool $twoFactor): StepUpTestUser
    {
        return StepUpTestUser::query()->create([
            'name' => 'Password User',
            'email' => 'password@example.test',
            'password' => Hash::make(self::PASSWORD),
            ...($twoFactor ? $this->confirmedTwoFactor() : []),
        ]);
    }

    private function makeSocialUser(bool $twoFactor, string $email = 'social@example.test'): StepUpTestUser
    {
        return StepUpTestUser::query()->create([
            'name' => 'Social User',
            'email' => $email,
            'password' => null,
            ...($twoFactor ? $this->confirmedTwoFactor() : []),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function confirmedTwoFactor(): array
    {
        return [
            'two_factor_secret' => encrypt(app(TwoFactorAuthenticationProvider::class)->generateSecretKey()),
            'two_factor_recovery_codes' => encrypt(json_encode([
                'old-1',
                'old-2',
            ])),
            'two_factor_confirmed_at' => now(),
        ];
    }
}

class StepUpTestUser extends Authenticatable
{
    use HasApiTokens;
    use HasSocialAccounts;
    use HasUuids;
    use TwoFactorAuthenticatable;

    protected $table = 'users';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_guest' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return Collection<int, \Illuminate\Database\Eloquent\Model>
     */
    public function allTeams(): Collection
    {
        return new Collection;
    }
}
