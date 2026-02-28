<?php

namespace FlutterSdk\MagicStarter\Tests;

use FlutterSdk\MagicStarter\MagicStarter;

class MagicStarterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        MagicStarter::reset();
    }

    protected function tearDown(): void
    {
        MagicStarter::reset();
        parent::tearDown();
    }

    public function test_user_model_resolves_from_magic_starter_config(): void
    {
        config(['magic-starter.models.user' => 'App\\Models\\CustomUser']);

        $this->assertSame('App\\Models\\CustomUser', MagicStarter::userModel());
    }

    public function test_user_model_falls_back_to_auth_providers_config(): void
    {
        config(['magic-starter.models.user' => null]);
        config(['auth.providers.users.model' => 'App\\Models\\AuthUser']);

        $this->assertSame('App\\Models\\AuthUser', MagicStarter::userModel());
    }

    public function test_user_model_throws_when_both_configs_null(): void
    {
        config(['magic-starter.models.user' => null]);
        config(['auth.providers.users.model' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/User model not configured/');

        MagicStarter::userModel();
    }

    public function test_team_model_resolves_from_config(): void
    {
        config(['magic-starter.models.team' => 'App\\Models\\CustomTeam']);

        $this->assertSame('App\\Models\\CustomTeam', MagicStarter::teamModel());
    }

    public function test_team_model_throws_when_not_configured(): void
    {
        config(['magic-starter.models.team' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Team model not configured/');

        MagicStarter::teamModel();
    }

    public function test_should_ignore_routes_is_false_by_default(): void
    {
        $this->assertFalse(MagicStarter::shouldIgnoreRoutes());
    }

    public function test_ignore_routes_sets_flag_to_true(): void
    {
        MagicStarter::ignoreRoutes();

        $this->assertTrue(MagicStarter::shouldIgnoreRoutes());
    }

    public function test_use_user_model_stores_class_name(): void
    {
        MagicStarter::useUserModel('App\\Models\\CustomUser');

        $this->assertSame('App\\Models\\CustomUser', MagicStarter::getUsing('user'));
    }

    public function test_use_team_model_stores_class_name(): void
    {
        MagicStarter::useTeamModel('App\\Models\\CustomTeam');

        $this->assertSame('App\\Models\\CustomTeam', MagicStarter::getUsing('team'));
    }

    public function test_get_using_returns_null_for_unknown_key(): void
    {
        $this->assertNull(MagicStarter::getUsing('unknown'));
    }

    public function test_reset_clears_all_static_state(): void
    {
        MagicStarter::ignoreRoutes();
        MagicStarter::useUserModel('App\\Models\\CustomUser');
        MagicStarter::reset();

        $this->assertFalse(MagicStarter::shouldIgnoreRoutes());
        $this->assertNull(MagicStarter::getUsing('user'));
    }

    public function test_team_model_resolves_concrete_when_abstract_configured(): void
    {
        // When config points to the abstract package Team class,
        // resolveConcreteModel should return App\Models\Team if it exists.
        config(['magic-starter.models.team' => \FlutterSdk\MagicStarter\Models\Team::class]);

        $result = MagicStarter::teamModel();

        // If App\Models\Team exists, it should return that.
        // If not, it returns the abstract class as-is.
        if (class_exists('App\\Models\\Team')) {
            $this->assertSame('App\\Models\\Team', $result);
        } else {
            $this->assertSame(\FlutterSdk\MagicStarter\Models\Team::class, $result);
        }
    }

    public function test_team_model_uses_custom_class_when_configured(): void
    {
        // Custom concrete class should be returned as-is, no resolution needed.
        config(['magic-starter.models.team' => 'App\\Models\\CustomTeam']);

        $this->assertSame('App\\Models\\CustomTeam', MagicStarter::teamModel());
    }

    public function test_runtime_override_takes_precedence_over_abstract_resolution(): void
    {
        config(['magic-starter.models.team' => \FlutterSdk\MagicStarter\Models\Team::class]);
        MagicStarter::useTeamModel('App\\Models\\MyCustomTeam');

        // Runtime override is NOT subject to abstract resolution —
        // it's already user-provided.
        $this->assertSame('App\\Models\\MyCustomTeam', MagicStarter::teamModel());
    }
}
