<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use Firebase\JWT\JWT;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Models\SocialAccount;
use FlutterSdk\MagicStarter\Tests\TestCase;
use FlutterSdk\MagicStarter\Traits\HasSocialAccounts;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\SanctumServiceProvider;
use OpenSSLAsymmetricKey;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Drives Apple's server-to-server notification endpoint with locally signed
 * ES256 payloads, the key set served through `Http::fake`.
 *
 * Every case asserts the rows and the token counts, not only the status: a
 * forged payload that still signs somebody out is the failure this suite
 * exists to catch, and so is a verified one that answers 400 and makes Apple
 * retry forever.
 */
class AppleNotificationTest extends TestCase
{
    private const APPLE_KEYS = 'https://appleid.apple.com/auth/keys';

    private const ENDPOINT = '/magic-starter/social/apple/notifications';

    private const AUDIENCE = 'com.example.web';

    private OpenSSLAsymmetricKey $signingKey;

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
     * The routes are gated on the social feature when the provider boots.
     *
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The Apple refresh token is an encrypted cast.
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('magic-starter.features', [
            Features::socialLogin(),
        ]);
        $app['config']->set('auth.providers.users.model', AppleNotificationTestUser::class);
        $app['config']->set('magic-starter.models.user', AppleNotificationTestUser::class);
        $app['config']->set('magic-starter.route_prefix', 'api/v1');
    }

    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::useUserModel(AppleNotificationTestUser::class);
        Http::preventStrayRequests();

        $this->signingKey = $this->ecKey();

        config([
            'magic-starter.social.audiences.apple' => [
                'com.example.app',
                self::AUDIENCE,
            ],
            'magic-starter.social.cache_store' => null,
        ]);

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique()->nullable();
            $table->string('password')->nullable();
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
            $table->timestamps();
        });

        (require __DIR__ . '/../../../database/migrations/add_deletion_columns_to_users_table.php')->up();
        (require __DIR__ . '/../../../database/migrations/create_social_accounts_table.php')->up();
    }

    protected function tearDown(): void
    {
        MagicStarter::reset();

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Refusals
    // ---------------------------------------------------------------------

    public function test_a_payload_with_a_bad_signature_is_refused_without_an_effect(): void
    {
        $this->fakeAppleKeys();
        [$user, $account] = $this->appleUser();

        $this->notify($this->payload('consent-revoked', key: $this->ecKey()))->assertStatus(400);

        $this->assertNull($account->fresh()?->revoked_at);
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_a_payload_for_a_foreign_audience_is_refused_without_an_effect(): void
    {
        $this->fakeAppleKeys();
        [$user, $account] = $this->appleUser();

        $this->notify($this->payload('consent-revoked', ['aud' => 'com.attacker.app']))->assertStatus(400);

        $this->assertNull($account->fresh()?->revoked_at);
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_a_payload_from_a_foreign_issuer_is_refused(): void
    {
        $this->fakeAppleKeys();
        [$user] = $this->appleUser();

        $this->notify($this->payload('consent-revoked', ['iss' => 'https://evil.example']))->assertStatus(400);

        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_no_configured_audience_refuses_every_payload(): void
    {
        config(['magic-starter.social.audiences.apple' => []]);
        $this->fakeAppleKeys();
        [$user] = $this->appleUser();

        $this->notify($this->payload('consent-revoked'))->assertStatus(400);

        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_a_request_without_a_payload_is_refused(): void
    {
        $this->fakeAppleKeys();

        $this->postJson(self::ENDPOINT, [])->assertStatus(400);
        $this->postJson(self::ENDPOINT, ['payload' => 'not.a.jwt'])->assertStatus(400);
    }

    // ---------------------------------------------------------------------
    // Events
    // ---------------------------------------------------------------------

    public function test_consent_revoked_revokes_the_account_and_every_token(): void
    {
        $this->fakeAppleKeys();
        [$user, $account] = $this->appleUser();

        $this->notify($this->payload('consent-revoked'))->assertOk();

        $account = $account->fresh();
        $this->assertNotNull($account?->revoked_at);
        $this->assertNull($account->refresh_token);
        $this->assertSame(0, $user->tokens()->count());
        // A revoked consent is not a deleted account: the user stays and nothing is scheduled.
        $this->assertNull($user->fresh()?->getAttribute('deletion_scheduled_at'));
    }

    public function test_events_sent_as_a_json_string_are_read_the_same_way(): void
    {
        $this->fakeAppleKeys();
        [$user, $account] = $this->appleUser();

        $this->notify($this->payload('consent-revoked', eventsAsString: true))->assertOk();

        $this->assertNotNull($account->fresh()?->revoked_at);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_a_replayed_payload_answers_ok_without_a_second_effect(): void
    {
        $this->fakeAppleKeys();
        [$user, $account] = $this->appleUser();
        $payload = $this->payload('consent-revoked');

        $this->notify($payload)->assertOk();

        // The user signs in again: the row is reactivated and a new token issued.
        $account->refresh()->forceFill(['revoked_at' => null])->save();
        $user->createToken('again');

        $this->notify($payload)->assertOk();

        $this->assertNull($account->fresh()?->revoked_at);
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_account_deleted_for_an_apple_only_passwordless_user_schedules_an_orphan_deletion(): void
    {
        $this->fakeAppleKeys();
        [$user, $account] = $this->appleUser(password: null);

        $this->notify($this->payload('account-deleted'))->assertOk();

        $user = $user->fresh();
        $this->assertNotNull($user, 'The user is never deleted synchronously.');
        $this->assertNotNull($user->getAttribute('deletion_scheduled_at'));
        $this->assertNotNull($user->getAttribute('orphaned_at'));
        $this->assertNotNull($account->fresh()?->revoked_at);
        $this->assertNull($account->fresh()?->refresh_token);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_account_deleted_for_a_user_with_a_password_only_revokes(): void
    {
        $this->fakeAppleKeys();
        [$user, $account] = $this->appleUser(password: 'Password123');

        $this->notify($this->payload('account-deleted'))->assertOk();

        $user = $user->fresh();
        $this->assertNotNull($user);
        $this->assertNull($user->getAttribute('deletion_scheduled_at'));
        $this->assertNull($user->getAttribute('orphaned_at'));
        $this->assertNotNull($account->fresh()?->revoked_at);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_account_deleted_for_a_user_with_another_active_account_only_revokes(): void
    {
        $this->fakeAppleKeys();
        [$user, $account] = $this->appleUser(password: null);
        $this->link($user, 'google', 'google-sub-1');

        $this->notify($this->payload('account-deleted'))->assertOk();

        $this->assertNull($user->fresh()?->getAttribute('deletion_scheduled_at'));
        $this->assertNotNull($account->fresh()?->revoked_at);
    }

    public function test_account_deleted_ignores_another_account_that_is_itself_revoked(): void
    {
        $this->fakeAppleKeys();
        [$user] = $this->appleUser(password: null);
        $this->link($user, 'google', 'google-sub-1', revoked: true);

        $this->notify($this->payload('account-deleted'))->assertOk();

        $this->assertNotNull($user->fresh()?->getAttribute('orphaned_at'));
    }

    public function test_email_events_update_the_address_at_link(): void
    {
        $this->fakeAppleKeys();
        [$user, $account] = $this->appleUser();

        $this->notify($this->payload('email-disabled', email: 'relay-2@privaterelay.appleid.com'))
            ->assertOk();

        $this->assertSame('relay-2@privaterelay.appleid.com', $account->fresh()?->email_at_link);
        $this->assertNull($account->fresh()?->revoked_at);
        $this->assertSame(1, $user->tokens()->count());

        $this->notify($this->payload('email-enabled', email: 'relay-3@privaterelay.appleid.com'))
            ->assertOk();

        $this->assertSame('relay-3@privaterelay.appleid.com', $account->fresh()?->email_at_link);
    }

    public function test_an_unknown_subject_answers_ok_and_changes_nothing(): void
    {
        $this->fakeAppleKeys();
        [$user, $account] = $this->appleUser();

        $this->notify($this->payload('account-deleted', subject: 'apple-sub-unknown'))->assertOk();

        $this->assertNull($account->fresh()?->revoked_at);
        $this->assertSame(1, $user->tokens()->count());
        $this->assertNull($user->fresh()?->getAttribute('deletion_scheduled_at'));
    }

    public function test_an_unknown_event_type_answers_ok_and_changes_nothing(): void
    {
        $this->fakeAppleKeys();
        [$user, $account] = $this->appleUser();

        $this->notify($this->payload('something-new'))->assertOk();

        $this->assertNull($account->fresh()?->revoked_at);
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_the_key_set_is_cached_across_notifications(): void
    {
        $this->fakeAppleKeys();
        $this->appleUser();

        $this->notify($this->payload('email-enabled'))->assertOk();
        $this->notify($this->payload('email-enabled'))->assertOk();

        Http::assertSentCount(1);
    }

    // ---------------------------------------------------------------------
    // Route
    // ---------------------------------------------------------------------

    public function test_the_route_answers_on_its_fixed_path_without_the_prefix_or_auth(): void
    {
        $this->fakeAppleKeys();
        [$user, $account] = $this->appleUser();

        $this->postJson('/api/v1' . self::ENDPOINT, [
            'payload' => $this->payload('consent-revoked'),
        ])->assertNotFound();

        // No bearer, no session, no CSRF token: the signature is the only credential.
        $this->notify($this->payload('consent-revoked'))->assertOk();

        $this->assertNotNull($account->fresh()?->revoked_at);
        $this->assertSame(0, $user->tokens()->count());
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * @return TestResponse<HttpResponse>
     */
    private function notify(string $payload): TestResponse
    {
        return $this->post(self::ENDPOINT, [
            'payload' => $payload,
        ]);
    }

    /**
     * A user linked to Apple as `apple-sub-1` with a stored refresh token and one Sanctum token.
     *
     * @return array{0: AppleNotificationTestUser, 1: SocialAccount}
     */
    private function appleUser(?string $password = 'Password123'): array
    {
        $user = AppleNotificationTestUser::query()->create([
            'name' => 'Jane',
            'email' => 'jane@privaterelay.appleid.com',
            'password' => $password === null ? null : Hash::make($password),
        ]);
        $user->createToken('device');

        $account = $this->link($user, 'apple', 'apple-sub-1');
        $account->forceFill([
            'email_at_link' => 'jane@privaterelay.appleid.com',
            'client_id' => 'com.example.app',
            'refresh_token' => 'apple-refresh-1',
        ])->save();

        return [
            $user,
            $account,
        ];
    }

    private function link(
        AppleNotificationTestUser $user,
        string $provider,
        string $providerUserId,
        bool $revoked = false,
    ): SocialAccount {
        return SocialAccount::query()->create([
            'user_id' => $user->getKey(),
            'provider' => $provider,
            'provider_user_id' => $providerUserId,
            'revoked_at' => $revoked ? now() : null,
        ]);
    }

    private function ecKey(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);

        $this->assertInstanceOf(OpenSSLAsymmetricKey::class, $key);

        return $key;
    }

    private function fakeAppleKeys(): void
    {
        $details = openssl_pkey_get_details($this->signingKey);

        $this->assertIsArray($details);

        // OpenSSL drops leading zero bytes; a P-256 coordinate is always 32 bytes.
        $coordinate = fn (string $bytes): string => JWT::urlsafeB64Encode(str_pad($bytes, 32, "\0", STR_PAD_LEFT));

        Http::fake([
            self::APPLE_KEYS => Http::response([
                'keys' => [
                    [
                        'kty' => 'EC',
                        'crv' => 'P-256',
                        'alg' => 'ES256',
                        'use' => 'sig',
                        'kid' => 'apple-key',
                        'x' => $coordinate($details['ec']['x']),
                        'y' => $coordinate($details['ec']['y']),
                    ],
                ],
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides  top-level claims to replace
     */
    private function payload(
        string $type,
        array $overrides = [],
        ?OpenSSLAsymmetricKey $key = null,
        bool $eventsAsString = false,
        ?string $email = 'jane@privaterelay.appleid.com',
        string $subject = 'apple-sub-1',
    ): string {
        $events = array_filter([
            'type' => $type,
            'sub' => $subject,
            'event_time' => (time() - 5) * 1000,
            'email' => in_array($type, ['email-enabled', 'email-disabled'], true) ? $email : null,
            'is_private_email' => in_array($type, ['email-enabled', 'email-disabled'], true) ? 'true' : null,
        ], fn (mixed $value): bool => $value !== null);

        return JWT::encode([
            'iss' => 'https://appleid.apple.com',
            'aud' => self::AUDIENCE,
            'iat' => time() - 5,
            'jti' => (string) Str::uuid(),
            'events' => $eventsAsString ? (string) json_encode($events) : $events,
            ...$overrides,
        ], $key ?? $this->signingKey, 'ES256', 'apple-key');
    }
}

/**
 * @property string $id
 * @property string|null $email
 */
class AppleNotificationTestUser extends Authenticatable
{
    use HasApiTokens;
    use HasSocialAccounts;
    use HasUuids;

    protected $table = 'users';

    protected $guarded = [];
}
