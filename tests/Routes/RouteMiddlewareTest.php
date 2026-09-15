<?php

namespace FlutterSdk\MagicStarter\Tests\Routes;

use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

final class RouteMiddlewareTest extends TestCase
{
    /**
     * Nothing of the host's runs on these routes unless the host names it.
     *
     * Empty rather than `['api']` on purpose: every route here declares the
     * throttle it wants by name, and joining a group carrying `throttle:api`
     * too would halve a rate limit a prior release granted.
     */
    public function test_no_host_middleware_is_applied_by_default(): void
    {
        $this->assertNotContains('api', $this->middlewareOnLogin());
    }

    /**
     * A host that names middleware gets it on every route this package owns.
     *
     * This is the half that was missing entirely. These routes are loaded by
     * the service provider rather than from the host's `routes/api.php`, so
     * they joined no group at all: a host whose `api` group resolves the
     * caller's language had it run on every route it wrote and on none of this
     * package's, and a Turkish account read English refusals from exactly the
     * screens this package owns.
     */
    public function test_a_host_named_middleware_reaches_every_package_route(): void
    {
        $this->assertContains(
            RouteMiddlewareProbe::class,
            $this->middlewareOnLogin(),
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

    /**
     * @return list<string>
     */
    private function middlewareOnLogin(): array
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r): bool => $r->uri() === 'auth/login');

        $this->assertNotNull($route, 'the login route is registered');

        return $route->gatherMiddleware();
    }
}

final class RouteMiddlewareProbe
{
    public function handle(Request $request, \Closure $next): mixed
    {
        return $next($request);
    }
}
