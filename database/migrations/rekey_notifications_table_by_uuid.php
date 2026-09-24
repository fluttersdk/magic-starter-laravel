<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Rebuilds a notifications table that an integer-key install created with an
 * auto-incrementing id.
 *
 * Laravel's database channel writes the notification's own UUID as the id, so
 * that table refused every delivery (SQLite, PostgreSQL and strict-mode MySQL
 * reject the value). The create stub is fixed for fresh installs, but its
 * `hasTable` guard means it never touches a table that already exists; this is
 * the migration that does. On any other table it is a no-op.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Nothing to repair: no table yet, or one already keyed by UUID.
        if (! Schema::hasTable('notifications') || ! $this->keyedByAutoIncrement()) {
            return;
        }

        // 2. Build the correctly keyed table beside the old one. Its indexes come
        //    after the swap, so they carry the names the create stub gives them.
        Schema::create('notifications_rekeyed', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->string('notifiable_type');
            MigrationHelper::usesUuids()
                ? $table->uuid('notifiable_id')
                : $table->unsignedBigInteger('notifiable_id');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        // 3. Carry every row over under a fresh UUID. Only a non-strict MySQL can
        //    have written one, by coercing the channel's UUID into a number, so the
        //    old id was never the notification's identity and nothing refers to it.
        DB::table('notifications')
            ->orderBy('id')
            ->chunk(500, function ($rows): void {
                DB::table('notifications_rekeyed')->insert(
                    $rows->map(fn (object $row): array => [
                        'id' => (string) Str::uuid(),
                        'type' => $row->type,
                        'notifiable_type' => $row->notifiable_type,
                        'notifiable_id' => $row->notifiable_id,
                        'data' => $row->data,
                        'read_at' => $row->read_at,
                        'created_at' => $row->created_at,
                        'updated_at' => $row->updated_at,
                    ])->all(),
                );
            });

        // 4. Swap the tables, then index the survivor under its final name.
        Schema::drop('notifications');
        Schema::rename('notifications_rekeyed', 'notifications');
        Schema::table('notifications', function (Blueprint $table): void {
            $table->index([
                'notifiable_type',
                'notifiable_id',
            ]);
            $table->index([
                'notifiable_type',
                'notifiable_id',
                'read_at',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     *
     * Deliberately empty: restoring an auto-incrementing id would bring back a
     * table the database channel cannot write to.
     */
    public function down(): void {}

    /**
     * Whether the table's id is the auto-incrementing integer the old stub built.
     */
    private function keyedByAutoIncrement(): bool
    {
        $id = collect(Schema::getColumns('notifications'))->firstWhere('name', 'id');

        return (bool) ($id['auto_increment'] ?? false);
    }
};
