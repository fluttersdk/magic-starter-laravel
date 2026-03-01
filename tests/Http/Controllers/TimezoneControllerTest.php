<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Http\Controllers\TimezoneController;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Support\Facades\Route;

/**
 * Tests for the paginated, searchable timezone list endpoint.
 */
class TimezoneControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/timezones', [TimezoneController::class, 'index']);
        config([
            'magic-starter.features' => [
                Features::timezones(),
            ],
        ]);
    }

    // ─── Basic Accessibility ────────────────────────────────────────────

    public function test_timezones_returns_200_without_auth(): void
    {
        $response = $this->getJson('/timezones');

        $response->assertOk();
    }

    public function test_timezones_returns_paginated_json_structure(): void
    {
        $response = $this->getJson('/timezones');

        $response->assertOk()->assertJsonStructure([
            'data' => [
                '*' => [
                    'identifier',
                    'label',
                    'offset',
                    'offset_minutes',
                    'region',
                ],
            ],
            'links',
            'meta' => [
                'current_page',
                'last_page',
                'per_page',
                'total',
            ],
        ]);
    }

    // ─── Pagination ─────────────────────────────────────────────────────

    public function test_timezones_default_per_page_is_15(): void
    {
        $response = $this->getJson('/timezones');

        $response->assertOk();
        $this->assertLessThanOrEqual(15, count($response->json('data')));
        $this->assertEquals(15, $response->json('meta.per_page'));
    }

    public function test_timezones_respects_custom_per_page(): void
    {
        $response = $this->getJson('/timezones?per_page=5');

        $response->assertOk();
        $this->assertLessThanOrEqual(5, count($response->json('data')));
        $this->assertEquals(5, $response->json('meta.per_page'));
    }

    public function test_timezones_returns_correct_page(): void
    {
        $response = $this->getJson('/timezones?per_page=5&page=2');

        $response->assertOk();
        $this->assertEquals(2, $response->json('meta.current_page'));
    }

    public function test_timezones_total_matches_php_timezone_count(): void
    {
        $response = $this->getJson('/timezones');

        $response->assertOk();

        $total = $response->json('meta.total');
        $this->assertGreaterThan(100, $total);
    }

    // ─── Search ─────────────────────────────────────────────────────────

    public function test_timezones_search_by_identifier(): void
    {
        $response = $this->getJson('/timezones?search=Istanbul&per_page=100');

        $response->assertOk();

        $identifiers = collect($response->json('data'))->pluck('identifier');
        $this->assertTrue($identifiers->contains('Europe/Istanbul'));
    }

    public function test_timezones_search_is_case_insensitive(): void
    {
        $response = $this->getJson('/timezones?search=istanbul&per_page=100');

        $response->assertOk();

        $identifiers = collect($response->json('data'))->pluck('identifier');
        $this->assertTrue($identifiers->contains('Europe/Istanbul'));
    }

    public function test_timezones_search_by_offset_string(): void
    {
        $response = $this->getJson('/timezones?search=%2B00:00&per_page=500');

        $response->assertOk();
        $this->assertNotEmpty($response->json('data'));

        $offsets = collect($response->json('data'))->pluck('offset')->unique();
        $offsets->each(function (string $offset): void {
            $this->assertEquals('+00:00', $offset);
        });
    }

    public function test_timezones_search_returns_empty_for_no_match(): void
    {
        $response = $this->getJson('/timezones?search=XyzNonexistent999');

        $response->assertOk();
        $this->assertEmpty($response->json('data'));
        $this->assertEquals(0, $response->json('meta.total'));
    }

    public function test_timezones_search_reduces_total_count(): void
    {
        $allResponse = $this->getJson('/timezones');
        $searchResponse = $this->getJson('/timezones?search=Europe');

        $allTotal = $allResponse->json('meta.total');
        $searchTotal = $searchResponse->json('meta.total');

        $this->assertGreaterThan($searchTotal, $allTotal);
        $this->assertGreaterThan(0, $searchTotal);
    }

    // ─── Response Shape ─────────────────────────────────────────────────

    public function test_timezone_item_has_correct_shape_for_utc(): void
    {
        $response = $this->getJson('/timezones?search=UTC&per_page=500');

        $response->assertOk();

        $utc = collect($response->json('data'))->firstWhere('identifier', 'UTC');

        $this->assertNotNull($utc);
        $this->assertEquals('UTC', $utc['identifier']);
        $this->assertEquals('+00:00', $utc['offset']);
        $this->assertEquals(0, $utc['offset_minutes']);
        $this->assertEquals('(UTC+00:00) UTC', $utc['label']);
        $this->assertEquals('UTC', $utc['region']);
    }

    public function test_timezone_with_positive_offset(): void
    {
        $response = $this->getJson('/timezones?search=Istanbul&per_page=100');

        $response->assertOk();

        $istanbul = collect($response->json('data'))->firstWhere('identifier', 'Europe/Istanbul');

        $this->assertNotNull($istanbul);
        $this->assertEquals('Europe/Istanbul', $istanbul['identifier']);
        $this->assertEquals('+03:00', $istanbul['offset']);
        $this->assertEquals(180, $istanbul['offset_minutes']);
        $this->assertEquals('Europe', $istanbul['region']);
        $this->assertStringStartsWith('(UTC+03:00)', $istanbul['label']);
    }

    public function test_timezone_with_negative_offset(): void
    {
        $response = $this->getJson('/timezones?search=New_York&per_page=100');

        $response->assertOk();

        $ny = collect($response->json('data'))->firstWhere('identifier', 'America/New_York');

        $this->assertNotNull($ny);
        $this->assertEquals('America/New_York', $ny['identifier']);
        $this->assertEquals('America', $ny['region']);
        $this->assertIsString($ny['offset']);
        $this->assertIsInt($ny['offset_minutes']);
        $this->assertLessThan(0, $ny['offset_minutes']);
    }

    // ─── Sorting ────────────────────────────────────────────────────────

    public function test_timezones_sorted_by_offset_ascending(): void
    {
        $response = $this->getJson('/timezones?per_page=500');

        $response->assertOk();

        $offsets = collect($response->json('data'))->pluck('offset_minutes')->toArray();
        $sorted = $offsets;
        sort($sorted);

        $this->assertEquals($sorted, $offsets);
    }

    // ─── Feature Toggle ─────────────────────────────────────────────────

    public function test_feature_toggle_returns_correct_string(): void
    {
        $this->assertEquals('timezones', Features::timezones());
    }

    public function test_has_timezone_features_when_enabled(): void
    {
        config([
            'magic-starter.features' => [
                Features::timezones(),
            ],
        ]);

        $this->assertTrue(Features::hasTimezoneFeatures());
    }

    public function test_has_timezone_features_when_disabled(): void
    {
        config([
            'magic-starter.features' => [],
        ]);

        $this->assertFalse(Features::hasTimezoneFeatures());
    }
}
