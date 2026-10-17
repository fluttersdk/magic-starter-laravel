<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Enums\ReleaseStatus;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Models\AppRelease;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The public release feed: any client reads the published releases, no draft
 * ever leaves, every supported locale is filled, and the answer is cached until
 * a release is saved or deleted.
 *
 * The package routes are booted for real, so the throttle and the feature gate
 * apply exactly as they do in an app.
 */
class ReleaseControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--path' => __DIR__ . '/../../../database/migrations/create_app_releases_table.php',
            '--realpath' => true,
        ]);

        MagicStarter::reset();

        config([
            'magic-starter.supported_locales' => ['en', 'tr'],
            'magic-starter.defaults.locale' => 'en',
            'magic-starter.releases.limit' => 50,
        ]);

        $this->bootRoutesWithFeatures([Features::releases()]);
    }

    protected function tearDown(): void
    {
        MagicStarter::reset();
        parent::tearDown();
    }

    public function test_index_returns_the_exact_shape_to_a_guest(): void
    {
        $this->makeRelease(
            version: '1.1.0',
            publishedAt: Carbon::parse('2026-10-20 09:00:00'),
            iosBuild: 15,
            androidBuild: 15,
            items: [
                [
                    'type' => 'new',
                    'text' => ['en' => 'Dark mode', 'tr' => 'Karanlik mod'],
                ],
            ],
        );

        $this->getJson('/api/v1/releases')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    [
                        'version' => '1.1.0',
                        'published_at' => '2026-10-20T09:00:00.000000Z',
                        'ios_build' => 15,
                        'android_build' => 15,
                        'items' => [
                            [
                                'type' => 'new',
                                'text' => ['en' => 'Dark mode', 'tr' => 'Karanlik mod'],
                            ],
                        ],
                    ],
                ],
                'meta' => [
                    'latest_build' => ['ios' => 15, 'android' => 15],
                ],
            ]);
    }

    public function test_index_never_serves_a_draft_or_a_published_row_without_a_date(): void
    {
        $this->makeRelease('1.0.0', publishedAt: now()->subDay());
        $this->makeRelease('1.1.0', ReleaseStatus::DRAFT, now()->subDay());
        $this->makeRelease('1.2.0', publishedAt: null);

        $this->getJson('/api/v1/releases')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.version', '1.0.0');
    }

    public function test_index_orders_by_published_at_then_id_descending(): void
    {
        $this->makeRelease('1.0.0', publishedAt: Carbon::parse('2026-10-01 09:00:00'));
        $this->makeRelease('1.2.0', publishedAt: Carbon::parse('2026-10-03 09:00:00'));
        $this->makeRelease('1.1.0', publishedAt: Carbon::parse('2026-10-03 09:00:00'));

        $tied = AppRelease::query()
            ->whereIn('version', ['1.1.0', '1.2.0'])
            ->orderByDesc('id')
            ->pluck('version')
            ->all();

        $this->getJson('/api/v1/releases')
            ->assertOk()
            ->assertJsonPath('data.0.version', $tied[0])
            ->assertJsonPath('data.1.version', $tied[1])
            ->assertJsonPath('data.2.version', '1.0.0');
    }

    public function test_index_respects_the_configured_limit_but_latest_build_spans_every_published_row(): void
    {
        config(['magic-starter.releases.limit' => 2]);

        $this->makeRelease('1.0.0', publishedAt: now()->subDays(3), iosBuild: 90, androidBuild: 91);
        $this->makeRelease('1.1.0', publishedAt: now()->subDays(2), iosBuild: 10, androidBuild: 11);
        $this->makeRelease('1.2.0', publishedAt: now()->subDay(), iosBuild: 12, androidBuild: 13);

        $this->getJson('/api/v1/releases')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.version', '1.2.0')
            ->assertJsonPath('data.1.version', '1.1.0')
            ->assertJsonPath('meta.latest_build.ios', 90)
            ->assertJsonPath('meta.latest_build.android', 91);
    }

    public function test_index_fills_a_null_locale_with_the_default_locale_text(): void
    {
        $this->makeRelease('1.0.0', publishedAt: now()->subDay(), items: [
            [
                'type' => 'fixed',
                'text' => ['en' => 'Crash fixed', 'tr' => null],
            ],
        ]);

        $this->getJson('/api/v1/releases')
            ->assertOk()
            ->assertJsonPath('data.0.items.0.text.tr', 'Crash fixed')
            ->assertJsonPath('data.0.items.0.text.en', 'Crash fixed');
    }

    public function test_index_fills_an_empty_locale_with_the_default_locale_text(): void
    {
        $this->makeRelease('1.0.0', publishedAt: now()->subDay(), items: [
            [
                'type' => 'improved',
                'text' => ['en' => 'Faster sync', 'tr' => ''],
            ],
        ]);

        $this->getJson('/api/v1/releases')
            ->assertOk()
            ->assertJsonPath('data.0.items.0.text.tr', 'Faster sync');
    }

    public function test_index_fills_a_locale_the_item_does_not_carry_at_all(): void
    {
        $this->makeRelease('1.0.0', publishedAt: now()->subDay(), items: [
            [
                'type' => 'new',
                'text' => ['en' => 'Widgets'],
            ],
        ]);

        $this->getJson('/api/v1/releases')
            ->assertOk()
            ->assertJsonPath('data.0.items.0.text', ['en' => 'Widgets', 'tr' => 'Widgets']);
    }

    public function test_latest_build_ignores_a_draft_with_a_higher_build(): void
    {
        $this->makeRelease('1.0.0', publishedAt: now()->subDay(), iosBuild: 10, androidBuild: 11);
        $this->makeRelease('2.0.0', ReleaseStatus::DRAFT, now(), iosBuild: 99, androidBuild: 98);

        $this->getJson('/api/v1/releases')
            ->assertOk()
            ->assertJsonPath('meta.latest_build.ios', 10)
            ->assertJsonPath('meta.latest_build.android', 11);
    }

    public function test_latest_build_is_null_for_a_platform_no_published_release_carries(): void
    {
        $this->makeRelease('1.0.0', publishedAt: now()->subDay(), iosBuild: 10);

        $this->getJson('/api/v1/releases')
            ->assertOk()
            ->assertJsonPath('meta.latest_build.ios', 10)
            ->assertJsonPath('meta.latest_build.android', null);
    }

    public function test_latest_build_is_null_on_both_platforms_with_nothing_published(): void
    {
        $this->makeRelease('1.0.0', ReleaseStatus::DRAFT, now(), iosBuild: 10, androidBuild: 11);

        $this->getJson('/api/v1/releases')
            ->assertOk()
            ->assertExactJson([
                'data' => [],
                'meta' => [
                    'latest_build' => ['ios' => null, 'android' => null],
                ],
            ]);
    }

    public function test_second_request_is_served_from_cache_until_a_release_is_saved(): void
    {
        $release = $this->makeRelease('1.0.0', publishedAt: now()->subDays(2));

        $this->getJson('/api/v1/releases')->assertJsonCount(1, 'data');

        // A raw insert fires no model event, so only a stale cache can hide it.
        $this->insertWithoutEvents('1.1.0', now()->subDay());

        $this->getJson('/api/v1/releases')->assertJsonCount(1, 'data');

        $release->update(['android_build' => 7]);

        $this->getJson('/api/v1/releases')->assertJsonCount(2, 'data');
    }

    public function test_cache_is_busted_when_a_release_is_deleted(): void
    {
        $this->makeRelease('1.0.0', publishedAt: now()->subDays(2));
        $newest = $this->makeRelease('1.1.0', publishedAt: now()->subDay());

        $this->getJson('/api/v1/releases')->assertJsonCount(2, 'data');

        $newest->delete();

        $this->getJson('/api/v1/releases')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.version', '1.0.0');
    }

    public function test_index_is_throttled_per_ip_at_sixty_requests_a_minute(): void
    {
        for ($attempt = 1; $attempt <= 60; $attempt++) {
            $this->getJson('/api/v1/releases')->assertOk();
        }

        $this->getJson('/api/v1/releases')->assertStatus(429);
    }

    public function test_route_is_missing_with_the_feature_off(): void
    {
        $this->bootRoutesWithFeatures([]);

        $this->getJson('/api/v1/releases')->assertNotFound();
    }

    /**
     * @param  list<array{type: string, text: array<string, string|null>}>  $items
     */
    private function makeRelease(
        string $version,
        ReleaseStatus $status = ReleaseStatus::PUBLISHED,
        ?Carbon $publishedAt = null,
        ?int $iosBuild = null,
        ?int $androidBuild = null,
        array $items = [],
    ): AppRelease {
        return AppRelease::query()->create([
            'version' => $version,
            'status' => $status,
            'published_at' => $publishedAt,
            'ios_build' => $iosBuild,
            'android_build' => $androidBuild,
            'items' => $items,
        ]);
    }

    private function insertWithoutEvents(string $version, Carbon $publishedAt): void
    {
        DB::table('app_releases')->insert([
            'id' => (string) Str::orderedUuid(),
            'version' => $version,
            'status' => ReleaseStatus::PUBLISHED->value,
            'published_at' => $publishedAt,
            'items' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  list<string>  $features
     */
    private function bootRoutesWithFeatures(array $features): void
    {
        config([
            'magic-starter.features' => $features,
            'magic-starter.route_prefix' => 'api/v1',
        ]);

        $this->app['router']->setRoutes(new RouteCollection);

        (new MagicStarterServiceProvider($this->app))->boot();
    }
}
