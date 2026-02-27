<?php

namespace FlutterSdk\MagicStarter\Tests;

use FlutterSdk\MagicStarter\MagicStarterServiceProvider;

class SmokeTest extends TestCase
{
    public function test_service_provider_boots(): void
    {
        $this->assertTrue(
            $this->app->providerIsLoaded(MagicStarterServiceProvider::class),
        );
    }
}
