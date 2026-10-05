<?php

namespace FlutterSdk\MagicStarter\Filament\Resources;

use Filament\Resources\Resource;
use FlutterSdk\MagicStarter\Filament\MagicStarterPlugin;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
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

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return static::$navigationGroup ?? MagicStarterPlugin::get()->getNavigationGroup();
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
}
