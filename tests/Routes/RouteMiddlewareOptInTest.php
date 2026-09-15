<?php

namespace FlutterSdk\MagicStarter\Tests\Routes;

use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Contracts\Config\Repository;

/**
 * A host that names middleware gets it on every route this package owns.
 *
 * This is the half that was missing entirely. These routes are loaded by the
 * service provider rather than from the host's `routes/api.php`, so they joined
 * no group at all: a host whose `api` group resolves the caller's language had
 * it run on every route it wrote and on none of this package's, and a Turkish
 * account read English refusals from exactly the screens this package owns.
 */
final class RouteMiddlewareOptInTest extends TestCase
{
    public function test_a_host_named_middleware_reaches_every_package_route(): void
    {
        $this->assertContains(
            RouteMiddlewareProbe::class,
            RouteMiddlewareProbe::middlewareOnLogin($this),
        );
    }

    /**
     * Applies the probe middleware before the routes are registered.
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        tap($app['config'], function (Repository $config): void {
            $config->set('magic-starter.route_middleware', [RouteMiddlewareProbe::class]);
        });
    }
}
