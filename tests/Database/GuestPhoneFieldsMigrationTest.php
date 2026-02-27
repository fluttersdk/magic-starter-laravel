<?php

namespace FlutterSdk\MagicStarter\Tests\Database;

use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Support\Facades\Schema;

class GuestPhoneFieldsMigrationTest extends TestCase
{
    /**
     * Test the migration "up" and "down" methods.
     */
    public function test_migration_up_and_down_work_correctly(): void
    {
        // 1. Prepare: Run only the base create_users_table migration first
        $this->artisan('migrate', [
            '--path' => __DIR__ . '/../../database/migrations/create_users_table.php',
            '--realpath' => true,
        ]);

        $migration = require __DIR__ . '/../../database/migrations/add_guest_and_phone_fields_to_users_table.php';

        // 2. Run Migration "up"
        $migration->up();

        // 3. Assert columns exist
        $this->assertTrue(Schema::hasColumn('users', 'is_guest'));
        $this->assertTrue(Schema::hasColumn('users', 'device_id'));
        $this->assertTrue(Schema::hasColumn('users', 'phone_country'));

        // 4. Run Migration "down"
        $migration->down();

        // 5. Assert columns are removed
        $this->assertFalse(Schema::hasColumn('users', 'is_guest'));
        $this->assertFalse(Schema::hasColumn('users', 'device_id'));
        $this->assertFalse(Schema::hasColumn('users', 'phone_country'));
    }
}
