<?php

namespace FlutterSdk\MagicStarter\Tests\Database;

use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Locks the push devices stub to the shape the report endpoint writes.
 *
 * The stub is `hasTable` guarded, so an application that already built this
 * table (uptizm did, before the package owned it) keeps its own shape. That is
 * why the fresh shape has to be the same one: a guard that skips makes the
 * first shape the permanent one.
 */
class PushDevicesMigrationTest extends TestCase
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
        $this->migrate('create_users_table.php');

        $this->migrate('create_push_devices_table.php');

        $this->assertTrue(Schema::hasColumns('push_devices', [
            'id',
            'user_id',
            'external_id',
            'subscription_id',
            'reachability',
            'captured_at',
            'reported_at',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasIndex('push_devices', [
            'user_id',
            'subscription_id',
        ], 'unique'));
        $this->assertTrue(Schema::hasIndex('push_devices', [
            'user_id',
            'reported_at',
        ]));
    }

    public function test_it_is_a_no_op_when_the_table_already_exists(): void
    {
        Schema::create('push_devices', function (Blueprint $table): void {
            $table->id();
            $table->string('legacy_column');
        });

        $this->migrate('create_push_devices_table.php');

        $this->assertTrue(Schema::hasColumn('push_devices', 'legacy_column'));
        $this->assertFalse(Schema::hasColumn('push_devices', 'reachability'));
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
