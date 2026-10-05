<?php

namespace FlutterSdk\MagicStarter\Tests\Social;

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Laravel\Socialite\Facades\Socialite;
use SocialiteProviders\Manager\ServiceProvider as SocialiteProvidersServiceProvider;
use SocialiteProviders\Microsoft\Provider as MicrosoftProvider;

/**
 * The package ships the Microsoft driver it signs users in with.
 *
 * Socialite has no core Microsoft driver; socialiteproviders/microsoft adds one
 * only when something listens for SocialiteWasCalled, so without this wiring an
 * adopter's `microsoft` flow answers "Driver [microsoft] not supported".
 */
class MicrosoftDriverRegistrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            SocialiteProvidersServiceProvider::class,
            MagicStarterServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('magic-starter.features', [
            Features::socialLogin(),
        ]);
        $app['config']->set('services.microsoft', [
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'redirect' => 'https://api.example.test/magic-starter/social/microsoft/callback',
        ]);
    }

    public function test_the_microsoft_driver_resolves_to_the_socialiteproviders_provider(): void
    {
        $this->assertInstanceOf(MicrosoftProvider::class, Socialite::driver('microsoft'));
    }
}
