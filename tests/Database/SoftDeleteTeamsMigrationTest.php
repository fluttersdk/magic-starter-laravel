<?php

namespace FlutterSdk\MagicStarter\Tests\Database;

use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The opt-in `deleted_at` and `restore_until` columns of the teams table.
 */
final class SoftDeleteTeamsMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('teams', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function test_it_adds_the_soft_delete_column_and_an_indexed_restore_deadline(): void
    {
        $this->migration()->up();

        $this->assertTrue(Schema::hasColumns('teams', [
            'deleted_at',
            'restore_until',
        ]));
        $this->assertTrue(Schema::hasIndex('teams', ['restore_until']));
    }

    public function test_it_can_run_twice(): void
    {
        $this->migration()->up();
        $this->migration()->up();

        $this->assertTrue(Schema::hasColumns('teams', [
            'deleted_at',
            'restore_until',
        ]));
    }

    public function test_it_leaves_an_existing_team_live(): void
    {
        Schema::getConnection()->table('teams')->insert([
            'id' => 'a7f0d6f4-6a2c-4f6a-9f57-0d2f8d8a1b11',
            'name' => 'Home',
        ]);

        $this->migration()->up();

        $row = Schema::getConnection()->table('teams')->first();
        $this->assertNull($row->deleted_at);
        $this->assertNull($row->restore_until);
    }

    public function test_down_drops_both_columns_and_the_index(): void
    {
        $this->migration()->up();

        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('teams', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('teams', 'restore_until'));
        $this->assertTrue(Schema::hasColumn('teams', 'name'));
    }

    public function test_down_is_safe_on_a_table_that_never_got_the_columns(): void
    {
        $this->migration()->down();

        $this->assertTrue(Schema::hasTable('teams'));
    }

    private function migration(): Migration
    {
        return require __DIR__ . '/../../database/migrations/add_soft_deletes_to_teams_table.php';
    }
}
