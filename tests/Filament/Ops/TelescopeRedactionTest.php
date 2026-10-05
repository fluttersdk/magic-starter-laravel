<?php

namespace FlutterSdk\MagicStarter\Tests\Filament\Ops;

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Filament\Ops\TelescopeRedaction;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Models\Team;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeamInvitation;
use FlutterSdk\MagicStarter\Tests\TestCase;
use FlutterSdk\MagicStarter\Traits\HasTeams;
use FlutterSdk\MagicStarter\Traits\TwoFactorAuthenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeServiceProvider;
use RuntimeException;

/**
 * What Telescope keeps of a request once the plugin has configured it.
 *
 * Driven through the package's own routes, so the masks are checked against
 * the response shapes a client actually receives: the API wraps its secrets
 * under `data`, and a mask on a path the response does not have masks nothing.
 * Each secret must read `********`; a field the rules do not list must survive
 * in clear text, or a redaction that blanked the whole payload would pass.
 */
class TelescopeRedactionTest extends TestCase
{
    private const PASSWORD = 'Password123';

    /**
     * Telescope's rules are statics that outlive this application.
     *
     * @var array<string, mixed>
     */
    private array $statics = [];

    protected function setUp(): void
    {
        if (! class_exists(Telescope::class)) {
            $this->markTestSkipped('laravel/telescope is not installed.');
        }

        $this->statics = [
            'filter' => Telescope::$filterUsing,
            'filterBatch' => Telescope::$filterBatchUsing,
            'requestParameters' => Telescope::$hiddenRequestParameters,
            'responseParameters' => Telescope::$hiddenResponseParameters,
            'requestHeaders' => Telescope::$hiddenRequestHeaders,
        ];

        parent::setUp();

        MagicStarter::useUserModel(TelescopeRedactionTestUser::class);
        MagicStarter::useTeamModel(TelescopeRedactionTestTeam::class);

        foreach (glob(__DIR__ . '/../../../vendor/laravel/telescope/database/migrations/*.php') as $file) {
            (require $file)->up();
        }

        foreach ([
            'create_teams_table',
            'create_team_user_table',
            'create_team_invitations_table',
            'add_expires_at_to_team_invitations_table',
        ] as $migration) {
            (require __DIR__ . "/../../../database/migrations/{$migration}.php")->up();
        }

        Schema::create('users', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamp('deletion_scheduled_at')->nullable();
            $table->string('current_team_id')->nullable();
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', static function (Blueprint $table): void {
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

        Route::post('/probe', static fn () => response()->json([
            'user' => 'ops',
        ]));

        Route::get('/explode', static function (): never {
            throw new RuntimeException('Probe failure.');
        });
    }

    protected function tearDown(): void
    {
        Telescope::stopRecording();
        Telescope::flushEntries();
        MagicStarter::reset();

        parent::tearDown();

        Telescope::$filterUsing = $this->statics['filter'];
        Telescope::$filterBatchUsing = $this->statics['filterBatch'];
        Telescope::$hiddenRequestParameters = $this->statics['requestParameters'];
        Telescope::$hiddenResponseParameters = $this->statics['responseParameters'];
        Telescope::$hiddenRequestHeaders = $this->statics['requestHeaders'];
    }

    public function test_a_real_sign_in_shows_its_issued_token_masked(): void
    {
        TelescopeRedaction::hideSecrets();
        $this->user();

        $this->postJson('/auth/login', [
            'email' => 'ops@example.com',
            'password' => self::PASSWORD,
        ])->assertOk()->assertJsonPath('data.user.email', 'ops@example.com');

        $content = $this->recordedRequest();

        $this->assertSame('********', $content['response']['data']['token']);
        $this->assertSame('ops@example.com', $content['response']['data']['user']['email']);
        $this->assertSame('********', $content['payload']['password']);
        $this->assertSame('ops@example.com', $content['payload']['email']);
    }

    public function test_a_sign_in_that_meets_a_second_factor_shows_its_challenge_token_masked(): void
    {
        TelescopeRedaction::hideSecrets();
        $this->user()->forceFill([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->postJson('/auth/login', [
            'email' => 'ops@example.com',
            'password' => self::PASSWORD,
        ])->assertOk()->assertJsonPath('two_factor', true);

        $content = $this->recordedRequest();

        $this->assertSame('********', $content['response']['two_factor_token']);
        $this->assertTrue($content['response']['two_factor']);
    }

    public function test_enabling_two_factor_shows_the_enrolment_secrets_masked(): void
    {
        TelescopeRedaction::hideSecrets();
        Sanctum::actingAs($this->user());

        $this->postJson('/two-factor-authentication', [
            'password' => self::PASSWORD,
        ])->assertOk()->assertJsonStructure([
            'data' => [
                'secret',
                'qr_url',
                'qr_svg',
                'recovery_codes',
            ],
        ]);

        $content = $this->recordedRequest();

        $this->assertSame('********', $content['response']['data']['secret']);
        $this->assertSame('********', $content['response']['data']['qr_url']);
        $this->assertSame('********', $content['response']['data']['qr_svg']);
        $this->assertSame('********', $content['response']['data']['recovery_codes']);
        $this->assertSame(__('magic-starter::auth.two_factor.enabled'), $content['response']['message']);
    }

    /**
     * The codes are answered as a bare list under `data`, which no dot path can
     * mask, so the entry is not kept at all.
     */
    public function test_a_recovery_codes_response_is_not_recorded(): void
    {
        TelescopeRedaction::hideSecrets();
        $user = $this->user();
        $user->forceFill([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['first-code', 'second-code'])),
            'two_factor_confirmed_at' => now(),
        ])->save();
        Sanctum::actingAs($user);

        $this->postJson('/two-factor-recovery-codes/show', [
            'password' => self::PASSWORD,
        ])->assertOk()->assertJsonPath('data', ['first-code', 'second-code']);

        $this->postJson('/two-factor-recovery-codes', [
            'password' => self::PASSWORD,
        ])->assertOk();

        $this->assertSame(0, DB::table('telescope_entries')->where('type', 'request')->count());
    }

    /**
     * Each invitation on the page carries its acceptance token, at a path that
     * moves with the page, so the entry is not kept at all.
     */
    public function test_an_invitation_list_is_not_recorded(): void
    {
        TelescopeRedaction::hideSecrets();
        $owner = $this->user();
        $team = TelescopeRedactionTestTeam::query()->create([
            'name' => 'Acme',
            'user_id' => $owner->getKey(),
            'personal_team' => false,
        ]);
        (new ConcreteTeamInvitation)->forceFill([
            'team_id' => $team->getKey(),
            'email' => 'invitee@example.com',
            'role' => 'editor',
            'token' => 'acceptance-token',
        ])->save();
        Sanctum::actingAs($owner);

        $this->getJson("/teams/{$team->getKey()}/invitations")
            ->assertOk()
            ->assertJsonPath('data.0.token', 'acceptance-token');

        $this->assertSame(0, DB::table('telescope_entries')->where('type', 'request')->count());
    }

    public function test_the_request_secrets_the_api_receives_are_masked(): void
    {
        TelescopeRedaction::hideSecrets();

        $this->withHeaders([
            'Authorization' => 'Bearer live-token',
            'X-XSRF-TOKEN' => 'xsrf-value',
        ])->postJson('/probe', [
            'name' => 'Ops',
            'token' => 'push-or-reset-token',
            'id_token' => 'google-id-token',
            'nonce' => 'apple-raw-nonce',
            'ticket' => 'link-ticket',
            'code' => '123456',
            'code_verifier' => 'pkce-verifier',
            'recovery_code' => 'abcd-efgh',
            'current_password' => 'old-secret',
        ])->assertOk();

        $content = $this->recordedRequest();

        $this->assertSame('Ops', $content['payload']['name']);
        $this->assertSame('********', $content['payload']['id_token']);
        $this->assertSame('********', $content['payload']['nonce']);
        $this->assertSame('********', $content['payload']['ticket']);
        $this->assertSame('********', $content['payload']['token']);
        $this->assertSame('********', $content['payload']['code']);
        $this->assertSame('********', $content['payload']['code_verifier']);
        $this->assertSame('********', $content['payload']['recovery_code']);
        $this->assertSame('********', $content['payload']['current_password']);
        $this->assertSame('ops', $content['response']['user']);
        $this->assertSame('********', $content['headers']['authorization']);
        $this->assertSame('********', $content['headers']['x-xsrf-token']);
    }

    public function test_outside_local_an_unremarkable_batch_is_dropped(): void
    {
        TelescopeRedaction::hideSecrets();
        TelescopeRedaction::keepNoteworthyBatches();

        $this->postJson('/probe', [
            'name' => 'Ops',
        ])->assertOk();

        $this->assertSame(0, DB::table('telescope_entries')->count());
    }

    public function test_outside_local_a_batch_with_a_reportable_exception_is_kept(): void
    {
        TelescopeRedaction::hideSecrets();
        TelescopeRedaction::keepNoteworthyBatches();

        $this->get('/explode')->assertServerError();

        $this->assertSame(1, DB::table('telescope_entries')->where('type', 'exception')->count());
        $this->assertSame(1, DB::table('telescope_entries')->where('type', 'request')->count());
    }

    protected function getPackageProviders($app): array
    {
        return [
            SanctumServiceProvider::class,
            MagicStarterServiceProvider::class,
            TelescopeServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The 2FA secret, recovery codes and challenge token are encrypted.
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('magic-starter.features', [
            Features::twoFactorAuthentication(),
            Features::teams(),
        ]);
        $app['config']->set('auth.providers.users.model', TelescopeRedactionTestUser::class);
        $app['config']->set('telescope.storage.database.connection', 'testing');
    }

    private function user(): TelescopeRedactionTestUser
    {
        return TelescopeRedactionTestUser::query()->create([
            'name' => 'Ops',
            'email' => 'ops@example.com',
            'password' => Hash::make(self::PASSWORD),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function recordedRequest(): array
    {
        $entry = DB::table('telescope_entries')->where('type', 'request')->sole();

        return json_decode($entry->content, true);
    }
}

class TelescopeRedactionTestUser extends Authenticatable
{
    use HasApiTokens;
    use HasTeams;
    use HasUuids;
    use TwoFactorAuthenticatable;

    protected $table = 'users';

    protected $guarded = [];
}

class TelescopeRedactionTestTeam extends Team
{
    protected $table = 'teams';

    protected $guarded = [];

    /**
     * @return HasMany<ConcreteTeamInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(ConcreteTeamInvitation::class, 'team_id');
    }
}
