<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\Users\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The teams the user belongs to, read only: a membership changes through the
 * team's own page, where the contracts that guard owners and roles are run.
 */
class TeamsRelationManager extends RelationManager
{
    protected static string $relationship = 'teams';

    protected static bool $shouldSkipAuthorization = true;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return (string) __('magic-starter::admin_users.relations.teams.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('magic-starter::admin_users.relations.teams.name')),
                TextColumn::make('pivot.role')
                    ->label(__('magic-starter::admin_users.relations.teams.role'))
                    ->badge(),
                IconColumn::make('personal_team')
                    ->label(__('magic-starter::admin_users.relations.teams.personal_team'))
                    ->boolean(),
            ]);
    }
}
