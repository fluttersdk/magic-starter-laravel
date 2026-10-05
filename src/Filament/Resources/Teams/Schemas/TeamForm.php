<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\Teams\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use FlutterSdk\MagicStarter\Filament\Resources\Teams\TeamResource;

/**
 * Edits the team name and nothing else; see {@see TeamResource} for why the
 * owner and billing columns never appear.
 */
class TeamForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('magic-starter::admin_teams.fields.name'))
                    ->required()
                    ->maxLength(255),
            ]);
    }
}
