<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\AiPrompts\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use FlutterSdk\MagicStarter\Filament\Resources\AiPrompts\AiPromptOverrideResource;

class ListAiPromptOverrides extends ListRecords
{
    protected static string $resource = AiPromptOverrideResource::class;

    /**
     * A link to the create page rather than Filament's create action, which would build the
     * model itself and skip the contract.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label(__('magic-starter::admin_ai.prompts.actions.create'))
                ->icon(Heroicon::OutlinedPlus)
                ->url(static fn (): string => AiPromptOverrideResource::getUrl('create')),
        ];
    }
}
