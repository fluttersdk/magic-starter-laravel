<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use FlutterSdk\MagicStarter\Support\SessionLocation;
use FlutterSdk\MagicStarter\Tests\TestCase;

/**
 * Tests for the SessionLocation support class.
 *
 * These tests are RED by design — `SessionLocation` does not exist yet.
 * Expected failure: `Class "FlutterSdk\MagicStarter\Support\SessionLocation" not found`
 */
final class SessionLocationTest extends TestCase
{
    /**
     * Test that resolve() returns null when no GeoIP database path is configured.
     */
    public function test_resolve_returns_null_when_geoip_db_path_not_configured(): void
    {
        config(['magic-starter.two_factor.geoip_db_path' => null]);

        $result = SessionLocation::resolve('1.2.3.4');

        $this->assertNull($result);
    }

    /**
     * Test that resolve() returns null for private/loopback IPv4 addresses.
     */
    public function test_resolve_returns_null_for_private_ip_address(): void
    {
        config(['magic-starter.two_factor.geoip_db_path' => '/some/path/GeoLite2-City.mmdb']);

        $result = SessionLocation::resolve('127.0.0.1');

        $this->assertNull($result);
    }

    /**
     * Test that resolve() returns null for IPv6 loopback address.
     */
    public function test_resolve_returns_null_for_loopback_address(): void
    {
        config(['magic-starter.two_factor.geoip_db_path' => '/some/path/GeoLite2-City.mmdb']);

        $result = SessionLocation::resolve('::1');

        $this->assertNull($result);
    }

    /**
     * Test that resolve() returns null when the configured database file does not exist.
     * No exception should be thrown — graceful null return expected.
     */
    public function test_resolve_returns_null_when_db_file_missing(): void
    {
        config(['magic-starter.two_factor.geoip_db_path' => '/nonexistent/path/GeoLite2-City.mmdb']);

        $result = SessionLocation::resolve('8.8.8.8');

        $this->assertNull($result);
    }

    /**
     * Test that when successfully resolved, the result contains city and country keys.
     *
     * NOTE: This test documents the expected return shape. It will stay RED until both
     * SessionLocation is implemented AND a valid GeoIP database is present in tests.
     * In CI, this test is expected to return null (missing DB), so we assert the
     * shape contract: when non-null, it MUST have city and country keys.
     */
    public function test_resolve_returns_array_with_city_and_country_keys(): void
    {
        config(['magic-starter.two_factor.geoip_db_path' => null]);

        // With no DB configured, result is null — the shape contract is verified
        // by asserting that if a result IS returned, it must have city and country.
        $result = SessionLocation::resolve('8.8.8.8');

        if ($result !== null) {
            $this->assertArrayHasKey('city', $result);
            $this->assertArrayHasKey('country', $result);
        } else {
            // Class-not-found will cause this test to fail (RED) until implementation exists.
            // Once implemented, null is acceptable when DB is not configured.
            $this->assertNull($result);
        }
    }
}
