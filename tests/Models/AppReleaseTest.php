<?php

namespace FlutterSdk\MagicStarter\Tests\Models;

use FlutterSdk\MagicStarter\Enums\ReleaseItemType;
use FlutterSdk\MagicStarter\Enums\ReleaseStatus;
use FlutterSdk\MagicStarter\Models\AppRelease;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Support\Carbon;

/**
 * Locks the casts of an app release and the `published()` scope the public
 * read depends on.
 */
class AppReleaseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--path' => __DIR__ . '/../../database/migrations/create_app_releases_table.php',
            '--realpath' => true,
        ]);
    }

    public function test_it_casts_its_columns(): void
    {
        $release = AppRelease::query()->create([
            'version' => '1.4.0',
            'status' => ReleaseStatus::PUBLISHED,
            'published_at' => '2026-10-01 09:30:00',
            'ios_build' => '120',
            'android_build' => '121',
            'items' => [
                [
                    'type' => 'new',
                    'text' => ['en' => 'Dark mode'],
                ],
            ],
        ])->fresh();

        $this->assertSame(ReleaseStatus::PUBLISHED, $release->status);
        $this->assertInstanceOf(Carbon::class, $release->published_at);
        $this->assertSame(120, $release->ios_build);
        $this->assertSame(121, $release->android_build);
        $this->assertSame('Dark mode', $release->items[0]['text']['en']);
    }

    public function test_published_scope_excludes_drafts_and_published_rows_without_a_date(): void
    {
        $this->makeRelease('1.0.0', ReleaseStatus::PUBLISHED, now()->subDay());
        $this->makeRelease('1.1.0', ReleaseStatus::DRAFT, now()->subDay());
        $this->makeRelease('1.2.0', ReleaseStatus::PUBLISHED, null);

        $versions = AppRelease::query()->published()->pluck('version')->all();

        $this->assertSame(['1.0.0'], $versions);
    }

    public function test_status_values_are_the_stored_words(): void
    {
        $this->assertSame('draft', ReleaseStatus::DRAFT->value);
        $this->assertSame('published', ReleaseStatus::PUBLISHED->value);
    }

    public function test_item_type_values_are_the_stored_words(): void
    {
        $this->assertSame('new', ReleaseItemType::NEW->value);
        $this->assertSame('improved', ReleaseItemType::IMPROVED->value);
        $this->assertSame('fixed', ReleaseItemType::FIXED->value);
    }

    private function makeRelease(string $version, ReleaseStatus $status, ?Carbon $publishedAt): AppRelease
    {
        return AppRelease::query()->create([
            'version' => $version,
            'status' => $status,
            'published_at' => $publishedAt,
            'items' => [],
        ]);
    }
}
