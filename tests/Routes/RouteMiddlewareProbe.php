<?php

namespace FlutterSdk\MagicStarter\Tests\Routes;

use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * A middleware that does nothing, plus the one read both route tests make.
 */
final class RouteMiddlewareProbe
{
    /**
     * The middleware stack on a route this package registers.
     *
     * @return list<string>
     */
    public static function middlewareOnLogin(TestCase $test): array
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r): bool => $r->uri() === 'auth/login');

        $test->assertNotNull($route, 'the login route is registered');

        return $route->gatherMiddleware();
    }

    public function handle(Request $request, \Closure $next): mixed
    {
        return $next($request);
    }
}
