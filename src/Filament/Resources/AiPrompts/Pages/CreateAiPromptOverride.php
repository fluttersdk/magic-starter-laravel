<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\AiPrompts\Pages;

use Filament\Resources\Pages\CreateRecord;
use FlutterSdk\MagicStarter\Filament\Concerns\WritesThroughContracts;
use FlutterSdk\MagicStarter\Filament\Resources\AiPrompts\AiPromptOverrideResource;

class CreateAiPromptOverride extends CreateRecord
{
    use WritesThroughContracts;

    protected static string $resource = AiPromptOverrideResource::class;
}
