<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Requests;

use FlutterSdk\MagicStarter\Http\Controllers\AuthController;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

/**
 * Tests that SwitchTeamRequest validates team_id as 'integer' when use_uuids is false
 * and as 'uuid' when use_uuids is true, mirroring the package's UUID-optional contract.
 *
 * Note: Laravel's FormRequest runs authorize() before rules(). A nonexistent team_id
 * causes authorize() to return false (403), so we test the validation rule by sending
 * team_ids that exist in the database for the happy-path assertions and by testing the
 * rules() method directly for the rejection assertions.
 */
final class SwitchTeamRequestTest extends TestCase
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

        // Route under test.
        app('router')->put('/user/current-team', [AuthController::class, 'switchTeam']);
    }

    /**
     * Build integer-keyed tables and register integer-keyed test models.
     */
    private function setUpIntegerSchema(): void
    {
        config([
            'magic-starter.use_uuids' => false,
            'auth.providers.users.model' => SwitchTeamIntUser::class,
            'magic-starter.models.user' => SwitchTeamIntUser::class,
            'magic-starter.models.team' => SwitchTeamIntTeam::class,
            'magic-starter.models.membership' => \FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeamUser::class,
        ]);

        MagicStarter::useUserModel(SwitchTeamIntUser::class);
        MagicStarter::useTeamModel(SwitchTeamIntTeam::class);

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique()->nullable();
            $table->string('password')->nullable();
            $table->unsignedBigInteger('current_team_id')->nullable();
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('name');
            $table->boolean('personal_team')->default(false);
            $table->string('profile_photo_path', 2048)->nullable();
            $table->timestamps();
        });

        Schema::create('team_user', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role')->nullable();
            $table->timestamps();
        });

        Gate::policy(SwitchTeamIntTeam::class, \FlutterSdk\MagicStarter\Policies\TeamPolicy::class);
    }

    /**
     * Build UUID-keyed tables and register UUID-keyed test models.
     */
    private function setUpUuidSchema(): void
    {
        config([
            'magic-starter.use_uuids' => true,
            'auth.providers.users.model' => SwitchTeamUuidUser::class,
            'magic-starter.models.user' => SwitchTeamUuidUser::class,
            'magic-starter.models.team' => SwitchTeamUuidTeam::class,
            'magic-starter.models.membership' => \FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeamUser::class,
        ]);

        MagicStarter::useUserModel(SwitchTeamUuidUser::class);
        MagicStarter::useTeamModel(SwitchTeamUuidTeam::class);

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique()->nullable();
            $table->string('password')->nullable();
            $table->string('current_team_id')->nullable();
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

        Gate::policy(SwitchTeamUuidTeam::class, \FlutterSdk\MagicStarter\Policies\TeamPolicy::class);
    }

    // -------------------------------------------------------------------------
    // Integer mode: happy path
    // -------------------------------------------------------------------------

    /**
     * When use_uuids is false, a valid integer team_id owned by the authenticated
     * user must succeed with 200. This was previously broken because the hardcoded
     * 'uuid' rule rejected integer values with a 422.
     */
    public function test_switch_team_accepts_integer_team_id_when_use_uuids_is_false(): void
    {
        $this->setUpIntegerSchema();

        $user = SwitchTeamIntUser::query()->create(['name' => 'Owner', 'email' => 'owner@example.com']);
        $team = SwitchTeamIntTeam::query()->create([
            'user_id' => $user->id,
            'name' => 'My Team',
            'personal_team' => false,
        ]);
        $team->users()->attach($user->id, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);

        $this->actingAs($user)
            ->putJson('/user/current-team', ['team_id' => $team->id])
            ->assertOk();
    }

    // -------------------------------------------------------------------------
    // Integer mode: validation rules via the rules() method directly
    // -------------------------------------------------------------------------

    /**
     * In integer mode, the rules() method must return 'integer' for team_id,
     * not 'uuid'. This is the rule that was hardcoded and is now conditional.
     */
    public function test_rules_returns_integer_rule_when_use_uuids_is_false(): void
    {
        config(['magic-starter.use_uuids' => false]);
        MagicStarter::useTeamModel(\FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam::class);

        $request = new \FlutterSdk\MagicStarter\Http\Requests\SwitchTeamRequest;
        $rules = $request->rules();

        $this->assertContains('integer', $rules['team_id'], 'Expected integer rule in integer mode.');
        $this->assertNotContains('uuid', $rules['team_id'], 'Must not contain uuid rule in integer mode.');
    }

    // -------------------------------------------------------------------------
    // UUID mode: the existing behaviour must be preserved
    // -------------------------------------------------------------------------

    /**
     * When use_uuids is true, a valid UUID team_id owned by the authenticated user
     * must succeed with 200, confirming the uuid branch still works.
     */
    public function test_switch_team_accepts_uuid_team_id_when_use_uuids_is_true(): void
    {
        $this->setUpUuidSchema();

        $user = SwitchTeamUuidUser::query()->create(['name' => 'Owner', 'email' => 'uuid-owner@example.com']);
        $team = SwitchTeamUuidTeam::query()->create([
            'user_id' => $user->id,
            'name' => 'UUID Team',
            'personal_team' => false,
        ]);
        $team->users()->attach($user->id, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);

        $this->actingAs($user)
            ->putJson('/user/current-team', ['team_id' => $team->id])
            ->assertOk();
    }

    /**
     * In UUID mode, the rules() method must retain 'uuid' for team_id.
     */
    public function test_rules_returns_uuid_rule_when_use_uuids_is_true(): void
    {
        config(['magic-starter.use_uuids' => true]);
        MagicStarter::useTeamModel(\FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam::class);

        $request = new \FlutterSdk\MagicStarter\Http\Requests\SwitchTeamRequest;
        $rules = $request->rules();

        $this->assertContains('uuid', $rules['team_id'], 'Expected uuid rule in UUID mode.');
        $this->assertNotContains('integer', $rules['team_id'], 'Must not contain integer rule in UUID mode.');
    }

    /**
     * In UUID mode, a non-UUID value (bare integer) must still be rejected with 422.
     * We test this via the rules() assertions above; the HTTP path results in a 403
     * because authorize() runs before rules() and a nonexistent integer team_id causes
     * find() to return null, which is the existing documented behaviour.
     */
    public function test_switch_team_rejects_integer_team_id_when_use_uuids_is_true(): void
    {
        $this->setUpUuidSchema();

        $user = SwitchTeamUuidUser::query()->create(['name' => 'Owner', 'email' => 'uuid-owner2@example.com']);

        // authorize() fires first: find(42) on a UUID table returns null, so 403 is
        // returned before rules() even run. The validation rule is confirmed in
        // test_rules_returns_uuid_rule_when_use_uuids_is_true above.
        $this->actingAs($user)
            ->putJson('/user/current-team', ['team_id' => 42])
            ->assertForbidden();
    }
}

