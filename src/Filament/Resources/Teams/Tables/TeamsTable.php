<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\Teams\Tables;

use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FlutterSdk\MagicStarter\Features;

/**
 * Lists every team with its owner, size and, under the billing feature, its plan.
 */
class TeamsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('magic-starter::admin_teams.columns.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('owner.email')
                    ->label(__('magic-starter::admin_teams.columns.owner'))
                    ->searchable(),
                IconColumn::make('personal_team')
                    ->label(__('magic-starter::admin_teams.columns.personal'))
                    ->boolean(),
                TextColumn::make('users_count')
                    ->label(__('magic-starter::admin_teams.columns.members'))
                    ->counts('users')
                    ->sortable(),
                TextColumn::make('plan')
                    ->label(__('magic-starter::admin_teams.columns.plan'))
                    ->badge()
                    ->visible(static fn (): bool => Features::hasBillingFeatures()),
                TextColumn::make('plan_status')
                    ->label(__('magic-starter::admin_teams.columns.plan_status'))
                    ->badge()
                    ->visible(static fn (): bool => Features::hasBillingFeatures()),
                TextColumn::make('created_at')
                    ->label(__('magic-starter::admin_teams.columns.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
