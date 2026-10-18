<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\Campaigns\Pages;

use Filament\Resources\Pages\EditRecord;
use FlutterSdk\MagicStarter\Filament\Concerns\WritesThroughContracts;
use FlutterSdk\MagicStarter\Filament\Resources\Campaigns\CampaignResource;

class EditCampaign extends EditRecord
{
    use WritesThroughContracts;

    protected static string $resource = CampaignResource::class;
}
