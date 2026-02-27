<?php

namespace FlutterSdk\MagicStarter\Support;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

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
