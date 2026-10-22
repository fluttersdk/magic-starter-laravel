<?php

namespace FlutterSdk\MagicStarter\Support;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * Conditionally applies UUID primary keys based on package configuration.
 *
 * When `magic-starter.use_uuids` is true, this trait activates the
 * HasUuids behavior and sets non-incrementing string keys. When false,
 * standard auto-incrementing integer keys are used.
 */
trait ConditionallyUsesUuids
{
    /**
     * Boot the conditionally-uses-uuids trait.
     *
     * Dynamically applies HasUuids boot logic and configures the model's
     * key type based on the `magic-starter.use_uuids` config setting.
     */
    public static function bootConditionallyUsesUuids(): void
    {
        if (MigrationHelper::usesUuids()) {
            // Boot HasUuids trait manually since we can't conditionally `use` traits.
            static::creating(function ($model) {
                if (empty($model->{$model->getKeyName()})) {
                    $model->{$model->getKeyName()} = (string) \Illuminate\Support\Str::orderedUuid();
                }
            });
        }
    }

    /**
     * Initialize the conditionally-uses-uuids trait on model instantiation.
     *
     * Sets `$incrementing` and `$keyType` based on configuration.
     */
    public function initializeConditionallyUsesUuids(): void
    {
        if (MigrationHelper::usesUuids()) {
            $this->incrementing = false;
            $this->keyType = 'string';
        }
    }

    /**
     * Treat a route key that cannot be a uuid as a missing model.
     *
     * `HasUuids` refuses such a key before it queries, and this trait has to do the same: PostgreSQL rejects a
     * malformed value against a uuid column, so the binding would end in a 500 where a missing model answers 404.
     * Only the primary key is checked; a route bound by another column (a slug) passes through untouched.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>|\Illuminate\Database\Eloquent\Relations\Relation<static, *, *>  $query
     * @param  mixed  $value
     * @param  string|null  $field
     * @return \Illuminate\Database\Eloquent\Builder<static>|\Illuminate\Database\Eloquent\Relations\Relation<static, *, *>
     *
     * @throws ModelNotFoundException<static>
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $bindsKey = ($field ?? $this->getRouteKeyName()) === $this->getKeyName();

        if (MigrationHelper::usesUuids() && $bindsKey && ! Str::isUuid($value)) {
            throw (new ModelNotFoundException)->setModel(static::class, $value);
        }

        return parent::resolveRouteBindingQuery($query, $value, $field);
    }

    /**
     * Get the columns that should receive a unique identifier.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        if (MigrationHelper::usesUuids()) {
            return [$this->getKeyName()];
        }

        return [];
    }
}
