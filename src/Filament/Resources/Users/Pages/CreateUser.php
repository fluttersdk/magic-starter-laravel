<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\Users\Pages;

use Filament\Resources\Pages\CreateRecord;
use FlutterSdk\MagicStarter\Filament\Concerns\WritesThroughContracts;
use FlutterSdk\MagicStarter\Filament\Resources\Users\UserResource;

class CreateUser extends CreateRecord
{
    use WritesThroughContracts;

    protected static string $resource = UserResource::class;
}
