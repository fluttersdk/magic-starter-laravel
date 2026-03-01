<?php

namespace FlutterSdk\MagicStarter\Tests;

use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

class TestCase extends OrchestraTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        \FlutterSdk\MagicStarter\MagicStarter::useUserModel(\FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser::class);
        \FlutterSdk\MagicStarter\MagicStarter::useTeamModel(\FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam::class);
        \FlutterSdk\MagicStarter\MagicStarter::useMembershipModel(\FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeamUser::class);
        \FlutterSdk\MagicStarter\MagicStarter::useTeamInvitationModel(\FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeamInvitation::class);
    }

    protected function getPackageProviders($app): array
    {
        return [
            MagicStarterServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * Plugin tests always run without a route prefix so that hardcoded
     * paths like '/auth/login' resolve correctly regardless of the
     * default shipped in the published config.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('magic-starter.route_prefix', '');
    }
}
