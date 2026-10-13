<?php

namespace FlutterSdk\MagicStarter\Tests;

use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use RuntimeException;

class TestCase extends OrchestraTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A PostgreSQL database outlives the test, and tests create their own tables in setUp.
        if (DB::connection()->getDriverName() === 'pgsql') {
            Schema::dropAllTables();
        }

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
     *
     * The database is in-memory SQLite unless `DB_CONNECTION=pgsql`, which reads
     * `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD`; the
     * lock paths only show on PostgreSQL, where SQLite ignores `for update`.
     * The database name must be `testing` or end in `_test`, because setUp
     * drops every table in it.
     *
     * @throws RuntimeException When PostgreSQL is selected and the database is not a test database.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('magic-starter.route_prefix', '');

        if (env('DB_CONNECTION') !== 'pgsql') {
            return;
        }

        // setUp drops every table, so a shell that exports DB_DATABASE for a dev database must never reach it.
        $database = (string) env('DB_DATABASE', 'testing');

        if ($database !== 'testing' && ! str_ends_with($database, '_test')) {
            throw new RuntimeException(
                "Refusing to run against PostgreSQL database [{$database}]: the suite drops every table, "
                . 'so the name must be `testing` or end in `_test`.',
            );
        }

        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => $database,
            'username' => env('DB_USERNAME', 'postgres'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);
    }
}
