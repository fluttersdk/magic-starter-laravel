<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Resources;

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Http\Resources\UserResource;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeamUser;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Tests for UserResource team field gating.
 *
 * Verifies that current_team and all_teams are conditionally included
 * based on the teams feature toggle.
 */
class UserResourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        MagicStarter::reset();

        config([
            'auth.providers.users.model' => ConcreteUser::class,
            'magic-starter.models.user' => ConcreteUser::class,
            'magic-starter.models.team' => ConcreteTeam::class,
            'magic-starter.models.membership' => ConcreteTeamUser::class,
        ]);

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('email')->unique()->nullable();
            $table->string('password')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->string('device_id', 255)->unique()->nullable();
            $table->string('phone')->unique()->nullable();
            $table->char('phone_country', 2)->nullable();
            $table->string('locale')->default('en');
            $table->string('timezone')->default('UTC');
            $table->string('language')->nullable();
            $table->string('profile_photo_path')->nullable();
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
    }

    protected function tearDown(): void
    {
        MagicStarter::reset();
        parent::tearDown();
    }

    /**
     * Teams feature disabled: current_team and all_teams omitted from response.
     */
    public function test_team_fields_omitted_when_teams_feature_disabled(): void
    {
        config(['magic-starter.features' => []]);

        $user = ConcreteUser::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $request = Request::create('/');
        $request->setUserResolver(fn () => $user);
        app()->instance('request', $request);

        $resource = (new UserResource($user))->resolve($request);

        $this->assertArrayNotHasKey('current_team', $resource);
        $this->assertArrayNotHasKey('all_teams', $resource);
    }

    /**
     * Teams feature enabled: all_teams key is present in response.
     */
    public function test_team_fields_present_when_teams_feature_enabled(): void
    {
        config(['magic-starter.features' => [Features::teams()]]);

        $user = ConcreteUser::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $request = Request::create('/');
        $request->setUserResolver(fn () => $user);
        app()->instance('request', $request);
        $resource = (new UserResource($user))->resolve($request);
        $resource = (new UserResource($user))->toArray($request);

        $this->assertArrayHasKey('all_teams', $resource);
    }
}
