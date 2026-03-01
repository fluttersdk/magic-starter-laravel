<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Http\Controllers\SettingsController;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Support\Facades\Route;

class SettingsControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::reset();

        config([
            'magic-starter.supported_locales' => [
                'en',
                'tr',
            ],
            'magic-starter.supported_timezones' => [
                'UTC',
                'Europe/Istanbul',
                'Europe/London',
                'America/New_York',
            ],
            'magic-starter.defaults.locale' => 'en',
            'magic-starter.defaults.timezone' => 'UTC',
            'magic-starter.features' => [],
        ]);

        Route::get('/settings', [SettingsController::class, 'index']);
    }

    protected function tearDown(): void
    {
        MagicStarter::reset();
        parent::tearDown();
    }

    /**
     * The settings endpoint must be publicly accessible — no auth token required.
     */
    public function test_settings_returns_200_without_auth(): void
    {
        $response = $this->getJson('/settings');

        $response->assertOk();
    }

    /**
     * The response must include all five top-level keys.
     */
    public function test_settings_returns_correct_json_structure(): void
    {
        $response = $this->getJson('/settings');

        $response->assertOk()->assertJsonStructure([
            'supported_timezones',
            'supported_locales',
            'features',
            'auth',
            'defaults',
        ]);

        $this->assertIsArray($response->json('supported_timezones'));
        $this->assertIsArray($response->json('supported_locales'));
        $this->assertIsArray($response->json('features'));
        $this->assertIsArray($response->json('auth'));
        $this->assertIsArray($response->json('defaults'));
    }

    /**
     * When teams feature is enabled, the response must reflect it as true.
     */
    public function test_settings_reflects_enabled_features(): void
    {
        config([
            'magic-starter.features' => [
                Features::teams(),
            ],
        ]);

        $response = $this->getJson('/settings');

        $response->assertOk()->assertJsonPath('features.teams', true);
    }

    /**
     * When all features are disabled, all feature flags must be false (except registration).
     */
    public function test_settings_reflects_disabled_features(): void
    {
        config([
            'magic-starter.features' => [],
        ]);

        $response = $this->getJson('/settings');

        $response->assertOk()
            ->assertJsonPath('features.teams', false)
            ->assertJsonPath('features.social_login', false)
            ->assertJsonPath('features.email_verification', false)
            ->assertJsonPath('features.guest_auth', false)
            ->assertJsonPath('features.phone_otp', false)
            ->assertJsonPath('features.newsletter', false)
            ->assertJsonPath('features.extended_profile', false)
            ->assertJsonPath('features.two_factor_authentication', false)
            ->assertJsonPath('features.sessions', false)
            ->assertJsonPath('features.profile_photos', false)
            ->assertJsonPath('features.notifications', false);
    }

    /**
     * The supported_timezones list must be a non-empty array from config.
     */
    public function test_settings_returns_supported_timezones(): void
    {
        $response = $this->getJson('/settings');

        $response->assertOk();

        $timezones = $response->json('supported_timezones');

        $this->assertIsArray($timezones);
        $this->assertNotEmpty($timezones);
        $this->assertContains('UTC', $timezones);
    }

    /**
     * The supported_locales list must be a non-empty array from config.
     */
    public function test_settings_returns_supported_locales(): void
    {
        $response = $this->getJson('/settings');

        $response->assertOk();

        $locales = $response->json('supported_locales');

        $this->assertIsArray($locales);
        $this->assertNotEmpty($locales);
        $this->assertContains('en', $locales);
    }

    /**
     * The auth section must expose email and phone as booleans.
     */
    public function test_settings_returns_auth_modes(): void
    {
        $response = $this->getJson('/settings');

        $response->assertOk();

        $auth = $response->json('auth');

        $this->assertArrayHasKey('email', $auth);
        $this->assertArrayHasKey('phone', $auth);
        $this->assertIsBool($auth['email']);
        $this->assertIsBool($auth['phone']);
    }

    /**
     * The defaults section must contain locale and timezone as strings.
     */
    public function test_settings_returns_defaults(): void
    {
        $response = $this->getJson('/settings');

        $response->assertOk();

        $defaults = $response->json('defaults');

        $this->assertArrayHasKey('locale', $defaults);
        $this->assertArrayHasKey('timezone', $defaults);
        $this->assertIsString($defaults['locale']);
        $this->assertIsString($defaults['timezone']);
    }

    /**
     * The response must NOT contain any sensitive or internal configuration keys.
     */
    public function test_settings_does_not_expose_sensitive_keys(): void
    {
        $response = $this->getJson('/settings');

        $response->assertOk();

        $body = $response->json();
        $bodyString = json_encode($body);

        // Top-level sensitive keys must not appear
        $this->assertArrayNotHasKey('frontend_url', $body);
        $this->assertArrayNotHasKey('token_expiration_minutes', $body);
        $this->assertArrayNotHasKey('models', $body);
        $this->assertArrayNotHasKey('two_factor', $body);

        // Internal values must not leak anywhere in the payload
        $this->assertStringNotContainsString('frontend_url', $bodyString);
        $this->assertStringNotContainsString('token_expiration_minutes', $bodyString);
        $this->assertStringNotContainsString('geoip_db_path', $bodyString);
    }
}
