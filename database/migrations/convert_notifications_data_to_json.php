<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turns a notifications payload column the create stub built as text into JSON.
 *
 * Filament's database notifications filter the bell on `data->>'format'`, and
 * PostgreSQL has no `->>` operator for text, so every panel page answered 500
 * ("operator does not exist: text ->> unknown"). The create and rekey stubs now
 * build the column as JSON, but the create's `hasTable` guard means that only
 * reaches a fresh install; this is the migration that converts an existing one.
 *
 * Only PostgreSQL needs it: SQLite stores JSON as text either way, and MySQL's
 * JSON functions read a text column. Every row the database channel wrote is
 * JSON-encoded, so the cast keeps them all.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! $this->payloadIs('text')) {
            return;
        }

        DB::statement($this->alterPayloadTo('json'));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! $this->payloadIs('json')) {
            return;
        }

        DB::statement($this->alterPayloadTo('text'));
    }

    /**
     * Whether this is a PostgreSQL table whose payload column has the given type.
     */
    private function payloadIs(string $type): bool
    {
        return DB::getDriverName() === 'pgsql'
            && Schema::hasTable('notifications')
            && Schema::getColumnType('notifications', 'data') === $type;
    }

    /**
     * The statement casting the payload column, through the grammar so a table
     * prefix is applied the same way the schema builder applies it.
     */
    private function alterPayloadTo(string $type): string
    {
        $grammar = DB::getQueryGrammar();
        $column = $grammar->wrap('data');

        return sprintf(
            'alter table %s alter column %s type %s using %s::%s',
            $grammar->wrapTable('notifications'),
            $column,
            $type,
            $column,
            $type,
        );
    }
};