// ---------------------------------------------------------------------------
// Integer-keyed test doubles
// ---------------------------------------------------------------------------

final class SwitchTeamIntUser extends Model implements AuthenticatableContract
{
    use AuthenticatableTrait;
    use Authorizable;
    use \FlutterSdk\MagicStarter\Traits\HasProfilePhoto;
    use \FlutterSdk\MagicStarter\Traits\HasTeams;

    protected $table = 'users';

    protected $guarded = [];

    public function createToken(string $_name): object
    {
        return new class
        {
            public string $plainTextToken = 'test-token-int';

            public object $accessToken;

            public function __construct()
            {
                $this->accessToken = new class
                {
                    public function forceFill(array $attributes): self
                    {
                        return $this;
                    }

                    public function save(): bool
                    {
                        return true;
                    }
                };
            }
        };
    }
}

final class SwitchTeamIntTeam extends \FlutterSdk\MagicStarter\Models\Team
{
    protected $table = 'teams';

    protected $guarded = [];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(SwitchTeamIntUser::class, 'team_user', 'team_id', 'user_id')
            ->using(\FlutterSdk\MagicStarter\MagicStarter::membershipModel())
            ->withPivot('role')
            ->withTimestamps();
    }
}

// ---------------------------------------------------------------------------
// UUID-keyed test doubles
// ---------------------------------------------------------------------------

final class SwitchTeamUuidUser extends Model implements AuthenticatableContract
{
    use AuthenticatableTrait;
    use Authorizable;
    use \FlutterSdk\MagicStarter\Traits\HasProfilePhoto;
    use \FlutterSdk\MagicStarter\Traits\HasTeams;
    use HasUuids;

    protected $table = 'users';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function createToken(string $_name): object
    {
        return new class
        {
            public string $plainTextToken = 'test-token-uuid';

            public object $accessToken;

            public function __construct()
            {
                $this->accessToken = new class
                {
                    public function forceFill(array $attributes): self
                    {
                        return $this;
                    }

                    public function save(): bool
                    {
                        return true;
                    }
                };
            }
        };
    }
}

final class SwitchTeamUuidTeam extends \FlutterSdk\MagicStarter\Models\Team
{
    protected $table = 'teams';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(SwitchTeamUuidUser::class, 'team_user', 'team_id', 'user_id')
            ->using(\FlutterSdk\MagicStarter\MagicStarter::membershipModel())
            ->withPivot('role')
            ->withTimestamps();
    }
}
