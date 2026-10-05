<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\Audits;

use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use FlutterSdk\MagicStarter\Audit\Audit;
use FlutterSdk\MagicStarter\Filament\Resources\Audits\Pages\ListAudits;
use FlutterSdk\MagicStarter\Filament\Resources\Audits\Pages\ViewAudit;
use FlutterSdk\MagicStarter\Filament\Resources\MagicStarterResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The audit trail, read-only: a row is history and is never edited or deleted
 * from the panel. Retention is the prune command's job.
 */
class AuditResource extends MagicStarterResource
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $recordTitleAttribute = 'event';

    protected static function resolveModel(): string
    {
        return Audit::class;
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('magic-starter::admin_misc.audits.plural_model_label');
    }

    public static function getModelLabel(): string
    {
        return (string) __('magic-starter::admin_misc.audits.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('magic-starter::admin_misc.audits.plural_model_label');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('event')
                    ->label(__('magic-starter::admin_misc.audits.columns.event'))
                    ->badge()
                    ->searchable(),
                TextColumn::make('auditable_type')
                    ->label(__('magic-starter::admin_misc.audits.columns.subject_type')),
                TextColumn::make('auditable_id')
                    ->label(__('magic-starter::admin_misc.audits.columns.subject_id'))
                    ->searchable(),
                TextColumn::make('actor_id')
                    ->label(__('magic-starter::admin_misc.audits.columns.actor'))
                    ->description(static fn (Audit $record): ?string => $record->actor_type)
                    ->searchable(),
                TextColumn::make('created_at')
                    ->label(__('magic-starter::admin_misc.audits.columns.created_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->label(__('magic-starter::admin_misc.audits.filters.event'))
                    ->options(static fn (): array => static::distinctValues('event')),
                SelectFilter::make('auditable_type')
                    ->label(__('magic-starter::admin_misc.audits.filters.subject_type'))
                    ->options(static fn (): array => static::distinctValues('auditable_type')),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('created_from')
                            ->label(__('magic-starter::admin_misc.audits.filters.created_from')),
                        DatePicker::make('created_until')
                            ->label(__('magic-starter::admin_misc.audits.filters.created_until')),
                    ])
                    ->query(static fn (Builder $query, array $data): Builder => $query
                        ->when($data['created_from'] ?? null, static fn (Builder $query, string $date): Builder => $query
                            ->where('created_at', '>=', Carbon::parse($date)->startOfDay()))
                        ->when($data['created_until'] ?? null, static fn (Builder $query, string $date): Builder => $query
                            ->where('created_at', '<=', Carbon::parse($date)->endOfDay()))),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->schema([
                    TextEntry::make('event')
                        ->label(__('magic-starter::admin_misc.audits.view.event'))
                        ->badge(),
                    TextEntry::make('created_at')
                        ->label(__('magic-starter::admin_misc.audits.view.created_at'))
                        ->dateTime(),
                    TextEntry::make('auditable_type')
                        ->label(__('magic-starter::admin_misc.audits.view.subject_type')),
                    TextEntry::make('auditable_id')
                        ->label(__('magic-starter::admin_misc.audits.view.subject_id')),
                    TextEntry::make('actor_type')
                        ->label(__('magic-starter::admin_misc.audits.view.actor_type')),
                    TextEntry::make('actor_id')
                        ->label(__('magic-starter::admin_misc.audits.view.actor_id')),
                    TextEntry::make('related_user_id')
                        ->label(__('magic-starter::admin_misc.audits.view.related_user')),
                ]),
            Grid::make(2)->schema([
                Section::make(__('magic-starter::admin_misc.audits.view.old_values'))
                    ->schema([
                        static::valuesEntry('old_values'),
                    ]),
                Section::make(__('magic-starter::admin_misc.audits.view.new_values'))
                    ->schema([
                        static::valuesEntry('new_values'),
                    ]),
            ]),
            Section::make(__('magic-starter::admin_misc.audits.view.context'))
                ->schema([
                    static::valuesEntry('context'),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAudits::route('/'),
            'view' => ViewAudit::route('/{record}'),
        ];
    }

    /**
     * One audit column as a field and value table.
     *
     * Each value is rendered as text: a bool, a number or a nested array would
     * otherwise print as `1`, an empty string or an array-to-string notice.
     * The redaction marker is already a plain string, so it passes through.
     */
    protected static function valuesEntry(string $column): KeyValueEntry
    {
        return KeyValueEntry::make($column)
            ->hiddenLabel()
            ->keyLabel(__('magic-starter::admin_misc.audits.view.field'))
            ->valueLabel(__('magic-starter::admin_misc.audits.view.value'))
            ->state(static fn (Audit $record): array => array_map(
                static fn (mixed $value): string => is_string($value) ? $value : (string) json_encode($value),
                $record->{$column} ?? [],
            ));
    }

    /**
     * The distinct values stored in a column, as filter options.
     *
     * @return array<string, string>
     */
    protected static function distinctValues(string $column): array
    {
        return static::getModel()::query()
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column, $column)
            ->all();
    }
}
