<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\AppReleases;

use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FlutterSdk\MagicStarter\Contracts\CreatesAppReleases;
use FlutterSdk\MagicStarter\Contracts\UpdatesAppReleases;
use FlutterSdk\MagicStarter\Enums\ReleaseStatus;
use FlutterSdk\MagicStarter\Filament\Resources\AppReleases\Pages\CreateAppRelease;
use FlutterSdk\MagicStarter\Filament\Resources\AppReleases\Pages\EditAppRelease;
use FlutterSdk\MagicStarter\Filament\Resources\AppReleases\Pages\ListAppReleases;
use FlutterSdk\MagicStarter\Filament\Resources\AppReleases\Schemas\AppReleaseForm;
use FlutterSdk\MagicStarter\Filament\Resources\MagicStarterResource;
use FlutterSdk\MagicStarter\Filament\Support\ContractAction;
use FlutterSdk\MagicStarter\Models\AppRelease;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * The release notes clients read from the public releases feed.
 *
 * Writes leave through {@see CreatesAppReleases} and {@see UpdatesAppReleases}, and the
 * delete through the page's header action. There is no bulk action on the table: a bulk
 * delete would skip the model events that drop the cached feed.
 */
class AppReleaseResource extends MagicStarterResource
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?string $recordTitleAttribute = 'version';

    protected static function resolveModel(): string
    {
        return AppRelease::class;
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('magic-starter::admin_misc.releases.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return (string) __('magic-starter::admin_misc.releases.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('magic-starter::admin_misc.releases.plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return AppReleaseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('version')
                    ->label(__('magic-starter::admin_misc.releases.columns.version'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('magic-starter::admin_misc.releases.columns.status'))
                    ->badge()
                    ->formatStateUsing(static fn (ReleaseStatus $state): string => $state->label())
                    ->color(static fn (ReleaseStatus $state): string => match ($state) {
                        ReleaseStatus::DRAFT => 'gray',
                        ReleaseStatus::PUBLISHED => 'success',
                    })
                    ->sortable(),
                TextColumn::make('published_at')
                    ->label(__('magic-starter::admin_misc.releases.columns.published_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('ios_build')
                    ->label(__('magic-starter::admin_misc.releases.columns.ios_build'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('android_build')
                    ->label(__('magic-starter::admin_misc.releases.columns.android_build'))
                    ->numeric()
                    ->sortable(),
            ])
            ->defaultSort('published_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAppReleases::route('/'),
            'create' => CreateAppRelease::route('/create'),
            'edit' => EditAppRelease::route('/{record}/edit'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function createRecordUsing(array $data, Authenticatable $actor): Model
    {
        /** @var Model $release */
        $release = ContractAction::run(
            null,
            static fn () => app(CreatesAppReleases::class)->create($actor, $data),
            'release.created',
            null,
        );

        return $release;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function updateRecordUsing(Model $record, array $data, Authenticatable $actor): Model
    {
        ContractAction::run(
            null,
            static fn () => app(UpdatesAppReleases::class)->update($actor, $record, $data),
            'release.updated',
            $record,
        );

        return $record->refresh();
    }
}
