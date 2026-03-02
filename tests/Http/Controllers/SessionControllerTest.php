<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Http\Controllers\SessionController;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;

final class SessionControllerTest extends TestCase
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
            'magic-starter.models.user' => SessionControllerTestUser::class,
            'magic-starter.models.team' => SessionControllerTestTeam::class,
        ]);

        \call_user_func([\call_user_func('app', 'db.schema'), 'create'], 'users', function ($table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('locale')->nullable();
            $table->string('timezone')->nullable();
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

        \call_user_func('app', 'router')->delete('/sessions/other', [SessionController::class, 'destroyOther']);
        \call_user_func('app', 'router')->get('/sessions', [SessionController::class, 'index']);
        \call_user_func('app', 'router')->delete('/sessions/{token}', [SessionController::class, 'destroy']);
    }

    public function test_index_returns_user_sessions_collection_resource(): void
    {
        $user = SessionControllerTestUser::query()->create([
            'name' => 'Session User',
            'email' => 'session@example.test',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
        ]);

        $tokenA = SessionControllerTestToken::query()->create([
            'tokenable_type' => SessionControllerTestUser::class,
            'tokenable_id' => $user->getKey(),
            'name' => 'device-a',
            'token' => hash('sha256', 'token-a'),
            'ip_address' => '10.0.0.1',
            'user_agent' => 'AgentA',
        ]);

        SessionControllerTestToken::query()->create([
            'tokenable_type' => SessionControllerTestUser::class,
            'tokenable_id' => $user->getKey(),
            'name' => 'device-b',
            'token' => hash('sha256', 'token-b'),
            'ip_address' => '10.0.0.2',
            'user_agent' => 'AgentB',
        ]);

        $user->setCurrentAccessToken($tokenA);

        $this->actingAs($user)
            ->getJson('/sessions')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $tokenA->getKey())
            ->assertJsonPath('data.0.is_current_device', true);
    }

    public function test_destroy_revokes_specific_session_for_authenticated_user(): void
    {
        $user = SessionControllerTestUser::query()->create([
            'name' => 'Session User',
            'email' => 'session@example.test',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
        ]);

        $token = SessionControllerTestToken::query()->create([
            'tokenable_type' => SessionControllerTestUser::class,
            'tokenable_id' => $user->getKey(),
            'name' => 'target',
            'token' => hash('sha256', 'target-token'),
        ]);

        $this->actingAs($user)
            ->deleteJson('/sessions/' . $token->getKey())
            ->assertOk()
            ->assertJson([
                'message' => 'Session revoked successfully.',
            ]);

        $this->assertNull(SessionControllerTestToken::query()->find($token->getKey()));
    }

    public function test_destroy_other_revokes_all_sessions_except_current_token(): void
    {
        $user = SessionControllerTestUser::query()->create([
            'name' => 'Session User',
            'email' => 'session@example.test',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
        ]);

        $currentToken = SessionControllerTestToken::query()->create([
            'tokenable_type' => SessionControllerTestUser::class,
            'tokenable_id' => $user->getKey(),
            'name' => 'current',
            'token' => hash('sha256', 'current-token'),
        ]);

        $otherToken = SessionControllerTestToken::query()->create([
            'tokenable_type' => SessionControllerTestUser::class,
            'tokenable_id' => $user->getKey(),
            'name' => 'other',
            'token' => hash('sha256', 'other-token'),
        ]);

        $user->setCurrentAccessToken($currentToken);

        $this->actingAs($user)
            ->deleteJson('/sessions/other', ['password' => 'password'])
            ->assertOk()
            ->assertJson([
                'message' => 'Other sessions revoked successfully.',
            ]);

        $this->assertNotNull(SessionControllerTestToken::query()->find($currentToken->getKey()));
        $this->assertNull(SessionControllerTestToken::query()->find($otherToken->getKey()));
    }

    public function test_destroy_returns_404_for_nonexistent_session(): void
    {
        $user = SessionControllerTestUser::query()->create([
            'name' => 'Session User',
            'email' => 'session@example.test',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
        ]);
        $this->actingAs($user)
            ->deleteJson('/sessions/00000000-0000-0000-0000-000000000000')
            ->assertStatus(404);
    }
}

final class SessionControllerTestUser extends Authenticatable
{
    use HasUuids;

    protected $table = 'users';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected mixed $currentToken = null;

    public function tokens()
    {
        return $this->morphMany(SessionControllerTestToken::class, 'tokenable');
    }

    public function currentAccessToken(): mixed
    {
        return $this->currentToken;
    }

    public function setCurrentAccessToken(mixed $token): void
    {
        $this->currentToken = $token;
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

final class SessionControllerTestToken extends Model
{
    use HasUuids;

    protected $table = 'personal_access_tokens';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}

final class SessionControllerTestTeam extends Model
{
    use HasUuids;

    protected $table = 'teams';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
