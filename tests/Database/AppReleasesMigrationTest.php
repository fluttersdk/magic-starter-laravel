<?php

namespace FlutterSdk\MagicStarter\Tests\Database;

use FlutterSdk\MagicStarter\Models\AppRelease;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Locks the app releases stub to the shape the release notes read and write.
 *
 * The stub is `hasTable` guarded, so a table an application already built keeps
 * its own shape; the fresh shape has to be right the first time.
 */
class AppReleasesMigrationTest extends TestCase
{
    /**
     * @return array<string, array{bool}>
     */
    public static function keyModes(): array
    {
        return [
            'uuid keys' => [
                true,
            ],
            'integer keys' => [
                false,
            ],
        ];
    }

    #[DataProvider('keyModes')]
    public function test_it_creates_the_table_with_its_columns_and_indexes(bool $useUuids): void
    {
        config()->set('magic-starter.use_uuids', $useUuids);

        $this->migrate('create_app_releases_table.php');

        $this->assertTrue(Schema::hasColumns('app_releases', [
            'id',
            'version',
            'status',
            'published_at',
            'ios_build',
            'android_build',
            'items',
            'created_at',
            'updated_at',
        ]));
        $this->assertFalse(Schema::hasColumn('app_releases', 'team_id'));
        $this->assertFalse(Schema::hasColumn('app_releases', 'deleted_at'));
        $this->assertTrue(Schema::hasIndex('app_releases', ['version'], 'unique'));
        $this->assertTrue(Schema::hasIndex('app_releases', ['status']));
    }

    public function test_it_refuses_a_second_row_for_the_same_version(): void
    {
        $this->migrate('create_app_releases_table.php');
        $this->makeRelease('1.4.0');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->makeRelease('1.4.0');
    }

    public function test_it_defaults_a_new_row_to_draft_with_no_builds_and_no_publish_date(): void
    {
        $this->migrate('create_app_releases_table.php');

        $release = $this->makeRelease('1.4.0')->fresh();

        $this->assertSame('draft', $release->getRawOriginal('status'));
        $this->assertNull($release->published_at);
        $this->assertNull($release->ios_build);
        $this->assertNull($release->android_build);
    }

    public function test_it_round_trips_items_as_a_php_list(): void
    {
        $this->migrate('create_app_releases_table.php');
        $items = [
            [
                'type' => 'new',
                'text' => 'Dark mode',
            ],
            [
                'type' => 'fixed',
                'text' => 'Sign-in loop',
            ],
        ];

        $release = $this->makeRelease('1.4.0', $items)->fresh();

        $this->assertSame($items, $release->items);
        $this->assertTrue(array_is_list($release->items));
    }

    public function test_it_is_a_no_op_when_the_table_already_exists(): void
    {
        Schema::create('app_releases', function (Blueprint $table): void {
            $table->id();
            $table->string('legacy_column');
        });

        $this->migrate('create_app_releases_table.php');

        $this->assertTrue(Schema::hasColumn('app_releases', 'legacy_column'));
        $this->assertFalse(Schema::hasColumn('app_releases', 'version'));
    }

    /**
     * @param  list<array<string, string>>  $items
     */
    private function makeRelease(string $version, array $items = []): AppRelease
    {
        return AppRelease::query()->create([
            'version' => $version,
            'items' => $items,
        ]);
    }

    /**
     * Run one of the package's migration stubs by file name.
     */
    private function migrate(string $file): void
    {
        $this->artisan('migrate', [
            '--path' => __DIR__ . '/../../database/migrations/' . $file,
            '--realpath' => true,
        ]);
    }
}
