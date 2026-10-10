<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\BillingEvents;

use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use FlutterSdk\MagicStarter\Enums\BillingEventType;
use FlutterSdk\MagicStarter\Enums\BillingProvider;
use FlutterSdk\MagicStarter\Enums\BillingSource;
use FlutterSdk\MagicStarter\Filament\Resources\BillingEvents\Pages\ListBillingEvents;
use FlutterSdk\MagicStarter\Filament\Resources\BillingEvents\Pages\ViewBillingEvent;
use FlutterSdk\MagicStarter\Filament\Resources\MagicStarterResource;
use FlutterSdk\MagicStarter\Models\BillingEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The billing history, read-only: a row is a reading and is never edited or
 * deleted from the panel. Retention is the prune command's job.
 */
class BillingEventResource extends MagicStarterResource
{
    /**
     * Shown for a column a row does not carry (a rail-driven outcome has no
     * actor, a refusal may have no billable).
     */
    protected const ABSENT = '-';

    /**
     * Ids and the JSON of the properties break anywhere rather than stretching
     * their cell or the page, and keep the pretty-printed line breaks.
     */
    protected const WRAP_ANYWHERE = ['style' => 'overflow-wrap: anywhere; white-space: pre-wrap;'];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static function resolveModel(): string
    {
        return BillingEvent::class;
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('magic-starter::admin_misc.billing_events.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return (string) __('magic-starter::admin_misc.billing_events.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('magic-starter::admin_misc.billing_events.plural_model_label');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with('actor'))
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('magic-starter::admin_misc.billing_events.columns.created_at'))
                    ->dateTime()
                    ->sinceTooltip()
                    ->sortable(),
                TextColumn::make('type')
                    ->label(__('magic-starter::admin_misc.billing_events.columns.type'))
                    ->badge()
                    ->formatStateUsing(static fn (BillingEventType $state): string => $state->value)
                    ->color(static fn (BillingEventType $state): string => static::typeColor($state)),
                TextColumn::make('source')
                    ->label(__('magic-starter::admin_misc.billing_events.columns.source'))
                    ->formatStateUsing(static fn (BillingSource $state): string => $state->value),
                TextColumn::make('provider')
                    ->label(__('magic-starter::admin_misc.billing_events.columns.provider'))
                    ->formatStateUsing(static fn (?BillingProvider $state): ?string => $state?->label())
                    ->placeholder(static::ABSENT),
                TextColumn::make('billable')
                    ->label(__('magic-starter::admin_misc.billing_events.columns.billable'))
                    ->state(static fn (BillingEvent $record): ?string => static::billableLabel($record))
                    ->tooltip(static fn (BillingEvent $record): ?string => $record->billable_type)
                    ->placeholder(static::ABSENT)
                    ->searchable(['billable_type', 'billable_id']),
                TextColumn::make('reason')
                    ->label(__('magic-starter::admin_misc.billing_events.columns.reason'))
                    ->placeholder(static::ABSENT),
                TextColumn::make('external_id')
                    ->label(__('magic-starter::admin_misc.billing_events.columns.external_id'))
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder(static::ABSENT)
                    ->searchable(),
                TextColumn::make('actor.email')
                    ->label(__('magic-starter::admin_misc.billing_events.columns.actor'))
                    ->placeholder(static::ABSENT),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('magic-starter::admin_misc.billing_events.filters.type'))
                    ->options(static::enumOptions(BillingEventType::cases())),
                SelectFilter::make('source')
                    ->label(__('magic-starter::admin_misc.billing_events.filters.source'))
                    ->options(static::enumOptions(BillingSource::cases())),
                SelectFilter::make('provider')
                    ->label(__('magic-starter::admin_misc.billing_events.filters.provider'))
                    ->options(static::enumOptions(BillingProvider::cases())),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('created_from')
                            ->label(__('magic-starter::admin_misc.billing_events.filters.created_from')),
                        DatePicker::make('created_until')
                            ->label(__('magic-starter::admin_misc.billing_events.filters.created_until')),
                    ])
                    ->query(static fn (Builder $query, array $data): Builder => static::createdBetween($query, $data)),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Every section spans the page, so the properties get the full width.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columnSpanFull()
                ->columns([
                    'default' => 1,
                    'md' => 2,
                    'xl' => 3,
                ])
                ->schema([
                    TextEntry::make('type')
                        ->label(__('magic-starter::admin_misc.billing_events.columns.type'))
                        ->badge()
                        ->formatStateUsing(static fn (BillingEventType $state): string => $state->value)
                        ->color(static fn (BillingEventType $state): string => static::typeColor($state)),
                    TextEntry::make('created_at')
                        ->label(__('magic-starter::admin_misc.billing_events.columns.created_at'))
                        ->dateTime()
                        ->sinceTooltip(),
                    TextEntry::make('source')
                        ->label(__('magic-starter::admin_misc.billing_events.columns.source'))
                        ->formatStateUsing(static fn (BillingSource $state): string => $state->value),
                    TextEntry::make('provider')
                        ->label(__('magic-starter::admin_misc.billing_events.columns.provider'))
                        ->formatStateUsing(static fn (?BillingProvider $state): ?string => $state?->label())
                        ->placeholder(static::ABSENT),
                    TextEntry::make('billable')
                        ->label(__('magic-starter::admin_misc.billing_events.columns.billable'))
                        ->state(static fn (BillingEvent $record): ?string => static::billableLabel($record))
                        ->fontFamily(FontFamily::Mono)
                        ->extraAttributes(static::WRAP_ANYWHERE)
                        ->placeholder(static::ABSENT),
                    TextEntry::make('reason')
                        ->label(__('magic-starter::admin_misc.billing_events.columns.reason'))
                        ->placeholder(static::ABSENT),
                    TextEntry::make('external_id')
                        ->label(__('magic-starter::admin_misc.billing_events.columns.external_id'))
                        ->fontFamily(FontFamily::Mono)
                        ->copyable()
                        ->extraAttributes(static::WRAP_ANYWHERE)
                        ->placeholder(static::ABSENT),
                    TextEntry::make('actor.email')
                        ->label(__('magic-starter::admin_misc.billing_events.columns.actor'))
                        ->placeholder(static::ABSENT),
                ]),
            Section::make(__('magic-starter::admin_misc.billing_events.view.properties'))
                ->columnSpanFull()
                ->collapsible()
                ->visible(static fn (BillingEvent $record): bool => filled($record->properties))
                ->schema([
                    TextEntry::make('properties')
                        ->hiddenLabel()
                        ->state(static fn (BillingEvent $record): string => (string) json_encode(
                            $record->properties,
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                        ))
                        ->fontFamily(FontFamily::Mono)
                        ->extraAttributes(static::WRAP_ANYWHERE),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBillingEvents::route('/'),
            'view' => ViewBillingEvent::route('/{record}'),
        ];
    }

    /**
     * Rows created from the start of one day to the end of another, either bound optional.
     *
     * @param  Builder<BillingEvent>  $query
     * @param  array<string, mixed>  $data  The date range filter state.
     * @return Builder<BillingEvent>
     */
    protected static function createdBetween(Builder $query, array $data): Builder
    {
        return $query
            ->when($data['created_from'] ?? null, static fn (Builder $events, string $day): Builder => $events
                ->where('created_at', '>=', Carbon::parse($day)->startOfDay()))
            ->when($data['created_until'] ?? null, static fn (Builder $events, string $day): Builder => $events
                ->where('created_at', '<=', Carbon::parse($day)->endOfDay()));
    }

    /**
     * A refusal is the package saying no and is the one an operator looks for.
     */
    protected static function typeColor(BillingEventType $type): string
    {
        return $type->isRefusal() ? 'danger' : 'gray';
    }

    /**
     * The model name without its namespace and the key, such as `Team 42`, or
     * null for a row with no billable.
     */
    protected static function billableLabel(BillingEvent $record): ?string
    {
        if ($record->billable_type === null) {
            return null;
        }

        return trim(class_basename($record->billable_type) . ' ' . $record->billable_id);
    }

    /**
     * Filter options keyed and labelled by the stored value, which is what a
     * log line or an alert names.
     *
     * @param  list<BackedEnum>  $cases
     * @return array<string, string>
     */
    protected static function enumOptions(array $cases): array
    {
        $values = array_map(static fn (BackedEnum $case): string => (string) $case->value, $cases);

        return array_combine($values, $values);
    }
}
