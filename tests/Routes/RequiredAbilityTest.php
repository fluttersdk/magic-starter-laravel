<?php

namespace FlutterSdk\MagicStarter\Tests\Routes;

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use UnexpectedValueException;

/**
 * A host that names a required ability gets it on every signed-in route this
 * package owns, and a host that names nothing sees no change.
 *
 * Sanctum issues a token with `*` unless told otherwise, and nothing here
 * checked what a token said it was for, so a token minted for one surface
 * reached every route of another. The gate sits on the `auth:sanctum` groups
 * and not in `route_middleware`, because login, register and the other routes
 * a caller reaches without a token must keep working.
 */
class RequiredAbilityTest extends TestCase
{
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
     * The routes register when the provider boots, so the features and the
     * user model are set before it does.
     *
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('magic-starter.features', [
            Features::teams(),
            Features::sessions(),
            Features::notifications(),
            Features::twoFactorAuthentication(),
            Features::signInHandoff(),
            Features::emailVerification(),
            Features::newsletterSubscription(),
            Features::support(),
            Features::supportCsat(),
            Features::supportFeedback(),
            Features::supportAttachments(),
        ]);
        $app['config']->set('auth.providers.users.model', RequiredAbilityTestUser::class);
        $app['config']->set('magic-starter.models.user', RequiredAbilityTestUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::useUserModel(RequiredAbilityTestUser::class);

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique()->nullable();
            $table->string('password')->nullable();
            $table->timestamp('deletion_scheduled_at')->nullable();
            $table->timestamp('orphaned_at')->nullable();
            $table->boolean('is_guest')->default(false);
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

    public function test_a_host_that_names_no_ability_changes_nothing(): void
    {
        $token = $this->makeUser()->createToken('reader', ['creator'])->plainTextToken;

        $this->withToken($token)->getJson('/auth/user')->assertOk();
    }

    public function test_an_empty_ability_is_the_same_as_none(): void
    {
        config(['magic-starter.required_ability' => '']);

        $token = $this->makeUser()->createToken('reader', ['creator'])->plainTextToken;

        $this->withToken($token)->getJson('/auth/user')->assertOk();
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function valuesThatAreNotAnAbility(): array
    {
        return [
            'true from an env value' => [true],
            'an array' => [['app']],
            'an integer' => [1],
        ];
    }

    #[DataProvider('valuesThatAreNotAnAbility')]
    public function test_a_value_that_is_not_a_string_stops_the_request_instead_of_opening_it(mixed $value): void
    {
        config(['magic-starter.required_ability' => $value]);

        $token = $this->makeUser()->createToken('creator', ['creator'])->plainTextToken;

        $this->withoutExceptionHandling();
        $this->expectException(UnexpectedValueException::class);

        $this->withToken($token)->getJson('/auth/user');
    }

    public function test_a_token_without_the_ability_is_refused(): void
    {
        config(['magic-starter.required_ability' => 'app']);

        $token = $this->makeUser()->createToken('creator', ['creator'])->plainTextToken;

        $this->withToken($token)->getJson('/auth/user')
            ->assertForbidden()
            ->assertJsonPath('code', 'missing_ability');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function signedInRoutes(): array
    {
        return [
            'user' => ['GET', '/auth/user'],
            'logout' => ['POST', '/auth/logout'],
            'teams' => ['GET', '/teams'],
            'team create' => ['POST', '/teams'],
            'current team' => ['PUT', '/user/current-team'],
            'profile' => ['PUT', '/user/profile'],
            'sessions' => ['GET', '/sessions'],
            'notifications' => ['GET', '/notifications'],
            'two factor' => ['POST', '/two-factor-authentication'],
            'handoff mint' => ['POST', '/auth/handoff'],
            'verification mail' => ['POST', '/email/verification-notification'],
            'newsletter' => ['GET', '/user/newsletter'],
            'support desk' => ['GET', '/support/conversations'],
        ];
    }

    #[DataProvider('signedInRoutes')]
    public function test_a_refused_token_reaches_no_signed_in_route(string $method, string $uri): void
    {
        config(['magic-starter.required_ability' => 'app']);

        $token = $this->makeUser()->createToken('creator', ['creator'])->plainTextToken;

        $this->withToken($token)->json($method, $uri)
            ->assertForbidden()
            ->assertJsonPath('code', 'missing_ability');
    }

    public function test_a_token_with_the_ability_is_admitted(): void
    {
        config(['magic-starter.required_ability' => 'app']);

        $token = $this->makeUser()->createToken('app', ['app'])->plainTextToken;

        $this->withToken($token)->getJson('/auth/user')->assertOk();
    }

    public function test_a_wildcard_token_is_admitted(): void
    {
        config(['magic-starter.required_ability' => 'app']);

        $token = $this->makeUser()->createToken('auth_token')->plainTextToken;

        $this->withToken($token)->getJson('/auth/user')->assertOk();
    }

    public function test_a_token_with_several_abilities_needs_only_the_named_one(): void
    {
        config(['magic-starter.required_ability' => 'app']);

        $token = $this->makeUser()->createToken('both', ['creator', 'app'])->plainTextToken;

        $this->withToken($token)->getJson('/auth/user')->assertOk();
    }

    /**
     * A row from before abilities existed has a NULL column, and Sanctum's own
     * `can()` throws on it. It predates scoping, so it keeps full access.
     */
    public function test_a_legacy_token_with_null_abilities_is_admitted(): void
    {
        config(['magic-starter.required_ability' => 'app']);

        $user = $this->makeUser();
        $plain = $user->createToken('legacy')->plainTextToken;
        DB::table('personal_access_tokens')->update(['abilities' => null]);

        $this->withToken($plain)->getJson('/auth/user')->assertOk();
    }

    public function test_a_session_authenticated_first_party_caller_is_admitted(): void
    {
        config(['magic-starter.required_ability' => 'app']);

        $this->actingAs($this->makeUser(), 'web');

        $this->getJson('/auth/user')->assertOk();
    }

    public function test_acting_as_with_the_ability_is_admitted_and_without_is_refused(): void
    {
        config(['magic-starter.required_ability' => 'app']);

        Sanctum::actingAs($this->makeUser(), ['app']);
        $this->getJson('/auth/user')->assertOk();

        Sanctum::actingAs($this->makeUser(), ['creator']);
        $this->getJson('/auth/user')->assertForbidden();
    }

    public function test_a_request_with_no_token_is_answered_unauthenticated_not_forbidden(): void
    {
        config(['magic-starter.required_ability' => 'app']);

        $this->getJson('/auth/user')->assertUnauthorized();
    }

    public function test_login_and_register_still_work_with_no_token(): void
    {
        config(['magic-starter.required_ability' => 'app']);

        $user = $this->makeUser();

        // Validation answers 422, so neither request was stopped by the gate or by authentication.
        $this->postJson('/auth/register', [])->assertUnprocessable();
        $this->postJson('/auth/login', [])->assertUnprocessable();

        $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'A-long-Password-1'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token']]);
    }

    private function makeUser(): RequiredAbilityTestUser
    {
        return RequiredAbilityTestUser::query()->create([
            'name' => 'Ada',
            'email' => 'ada' . uniqid() . '@example.test',
            'password' => bcrypt('A-long-Password-1'),
        ]);
    }
}

class RequiredAbilityTestUser extends Authenticatable
{
    use HasApiTokens;
    use HasUuids;

    protected $table = 'users';

    protected $guarded = [];
}
