<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Contracts\UpdatesUserPasswords;
use FlutterSdk\MagicStarter\Contracts\UpdatesUserProfiles;
use FlutterSdk\MagicStarter\Http\Controllers\ProfileController;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\TestCase;
use FlutterSdk\MagicStarter\Traits\HasTeams;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;

final class ProfileControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::reset();

        \call_user_func('config', ['database.default' => 'testing']);
        \call_user_func('config', ['database.connections.testing' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);
        \call_user_func('config', [
            'magic-starter.models.user' => ProfileControllerTestUser::class,
            'magic-starter.models.team' => ProfileControllerTestTeam::class,
        ]);
        \call_user_func('config', [
            'magic-starter.supported_locales' => [
                'en',
                'tr',
                'de',
            ],
        ]);

        \call_user_func([\call_user_func('app', 'db.schema'), 'create'], 'users', function ($table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->string('locale')->nullable();
            $table->string('timezone')->nullable();
            $table->string('profile_photo_path')->nullable();
            $table->string('current_team_id')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('deletion_scheduled_at')->nullable();
            $table->timestamp('orphaned_at')->nullable();
            $table->timestamps();
        });

        \call_user_func([\call_user_func('app', 'db.schema'), 'create'], 'teams', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('name');
            $table->boolean('personal_team')->default(false);
            $table->timestamps();
        });

        \call_user_func([\call_user_func('app', 'db.schema'), 'create'], 'team_user', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->uuid('user_id');
            $table->string('role')->nullable();
            $table->timestamps();
        });

        \call_user_func([\call_user_func('app', 'db.schema'), 'create'], 'personal_access_tokens', function ($table): void {
            $table->uuid('id')->primary();
            $table->string('tokenable_type');
            $table->uuid('tokenable_id');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });

        \call_user_func('app')->instance(UpdatesUserProfiles::class, new class implements UpdatesUserProfiles
        {
            public function update(\Illuminate\Contracts\Auth\Authenticatable $user, array $input): void
            {
                $user->forceFill($input)->save();
            }
        });

        \call_user_func('app')->instance(UpdatesUserPasswords::class, new class implements UpdatesUserPasswords
        {
            public function update(\Illuminate\Contracts\Auth\Authenticatable $user, array $input): void
            {
                $user->forceFill([
                    'password' => \password_hash((string) ($input['password'] ?? ''), PASSWORD_BCRYPT),
                ])->save();
            }
        });

        \call_user_func('app', 'router')->put('/user/profile', [ProfileController::class, 'update']);
        \call_user_func('app', 'router')->put('/user/password', [ProfileController::class, 'updatePassword']);
        \call_user_func('app', 'router')->delete('/user', [ProfileController::class, 'destroy']);
    }

    public function test_update_profile_updates_authenticated_user_using_contract_action(): void
    {
        $user = ProfileControllerTestUser::query()->create([
            'name' => 'Old Name',
            'email' => 'user@example.test',
            'phone' => '+11234567890',
            'timezone' => 'UTC',
            'password' => \password_hash('secret123', PASSWORD_BCRYPT),
        ]);

        $this->actingAs($user)
            ->putJson('/user/profile', [
                'name' => 'New Name',
                'phone' => '+15551000000',
                'timezone' => 'Europe/Istanbul',
                'locale' => 'tr',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.timezone', 'Europe/Istanbul')
            ->assertJsonPath('data.locale', 'tr')
            ->assertJsonPath('data.phone', '+15551000000');

        $this->assertSame('New Name', $user->fresh()->name);
        $this->assertSame('+15551000000', $user->fresh()->phone);
    }

    public function test_update_password_updates_password_and_returns_expected_message(): void
    {
        $user = ProfileControllerTestUser::query()->create([
            'name' => 'User',
            'email' => 'user@example.test',
            'password' => \password_hash('Old-Password-123', PASSWORD_BCRYPT),
        ]);

        $this->actingAs($user)
            ->putJson('/user/password', [
                'current_password' => 'Old-Password-123',
                'password' => 'New-Password-123',
                'password_confirmation' => 'New-Password-123',
            ])
            ->assertOk()
            ->assertJson([
                'message' => 'Password updated successfully.',
            ]);

        $this->assertTrue(\password_verify('New-Password-123', (string) $user->fresh()->password));
    }

    /**
     * DELETE /user schedules rather than deletes: the account is locked (every
     * token revoked) and stamped, and the user row stays for the purge.
     */
    public function test_destroy_schedules_the_deletion_and_revokes_all_tokens(): void
    {
        Carbon::setTestNow('2026-10-03 09:30:00');

        $user = ProfileControllerTestUser::query()->create([
            'name' => 'User',
            'email' => 'user@example.test',
            'password' => \password_hash('delete-me', PASSWORD_BCRYPT),
        ]);
        $this->createToken($user, 'device-1');
        $this->createToken($user, 'device-2');

        $this->actingAs($user)
            ->deleteJson('/user', ['password' => 'delete-me'])
            ->assertStatus(202)
            ->assertJsonPath('message', __('magic-starter::social.deletion_scheduled', ['days' => 30]));

        $user = ProfileControllerTestUser::query()->find($user->getKey());
        $this->assertNotNull($user, 'Nothing is deleted inside the request.');
        $this->assertSame('2026-10-03 09:30:00', Carbon::parse($user->deletion_scheduled_at)->toDateTimeString());
        $this->assertSame(0, ProfileControllerTestToken::query()->count());
    }

    /**
     * The 202 body carries the stamped date, so the client can show the window.
     */
    public function test_destroy_answers_with_the_scheduled_date(): void
    {
        $user = ProfileControllerTestUser::query()->create([
            'name' => 'User',
            'email' => 'user@example.test',
            'password' => \password_hash('delete-me', PASSWORD_BCRYPT),
        ]);

        $response = $this->actingAs($user)
            ->deleteJson('/user', ['password' => 'delete-me'])
            ->assertStatus(202);

        $this->assertNotNull($response->json('data.deletion_scheduled_at'));
    }

    /**
     * An owned team somebody else belongs to refuses with 422, the stable code
     * and the team ids, and the account, its team and its tokens are untouched.
     */
    public function test_destroy_with_a_shared_owned_team_refuses_and_changes_nothing(): void
    {
        $user = ProfileControllerTestUser::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => \password_hash('delete-me', PASSWORD_BCRYPT),
        ]);
        $member = ProfileControllerTestUser::query()->create([
            'name' => 'Member',
            'email' => 'member@example.test',
        ]);
        $team = ProfileControllerTestTeam::query()->create([
            'user_id' => $user->getKey(),
            'name' => 'Shared',
        ]);
        $team->users()->attach($user->getKey(), ['role' => 'owner']);
        $team->users()->attach($member->getKey(), ['role' => 'member']);
        $this->createToken($user, 'device-1');

        $this->actingAs($user)
            ->deleteJson('/user', ['password' => 'delete-me'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'owns_shared_teams')
            ->assertJsonPath('message', __('magic-starter::social.owns_shared_teams'))
            ->assertJsonPath('team_ids', [(string) $team->getKey()]);

        $this->assertNull($user->fresh()->deletion_scheduled_at);
        $this->assertNotNull(ProfileControllerTestTeam::query()->find($team->getKey()));
        $this->assertSame(2, $team->users()->count());
        $this->assertSame(1, ProfileControllerTestToken::query()->count());
    }

    private function createToken(ProfileControllerTestUser $user, string $name): void
    {
        ProfileControllerTestToken::query()->create([
            'tokenable_type' => ProfileControllerTestUser::class,
            'tokenable_id' => $user->getKey(),
            'name' => $name,
            'token' => hash('sha256', $name),
        ]);
    }
}

final class ProfileControllerTestUser extends Authenticatable
{
    use HasTeams;
    use HasUuids;

    protected $table = 'users';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function tokens()
    {
        return $this->morphMany(ProfileControllerTestToken::class, 'tokenable');
    }

    public function allTeams()
    {
        return collect();
    }

    public function getCurrentTeamOrPersonal(): mixed
    {
        return null;
    }
}

final class ProfileControllerTestToken extends Model
{
    use HasUuids;

    protected $table = 'personal_access_tokens';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}

final class ProfileControllerTestTeam extends Model
{
    use HasUuids;

    protected $table = 'teams';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(ProfileControllerTestUser::class, 'team_user', 'team_id', 'user_id')
            ->using(ProfileControllerTestMembership::class)
            ->withPivot('role')
            ->withTimestamps();
    }
}

final class ProfileControllerTestMembership extends Pivot
{
    use HasUuids;

    protected $table = 'team_user';

    public $incrementing = false;

    protected $keyType = 'string';
}
