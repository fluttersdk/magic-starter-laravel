<?php

namespace FlutterSdk\MagicStarter\Tests\Routes;

use FlutterSdk\MagicStarter\Tests\TestCase;

/**
 * Nothing of the host's runs on these routes unless the host names it.
 *
 * In its OWN class, with no `defineEnvironment` override, and that is the whole
 * point of the split: the hook applies to a whole class, so sharing one with the
 * opt-in case set the probe here too and this assertion passed against a shipped
 * default of `['api']`, which is exactly the value it exists to rule out.
 *
 * Empty rather than `['api']` because every route here already declares the
 * throttle it wants by name, and joining a group carrying `throttle:api` too
 * would halve a rate limit a prior release granted.
 */
final class RouteMiddlewareDefaultTest extends TestCase
{
    public function test_no_host_middleware_is_applied_by_default(): void
    {
        // The route's OWN named throttle is expected and is the package's; what
        // must be absent is anything the host would have contributed. Asserting
        // the whole stack rather than only the absence is what makes this fail
        // if a future default adds something as well as if it adds `api`.
        $this->assertSame(
            ['throttle:magic-starter-auth-login'],
            RouteMiddlewareProbe::middlewareOnLogin($this),
        );
    }
}
