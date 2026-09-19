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

    /**
     * A consumer publishes its own column without forking the resource.
     *
     * This resource is not resolved through the container, so a host app that
     * adds a column to `users` cannot swap it and has no other way to expose
     * the value on any endpoint this package owns.
     */
    public function test_consumer_fields_are_merged_into_the_payload(): void
    {
        config(['magic-starter.features' => []]);

        MagicStarter::serializeUserUsing(fn ($user, $request) => ['sync_salt' => 'abc123']);

        $resource = (new UserResource($this->makeUser()))->toArray(Request::create('/'));

        $this->assertSame('abc123', $resource['sync_salt']);
        // Merged rather than replacing: the package's own fields survive, so a
        // consumer does not inherit the job of keeping that list current.
        $this->assertSame('Test User', $resource['name']);
    }

    /**
     * The callback sees the user it is serialising, not the authenticated one.
     */
    public function test_consumer_fields_receive_the_resource_being_serialised(): void
    {
        config(['magic-starter.features' => []]);

        $seen = null;
        MagicStarter::serializeUserUsing(function ($user, $request) use (&$seen): array {
            $seen = $user;

            return [];
        });

        $user = $this->makeUser();
        (new UserResource($user))->toArray(Request::create('/'));

        $this->assertTrue($seen instanceof ConcreteUser && $seen->is($user));
    }

    /**
     * A consumer key wins over a package key, deliberately: the alternative is
     * a host unable to correct a value it owns.
     */
    public function test_a_consumer_key_overrides_a_package_key(): void
    {
        config(['magic-starter.features' => []]);

        MagicStarter::serializeUserUsing(fn ($user, $request) => ['name' => 'Overridden']);

        $this->assertSame('Overridden', (new UserResource($this->makeUser()))->toArray(Request::create('/'))['name']);
    }

    /**
     * No callback is the default, and `reset()` puts it back.
     */
    public function test_the_payload_is_unchanged_without_a_callback(): void
    {
        config(['magic-starter.features' => []]);

        $user = $this->makeUser();
        $before = array_keys((new UserResource($user))->toArray(Request::create('/')));

        MagicStarter::serializeUserUsing(fn ($user, $request) => ['extra' => true]);
        MagicStarter::reset();

        // Compared on the KEYS: the payload carries Carbon instances, and two
        // calls build two of them, so comparing values compares object
        // identity rather than the thing this asserts.
        $this->assertSame($before, array_keys((new UserResource($user))->toArray(Request::create('/'))));
    }

    private function makeUser(): ConcreteUser
    {
        return ConcreteUser::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
