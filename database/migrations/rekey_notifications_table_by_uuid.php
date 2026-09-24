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
 * the migration that does. On a table that is already right it does nothing.
 *
 * MySQL and SQLite run a migration outside a transaction, so every step is
 * written to be re-run after a failure part-way through.
 */
return new class extends Migration
{
    /**
     * The columns the rebuild carries over. A table with any other column is
     * refused rather than rebuilt, because the rebuild would drop it.
     *
     * @var list<string>
     */
    private const COLUMNS = [
        'id',
        'type',
        'notifiable_type',
        'notifiable_id',
        'data',
        'read_at',
        'created_at',
        'updated_at',
    ];

    private const SCRATCH = 'notifications_rekeyed';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Finish a run that stopped between dropping the old table and
        //    renaming the new one: the rows are all in the scratch table.
        if (! Schema::hasTable('notifications') && Schema::hasTable(self::SCRATCH)) {
            $this->promoteScratchTable();
        }

        if (! Schema::hasTable('notifications')) {
            return;
        }

        // 2. Rebuild only the table the old stub built.
        if ($this->keyedByAutoIncrement()) {
            $this->refuseUnknownColumns();
            $this->rebuild();
        }

        // 3. Also reached by a run that stopped before its indexes landed, whose
        //    table no longer looks like it needs rebuilding.
        $this->ensureIndexes();
    }

    /**
     * Reverse the migrations.
     *
     * Deliberately empty: restoring an auto-incrementing id would bring back a
     * table the database channel cannot write to.
     */
    public function down(): void {}

    /**
     * Copy the table into a UUID-keyed one and swap it in.
     */
    private function rebuild(): void
    {
        // 1. A scratch table beside the old one is left over from a run that
        //    failed while copying; the old table still holds every row.
        Schema::dropIfExists(self::SCRATCH);

        Schema::create(self::SCRATCH, function (Blueprint $table): void {
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

        // 2. Carry every row over under a fresh UUID. Rows reach this table only
        //    when a non-strict MySQL coerced the channel's UUID into a number, or
        //    when something created one through the model without an id; neither
        //    id identifies the notification anywhere a client can still use.
        DB::table('notifications')
            ->orderBy('id')
            ->chunk(500, function ($rows): void {
                DB::table(self::SCRATCH)->insert(
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

        // 3. Swap the tables.
        Schema::drop('notifications');
        $this->promoteScratchTable();
    }

    /**
     * Rename the scratch table to its final name, primary key included.
     */
    private function promoteScratchTable(): void
    {
        Schema::rename(self::SCRATCH, 'notifications');

        // PostgreSQL names a primary key after the table it was created on and a
        // rename keeps it, which would leave `dropPrimary()` looking for
        // `notifications_pkey` on a repaired install and finding nothing. Read
        // rather than assumed, since the scratch table may not be ours.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $name = DB::scalar(
            "select conname from pg_constraint where conrelid = 'notifications'::regclass and contype = 'p'",
        );

        if ($name !== null && $name !== 'notifications_pkey') {
            DB::statement(sprintf('alter table notifications rename constraint "%s" to notifications_pkey', $name));
        }
    }

    /**
     * Add whichever of the create stub's two indexes is missing, under its name.
     */
    private function ensureIndexes(): void
    {
        $indexes = [
            [
                'notifiable_type',
                'notifiable_id',
            ],
            [
                'notifiable_type',
                'notifiable_id',
                'read_at',
            ],
        ];

        foreach ($indexes as $columns) {
            if (Schema::hasIndex('notifications', $columns)) {
                continue;
            }

            Schema::table('notifications', fn (Blueprint $table) => $table->index($columns));
        }
    }

    /**
     * Stop before a rebuild that would drop a column the application added.
     *
     * @throws RuntimeException When the table carries a column the rebuild does not copy.
     */
    private function refuseUnknownColumns(): void
    {
        $unknown = array_values(array_diff(Schema::getColumnListing('notifications'), self::COLUMNS));

        if ($unknown === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            'The notifications table has an auto-incrementing id, which Laravel\'s database channel cannot '
                . 'write to, and carries columns this migration would drop by rebuilding it: %s. Change its id '
                . 'to a UUID primary key yourself, then run the migrations again.',
            implode(', ', $unknown),
        ));
    }

    /**
     * Whether the table's id is the auto-incrementing integer the old stub built.
     */
    private function keyedByAutoIncrement(): bool
    {
        $id = collect(Schema::getColumns('notifications'))->firstWhere('name', 'id');

        return (bool) ($id['auto_increment'] ?? false);
    }
};
