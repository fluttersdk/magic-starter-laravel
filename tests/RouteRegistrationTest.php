<?php

namespace FlutterSdk\MagicStarter\Tests;

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class RouteRegistrationTest extends TestCase
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

    public function test_registers_all_package_routes_when_features_enabled(): void
    {
        $this->bootRoutesWithConfig([
            Features::teams(),
            Features::sessions(),
            Features::profilePhotos(),
        ]);

        $this->assertRouteExists('POST', '/auth/register');
        $this->assertRouteExists('POST', '/auth/login');
        $this->assertRouteExists('POST', '/auth/social/google');
        $this->assertRouteExists('POST', '/auth/forgot-password');
        $this->assertRouteExists('POST', '/auth/reset-password');

        $this->assertRouteExists('POST', '/auth/logout');
        $this->assertRouteExists('GET', '/auth/user');

        $this->assertRouteExists('GET', '/teams');
        $this->assertRouteExists('POST', '/teams');
        $this->assertRouteExists('GET', '/teams/uuid-team');
        $this->assertRouteExists('PUT', '/teams/uuid-team');
        $this->assertRouteExists('DELETE', '/teams/uuid-team');

        $this->assertRouteExists('GET', '/teams/uuid-team/members');
        $this->assertRouteExists('PUT', '/teams/uuid-team/members/uuid-user');
        $this->assertRouteExists('DELETE', '/teams/uuid-team/members/uuid-user');
        $this->assertRouteExists('DELETE', '/teams/uuid-team/leave');

        $this->assertRouteExists('GET', '/teams/uuid-team/invitations');
        $this->assertRouteExists('POST', '/teams/uuid-team/invitations');
        $this->assertRouteExists('DELETE', '/teams/uuid-team/invitations/uuid-invitation');
        $this->assertRouteExists('POST', '/invitations/some-token/accept');
        $this->assertRouteExists('PUT', '/user/current-team');

        $this->assertRouteExists('PUT', '/user/profile');
        $this->assertRouteExists('PUT', '/user/password');
        $this->assertRouteExists('POST', '/user');
        $this->assertRouteExists('DELETE', '/user');

        $this->assertRouteExists('POST', '/user/profile-photo');
        $this->assertRouteExists('DELETE', '/user/profile-photo');

        $this->assertRouteExists('GET', '/sessions');
        $this->assertRouteExists('DELETE', '/sessions/other');
        $this->assertRouteExists('DELETE', '/sessions/uuid-token');
    }

    public function test_team_routes_are_not_registered_when_teams_feature_disabled(): void
    {
        $this->bootRoutesWithConfig([
            Features::sessions(),
            Features::profilePhotos(),
        ]);

        $this->assertRouteMissing('GET', '/teams');
        $this->assertRouteMissing('POST', '/teams/uuid-team/invitations');
        $this->assertRouteMissing('GET', '/teams/uuid-team/members');
        $this->assertRouteMissing('PUT', '/user/current-team');
    }

    public function test_session_routes_are_not_registered_when_sessions_feature_disabled(): void
    {
        $this->bootRoutesWithConfig([
            Features::teams(),
            Features::profilePhotos(),
        ]);

        $this->assertRouteMissing('GET', '/sessions');
        $this->assertRouteMissing('DELETE', '/sessions/other');
        $this->assertRouteMissing('DELETE', '/sessions/uuid-token');
    }

    public function test_profile_photo_routes_are_not_registered_when_feature_disabled(): void
    {
        $this->bootRoutesWithConfig([
            Features::teams(),
            Features::sessions(),
        ]);

        $this->assertRouteMissing('POST', '/user/profile-photo');
        $this->assertRouteMissing('DELETE', '/user/profile-photo');

        $this->assertRouteExists('PUT', '/user/profile');
        $this->assertRouteExists('PUT', '/user/password');
    }

    public function test_route_prefix_is_applied_from_config(): void
    {
        $this->bootRoutesWithConfig([
            Features::teams(),
            Features::sessions(),
            Features::profilePhotos(),
        ], 'v2/api');

        $this->assertRouteExists('POST', '/v2/api/auth/register');
        $this->assertRouteExists('GET', '/v2/api/teams');
        $this->assertRouteExists('GET', '/v2/api/sessions');

        $this->assertRouteMissing('POST', '/auth/register');
        $this->assertRouteMissing('GET', '/teams');
        $this->assertRouteMissing('GET', '/sessions');
    }

    public function test_ignore_routes_disables_all_package_route_registration(): void
    {
        MagicStarter::ignoreRoutes();

        $this->bootRoutesWithConfig([
            Features::teams(),
            Features::sessions(),
            Features::profilePhotos(),
        ]);

        $this->assertRouteMissing('POST', '/auth/register');
        $this->assertRouteMissing('POST', '/auth/login');
        $this->assertRouteMissing('GET', '/teams');
        $this->assertRouteMissing('GET', '/sessions');
        $this->assertRouteMissing('PUT', '/user/profile');
    }

    private function bootRoutesWithConfig(array $features, string $prefix = ''): void
    {
        config([
            'magic-starter.features' => $features,
            'magic-starter.route_prefix' => $prefix,
        ]);

        $this->app['router']->setRoutes(new RouteCollection);

        $provider = new MagicStarterServiceProvider($this->app);
        $provider->boot();
    }

    private function assertRouteExists(string $method, string $uri): void
    {
        $route = $this->matchRoute($method, $uri);

        $this->assertNotNull($route, sprintf('Expected route [%s %s] to exist.', $method, $uri));
    }

    private function assertRouteMissing(string $method, string $uri): void
    {
        $route = $this->matchRoute($method, $uri);

        $this->assertNull($route, sprintf('Expected route [%s %s] to be absent.', $method, $uri));
    }

    private function matchRoute(string $method, string $uri): mixed
    {
        try {
            return $this->app['router']->getRoutes()->match(
                Request::create($uri, $method),
            );
        } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
            return null;
        }
    }
}
