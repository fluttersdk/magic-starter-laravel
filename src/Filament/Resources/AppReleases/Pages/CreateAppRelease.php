<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\AppReleases\Pages;

use Filament\Resources\Pages\CreateRecord;
use FlutterSdk\MagicStarter\Filament\Concerns\WritesThroughContracts;
use FlutterSdk\MagicStarter\Filament\Resources\AppReleases\AppReleaseResource;

class CreateAppRelease extends CreateRecord
{
    use WritesThroughContracts;

    protected static string $resource = AppReleaseResource::class;
}
