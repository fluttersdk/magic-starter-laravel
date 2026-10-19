<?php

namespace FlutterSdk\MagicStarter\Filament\Resources;

use Closure;
use Filament\Resources\Resource;
use FlutterSdk\MagicStarter\Filament\MagicStarterPlugin;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;
use UnitEnum;

/**
 * Base for every resource the plugin mounts.
 *
 * - The model is resolved at call time through {@see resolveModel()}: the
 *   starter's models are swappable through `MagicStarter::use*Model()`, which a
 *   static `$model` frozen at class load would miss.
 * - Never auto-discovered: the plugin decides what is mounted, under its gates.
 * - Policies are skipped: panel access is the gate, and the explicit refusals
 *   (deleting an owner, a last admin) live in the resources and the contracts.
 * - A record id in the url that cannot be a uuid binds to nothing, which Filament answers as a
 *   404, when the model keys on uuids: PostgreSQL refuses to compare a uuid column with text
 *   (SQLSTATE 22P02) and the panel would answer 500.
 * - Writes leave through {@see updateRecordUsing()} and {@see createRecordUsing()},
 *   which call the package contracts rather than `Model::update()`.
 *
 * @extends resource<Model>
 */
abstract class MagicStarterResource extends Resource
{
    protected static bool $isDiscovered = false;

    /**
     * The model class as configured right now, such as `MagicStarter::userModel()`.
     *
     * @return class-string<Model>
     */
    abstract protected static function resolveModel(): string;

    /**
     * @return class-string<Model>
     */
    public static function getModel(): string
    {
        return static::resolveModel();
    }

    /**
     * Fixed rather than read from `$shouldSkipAuthorization`: the property is
     * shared static state, and one `skipAuthorization(false)` call would turn
     * policy lookups back on for every package resource at once.
     */
    public static function shouldSkipAuthorization(): bool
    {
        return true;
    }

    /**
     * Answers null without a query when the model keys on uuids and the id is not one.
     */
    public static function resolveRecordRouteBinding(int|string $key, ?Closure $modifyQuery = null): ?Model
    {
        if (static::cannotBeUuidKey($key)) {
            return null;
        }

        return parent::resolveRecordRouteBinding($key, $modifyQuery);
    }

    /**
     * The one group the application named for every package resource, else the
     * resource's own ({@see \FlutterSdk\MagicStarter\Filament\AdminNavigationGroup}).
     */
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return MagicStarterPlugin::get()->getNavigationGroup() ?? static::$navigationGroup;
    }

    /**
     * Persist an edit through the package contract that owns it.
     *
     * @param  array<string, mixed>  $data  The validated form state.
     *
     * @throws LogicException when the resource has no edit page to reach this
     */
    public static function updateRecordUsing(Model $record, array $data, Authenticatable $actor): Model
    {
        throw new LogicException('[' . static::class . '] does not support editing records.');
    }

    /**
     * Create a record through the package contract that owns it.
     *
     * @param  array<string, mixed>  $data  The validated form state.
     *
     * @throws LogicException when the resource has no create page to reach this
     */
    public static function createRecordUsing(array $data, Authenticatable $actor): Model
    {
        throw new LogicException('[' . static::class . '] does not support creating records.');
    }

    /**
     * Whether the id is looked up against a uuid primary key and is not a uuid.
     *
     * Only the package's own uuid models are judged: a host model that keys on something else, or
     * a resource bound to another column, goes to the database as before.
     */
    protected static function cannotBeUuidKey(int|string $key): bool
    {
        $model = app(static::getModel());

        $keysOnUuids = MigrationHelper::usesUuids()
            && in_array(ConditionallyUsesUuids::class, class_uses_recursive($model), true);

        if (! $keysOnUuids) {
            return false;
        }

        $routeKey = static::getRecordRouteKeyName() ?? $model->getRouteKeyName();

        return $routeKey === $model->getKeyName() && ! Str::isUuid((string) $key);
    }
}
