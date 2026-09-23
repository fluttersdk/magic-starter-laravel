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
use InvalidArgumentException;

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

    /**
     * A callback that does not answer an array is the consumer's bug, and it
     * has to read as one.
     *
     * `?callable` cannot constrain a closure's return, so without the check
     * this fatals with "Only arrays and Traversables can be unpacked" on every
     * endpoint that serialises a user rather than the one they were testing.
     */
    public function test_a_callback_that_returns_a_non_array_is_refused_by_name(): void
    {
        config(['magic-starter.features' => []]);

        MagicStarter::serializeUserUsing(fn ($user, $request) => null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/serializeUserUsing must return an array/');

        (new UserResource($this->makeUser()))->toArray(Request::create('/'));
    }

    /**
     * A guest with no upload answers null, so the client draws its own initial.
     *
     * It used to answer a generated ui-avatars.com image in the server's
     * colours, which a client cannot tell apart from a real upload: measured
     * in a consumer, the sidebar drew its themed initial and then replaced it
     * with a green image the moment the guest session answered.
     */
    public function test_an_account_with_no_photo_answers_a_null_photo_url(): void
    {
        config(['magic-starter.features' => []]);

        $guest = ConcreteUser::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Guest',
            'is_guest' => true,
        ]);

        $resource = (new UserResource($guest))->resolve(Request::create('/'));

        $this->assertArrayHasKey('profile_photo_url', $resource);
        $this->assertNull($resource['profile_photo_url']);
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
