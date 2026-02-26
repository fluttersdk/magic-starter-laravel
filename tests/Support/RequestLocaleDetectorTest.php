<?php

declare(strict_types=1);

namespace FlutterSdk\MagicStarter\Tests\Support;

use FlutterSdk\MagicStarter\Support\RequestLocaleDetector;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Http\Request;

final class RequestLocaleDetectorTest extends TestCase
{
    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'magic-starter.supported_locales' => [
                'en',
                'tr',
                'de',
            ],
            'magic-starter.supported_timezones' => [
                'UTC',
                'Europe/Istanbul',
                'Europe/London',
                'Europe/Berlin',
                'America/New_York',
            ],
        ]);
    }

    /**
     * Test that it detects locale from Accept-Language header.
     */
    public function test_detects_locale_from_accept_language_header(): void
    {
        $request = Request::create(
            '/',
            'GET',
            [],
            [],
            [],
            [
                'HTTP_ACCEPT_LANGUAGE' => 'tr-TR,tr;q=0.9,en;q=0.8',
            ],
        );

        $this->assertEquals('tr', RequestLocaleDetector::detectLocale($request));
    }

    /**
     * Test that it returns null when no Accept-Language header is present.
     */
    public function test_returns_null_when_no_accept_language_header(): void
    {
        $request = Request::create('/', 'GET', [], [], [], [
            'HTTP_ACCEPT_LANGUAGE' => '',
        ]);

        $this->assertNull(RequestLocaleDetector::detectLocale($request));

    }

    /**
     * Test that it returns null when locale is not in supported list.
     */
    public function test_returns_null_when_locale_not_in_supported_list(): void
    {
        $request = Request::create(
            '/',
            'GET',
            [],
            [],
            [],
            [
                'HTTP_ACCEPT_LANGUAGE' => 'ja-JP',
            ],
        );

        $this->assertNull(RequestLocaleDetector::detectLocale($request));
    }

    /**
     * Test that it falls back to second choice when first is unsupported.
     */
    public function test_falls_back_to_second_choice_when_first_unsupported(): void
    {
        $request = Request::create(
            '/',
            'GET',
            [],
            [],
            [],
            [
                'HTTP_ACCEPT_LANGUAGE' => 'ja;q=0.9,en;q=0.8',
            ],
        );

        $this->assertEquals('en', RequestLocaleDetector::detectLocale($request));
    }

    /**
     * Test that it handles simple Accept-Language without quality.
     */
    public function test_handles_simple_accept_language_without_quality(): void
    {
        $request = Request::create(
            '/',
            'GET',
            [],
            [],
            [],
            [
                'HTTP_ACCEPT_LANGUAGE' => 'tr',
            ],
        );

        $this->assertEquals('tr', RequestLocaleDetector::detectLocale($request));
    }

    /**
     * Test that it detects timezone from X-Timezone header.
     */
    public function test_detects_timezone_from_x_timezone_header(): void
    {
        $request = Request::create(
            '/',
            'GET',
            [],
            [],
            [],
            [
                'HTTP_X_TIMEZONE' => 'Europe/Istanbul',
            ],
        );

        $this->assertEquals('Europe/Istanbul', RequestLocaleDetector::detectTimezone($request));
    }

    /**
     * Test that it returns null when no X-Timezone header is present.
     */
    public function test_returns_null_when_no_x_timezone_header(): void
    {
        $request = Request::create('/');

        $this->assertNull(RequestLocaleDetector::detectTimezone($request));
    }

    /**
     * Test that it returns null when timezone is not in supported list.
     */
    public function test_returns_null_when_timezone_not_in_supported_list(): void
    {
        $request = Request::create(
            '/',
            'GET',
            [],
            [],
            [],
            [
                'HTTP_X_TIMEZONE' => 'Asia/Kolkata',
            ],
        );

        $this->assertNull(RequestLocaleDetector::detectTimezone($request));
    }

    /**
     * Test that timezone detection is case sensitive.
     */
    public function test_timezone_detection_is_case_sensitive(): void
    {
        $request = Request::create(
            '/',
            'GET',
            [],
            [],
            [],
            [
                'HTTP_X_TIMEZONE' => 'europe/istanbul',
            ],
        );

        $this->assertNull(RequestLocaleDetector::detectTimezone($request));
    }
}
