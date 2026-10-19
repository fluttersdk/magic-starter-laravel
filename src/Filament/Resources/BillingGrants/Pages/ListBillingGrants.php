<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\BillingGrants\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use FlutterSdk\MagicStarter\Filament\Actions\GrantPlanAction;
use FlutterSdk\MagicStarter\Filament\Resources\BillingGrants\BillingGrantResource;

class ListBillingGrants extends ListRecords
{
    protected static string $resource = BillingGrantResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            GrantPlanAction::make(),
        ];
    }
}
