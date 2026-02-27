<?php

namespace FlutterSdk\MagicStarter\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Database\Schema\ForeignIdColumnDefinition;

/**
 * Configuration-aware helpers for package migrations.
 *
 * Provides column type methods that respect the `magic-starter.use_uuids`
 * config setting, allowing all migrations to work with either UUID or
 * auto-incrementing integer primary keys.
 */
class MigrationHelper
{
    /**
     * Determine whether the package is configured to use UUID primary keys.
     */
    public static function usesUuids(): bool
    {
        return (bool) config(
            'magic-starter.use_uuids',
            true,
        );
    }

    /**
     * Add a primary key column to the given table.
     *
     * When UUIDs are enabled, creates a `uuid('id')->primary()` column.
     * When disabled, creates a standard `id()` auto-incrementing column.
     *
     * @param  Blueprint  $table  The table blueprint being built.
     */
    public static function primaryKey(Blueprint $table): ColumnDefinition
    {
        if (static::usesUuids()) {
            return $table
                ->uuid('id')
                ->primary();
        }

        return $table->id();
    }

    /**
     * Add a foreign key column referencing a UUID or integer primary key.
     *
     * When UUIDs are enabled, creates a `foreignUuid()` column.
     * When disabled, creates a standard `foreignId()` column.
     *
     * @param  Blueprint  $table  The table blueprint being built.
     * @param  string  $column  The column name (e.g., 'user_id', 'team_id').
     */
    public static function foreignKey(
        Blueprint $table,
        string $column,
    ): ForeignIdColumnDefinition {
        if (static::usesUuids()) {
            return $table->foreignUuid($column);
        }

        return $table->foreignId($column);
    }

    /**
     * Add polymorphic morph columns (morphable_type + morphable_id).
     *
     * When UUIDs are enabled, creates `uuidMorphs()`.
     * When disabled, creates standard `morphs()`.
     *
     * @param  Blueprint  $table  The table blueprint being built.
     * @param  string  $name  The morph name (e.g., 'tokenable', 'notifiable').
     */
    public static function morphColumns(
        Blueprint $table,
        string $name,
    ): void {
        if (static::usesUuids()) {
            $table->uuidMorphs($name);
        } else {
            $table->morphs($name);
        }
    }
}
