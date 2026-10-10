<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\BillingEvents\Pages;

use Filament\Resources\Pages\ListRecords;
use FlutterSdk\MagicStarter\Filament\Resources\BillingEvents\BillingEventResource;

class ListBillingEvents extends ListRecords
{
    protected static string $resource = BillingEventResource::class;
}
