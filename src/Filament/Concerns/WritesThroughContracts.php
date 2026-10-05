<?php

namespace FlutterSdk\MagicStarter\Filament\Concerns;

use Filament\Facades\Filament;
use FlutterSdk\MagicStarter\Filament\Resources\MagicStarterResource;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Routes a create or edit page's save through its resource's contract hooks.
 *
 * Filament's default save is `$record->update($data)` and `new $model($data)`,
 * which would bypass the package contracts (their validation, their refusals,
 * their side effects). Use on an `EditRecord` or `CreateRecord` page whose
 * resource extends {@see MagicStarterResource}.
 */
trait WritesThroughContracts
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var class-string<MagicStarterResource> $resource */
        $resource = static::getResource();

        return $resource::updateRecordUsing($record, $data, $this->panelActor());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var class-string<MagicStarterResource> $resource */
        $resource = static::getResource();

        return $resource::createRecordUsing($data, $this->panelActor());
    }

    /**
     * @throws AuthenticationException when the page runs without a panel user
     */
    private function panelActor(): Authenticatable
    {
        return Filament::auth()->user() ?? throw new AuthenticationException;
    }
}
