<?php

namespace FlutterSdk\MagicStarter\Tests\Routes;

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Http\Middleware\RequireTokenAbility;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\SanctumServiceProvider;
use ReflectionClass;
use ReflectionMethod;

/**
 * Every route behind a Sanctum guard carries the ability gate, with every
 * feature switched on.
 *
 * Features register their routes when the provider boots, so a sweep with a
 * few of them on would pass while a route behind one of the others slipped
 * past. The feature list is read from {@see Features} itself, so a feature
 * added later joins the sweep without anyone remembering to add it.
 */
final class RequiredAbilitySweepTest extends TestCase
{
    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            SanctumServiceProvider::class,
            MagicStarterServiceProvider::class,
        ];
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('magic-starter.features', self::everyFeature());
        // Billing refuses to boot without a catalogue to order.
        $app['config']->set('magic-starter.billing.tier_order', ['free', 'pro']);
    }

    public function test_every_signed_in_route_carries_the_gate(): void
    {
        $signedIn = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (LaravelRoute $route): bool => $this->guardedBySanctum($route));

        $this->assertGreaterThan(40, $signedIn->count(), 'the sweep reached the signed-in routes');

        $ungated = $signedIn
            ->reject(fn (LaravelRoute $route): bool => in_array(RequireTokenAbility::class, $route->gatherMiddleware(), true))
            ->map(fn (LaravelRoute $route): string => implode('|', $route->methods()) . ' ' . $route->uri())
            ->values()
            ->all();

        $this->assertSame([], $ungated, 'a signed-in route without the ability gate');
    }

    public function test_the_routes_a_caller_reaches_without_a_token_carry_no_gate(): void
    {
        foreach (['auth/login', 'auth/register', 'auth/forgot-password', 'auth/reset-password', 'auth/handoff/exchange', 'auth/social/exchange'] as $uri) {
            $route = collect(Route::getRoutes()->getRoutes())->first(fn (LaravelRoute $r): bool => $r->uri() === $uri);

            $this->assertNotNull($route, "{$uri} is registered");
            $this->assertNotContains(RequireTokenAbility::class, $route->gatherMiddleware(), $uri);
        }
    }

    /**
     * Any guard on the Sanctum guard, with or without a list of guards after it.
     */
    private function guardedBySanctum(LaravelRoute $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && preg_match('/^auth:.*sanctum/', $middleware) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function everyFeature(): array
    {
        $features = [];

        foreach ((new ReflectionClass(Features::class))->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC) as $method) {
            if ($method->getNumberOfParameters() === 0 && (string) $method->getReturnType() === 'string') {
                $features[] = (string) $method->invoke(null);
            }
        }

        return $features;
    }
}
