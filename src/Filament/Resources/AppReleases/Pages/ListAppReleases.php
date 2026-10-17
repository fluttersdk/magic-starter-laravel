<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\AppReleases\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use FlutterSdk\MagicStarter\Filament\Resources\AppReleases\AppReleaseResource;

class ListAppReleases extends ListRecords
{
    protected static string $resource = AppReleaseResource::class;

    /**
     * A link to the create page rather than Filament's create action, which would build the
     * model itself and skip the contract.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label(__('magic-starter::admin_misc.releases.actions.create'))
                ->icon(Heroicon::OutlinedPlus)
                ->url(static fn (): string => AppReleaseResource::getUrl('create')),
        ];
    }
}
