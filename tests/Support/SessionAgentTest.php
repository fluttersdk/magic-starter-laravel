<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use FlutterSdk\MagicStarter\Support\SessionAgent;
use FlutterSdk\MagicStarter\Tests\TestCase;

/**
 * Tests for the SessionAgent support class.
 *
 * These tests are RED by design — `SessionAgent` does not exist yet.
 * Expected failure: `Class "FlutterSdk\MagicStarter\Support\SessionAgent" not found`
 */
final class SessionAgentTest extends TestCase
{
    private const UA_DESKTOP_CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    private const UA_MOBILE_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

    /**
     * Test that parse() returns non-empty browser and platform strings for a known UA.
     */
    public function test_parse_returns_browser_and_platform_for_known_user_agent(): void
    {
        $result = SessionAgent::parse(self::UA_DESKTOP_CHROME);

        $this->assertNotEmpty($result['browser']);
        $this->assertNotEmpty($result['platform']);
    }

    /**
     * Test that a desktop Chrome UA is detected as desktop, not mobile.
     */
    public function test_parse_detects_desktop(): void
    {
        $result = SessionAgent::parse(self::UA_DESKTOP_CHROME);

        $this->assertTrue($result['is_desktop']);
        $this->assertFalse($result['is_mobile']);
    }

    /**
     * Test that a mobile iPhone UA is detected as mobile, not desktop.
     */
    public function test_parse_detects_mobile(): void
    {
        $result = SessionAgent::parse(self::UA_MOBILE_IPHONE);

        $this->assertTrue($result['is_mobile']);
        $this->assertFalse($result['is_desktop']);
    }

    /**
     * Test that an empty user agent string returns safe defaults without crashing.
     */
    public function test_parse_returns_defaults_for_empty_user_agent(): void
    {
        $result = SessionAgent::parse('');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('browser', $result);
        $this->assertArrayHasKey('platform', $result);
        $this->assertArrayHasKey('is_desktop', $result);
        $this->assertArrayHasKey('is_mobile', $result);
    }

    /**
     * Test that the parsed result always contains all required keys.
     */
    public function test_parse_returns_array_with_correct_keys(): void
    {
        $result = SessionAgent::parse(self::UA_DESKTOP_CHROME);

        $this->assertArrayHasKey('browser', $result);
        $this->assertArrayHasKey('platform', $result);
        $this->assertArrayHasKey('is_desktop', $result);
        $this->assertArrayHasKey('is_mobile', $result);
    }
}
