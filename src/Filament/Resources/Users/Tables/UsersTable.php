<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\Users\Tables;

use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Filament\Resources\Users\UserResource;
use Illuminate\Database\Eloquent\Model;

/**
 * The user directory. Columns follow the features that fill them; there is no
 * delete and no bulk action, so a row can only be opened.
 */
class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns(static::columns())
            ->filters(static::filters())
            ->recordActions([
                Action::make('edit')
                    ->label(__('magic-starter::admin_users.actions.edit'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->url(static fn (Model $record): string => UserResource::getUrl('edit', ['record' => $record])),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * @return list<TextColumn|IconColumn>
     */
    protected static function columns(): array
    {
        $columns = [
            TextColumn::make('name')
                ->label(__('magic-starter::admin_users.columns.name'))
                ->searchable()
                ->sortable(),
            TextColumn::make('email')
                ->label(__('magic-starter::admin_users.columns.email'))
                ->searchable()
                ->sortable(),
            IconColumn::make('email_verified_at')
                ->label(__('magic-starter::admin_users.columns.verified'))
                ->boolean(),
        ];

        if (Features::hasTwoFactorAuthenticationFeatures()) {
            $columns[] = IconColumn::make('two_factor_confirmed_at')
                ->label(__('magic-starter::admin_users.columns.two_factor_confirmed'))
                ->boolean();
        }

        if (Features::hasGuestAuthFeatures()) {
            $columns[] = IconColumn::make('is_guest')
                ->label(__('magic-starter::admin_users.columns.guest'))
                ->boolean()
                ->trueColor('warning')
                ->falseColor('gray');
        }

        $columns[] = IconColumn::make('deletion_scheduled_at')
            ->label(__('magic-starter::admin_users.columns.deletion_scheduled'))
            ->boolean()
            ->trueColor('danger')
            ->falseColor('gray');

        if (Features::hasTeamFeatures()) {
            $columns[] = TextColumn::make('teams_count')
                ->label(__('magic-starter::admin_users.columns.teams'))
                ->counts('teams')
                ->sortable();
        }

        $columns[] = TextColumn::make('created_at')
            ->label(__('magic-starter::admin_users.columns.created_at'))
            ->dateTime()
            ->sortable();

        return $columns;
    }

    /**
     * @return list<TernaryFilter>
     */
    protected static function filters(): array
    {
        $filters = [
            TernaryFilter::make('verified')
                ->label(__('magic-starter::admin_users.filters.verified'))
                ->attribute('email_verified_at')
                ->nullable(),
            TernaryFilter::make('scheduled')
                ->label(__('magic-starter::admin_users.filters.scheduled'))
                ->attribute('deletion_scheduled_at')
                ->nullable(),
        ];

        if (Features::hasGuestAuthFeatures()) {
            $filters[] = TernaryFilter::make('guest')
                ->label(__('magic-starter::admin_users.filters.guest'))
                ->attribute('is_guest');
        }

        return $filters;
    }
}
