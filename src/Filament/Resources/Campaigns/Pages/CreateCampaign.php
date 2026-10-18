<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\Campaigns\Pages;

use Filament\Resources\Pages\CreateRecord;
use FlutterSdk\MagicStarter\Filament\Concerns\WritesThroughContracts;
use FlutterSdk\MagicStarter\Filament\Resources\Campaigns\CampaignResource;

class CreateCampaign extends CreateRecord
{
    use WritesThroughContracts;

    protected static string $resource = CampaignResource::class;
}
