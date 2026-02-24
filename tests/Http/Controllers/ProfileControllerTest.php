<?php

declare(strict_types=1);

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Contracts\DeletesUsers;
use FlutterSdk\MagicStarter\Contracts\UpdatesUserPasswords;
use FlutterSdk\MagicStarter\Contracts\UpdatesUserProfiles;
use FlutterSdk\MagicStarter\Http\Controllers\ProfileController;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;

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

        \call_user_func([\call_user_func('app', 'db.schema'), 'create'], 'users', function ($table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->string('locale')->nullable();
            $table->string('timezone')->nullable();
            $table->string('language')->nullable();
            $table->string('profile_photo_path')->nullable();
            $table->string('current_team_id')->nullable();
            $table->timestamp('email_verified_at')->nullable();
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
            public function update(mixed $user, array $input): void
            {
                $user->forceFill($input)->save();
            }
        });

        \call_user_func('app')->instance(UpdatesUserPasswords::class, new class implements UpdatesUserPasswords
        {
            public function update(mixed $user, array $input): void
            {
                $user->forceFill([
                    'password' => \password_hash((string) ($input['password'] ?? ''), PASSWORD_BCRYPT),
                ])->save();
            }
        });

        \call_user_func('app')->instance(DeletesUsers::class, new class implements DeletesUsers
        {
            public function delete(mixed $user): void
            {
                $user->tokens()->delete();
                $user->delete();
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
            'phone' => '111',
            'timezone' => 'UTC',
            'language' => 'en',
            'password' => \password_hash('secret123', PASSWORD_BCRYPT),
        ]);

        $this->actingAs($user)
            ->putJson('/user/profile', [
                'name' => 'New Name',
                'phone' => '555-1000',
                'timezone' => 'Europe/Istanbul',
                'language' => 'tr',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.timezone', 'Europe/Istanbul')
            ->assertJsonPath('data.language', 'tr');

        $this->assertSame('New Name', $user->fresh()->name);
        $this->assertSame('555-1000', $user->fresh()->phone);
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

    public function test_destroy_deletes_user_and_revokes_all_tokens(): void
    {
        $user = ProfileControllerTestUser::query()->create([
            'name' => 'User',
            'email' => 'user@example.test',
            'password' => \password_hash('delete-me', PASSWORD_BCRYPT),
        ]);

        ProfileControllerTestToken::query()->create([
            'tokenable_type' => ProfileControllerTestUser::class,
            'tokenable_id' => $user->getKey(),
            'name' => 'device-1',
            'token' => hash('sha256', 'device-1'),
        ]);

        ProfileControllerTestToken::query()->create([
            'tokenable_type' => ProfileControllerTestUser::class,
            'tokenable_id' => $user->getKey(),
            'name' => 'device-2',
            'token' => hash('sha256', 'device-2'),
        ]);

        $this->actingAs($user)
            ->deleteJson('/user', ['password' => 'delete-me'])
            ->assertNoContent();

        $this->assertNull(ProfileControllerTestUser::query()->find($user->getKey()));
        $this->assertSame(0, ProfileControllerTestToken::query()->count());
    }
}

final class ProfileControllerTestUser extends Authenticatable
{
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
}
