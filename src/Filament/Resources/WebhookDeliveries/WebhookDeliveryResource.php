<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\WebhookDeliveries;

use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use FlutterSdk\MagicStarter\Filament\Resources\MagicStarterResource;
use FlutterSdk\MagicStarter\Filament\Resources\WebhookDeliveries\Pages\ListWebhookDeliveries;
use FlutterSdk\MagicStarter\Filament\Resources\WebhookDeliveries\Pages\ViewWebhookDelivery;
use FlutterSdk\MagicStarter\Jobs\SyncRevenueCatEntitlement;
use FlutterSdk\MagicStarter\Models\BillingEvent;
use FlutterSdk\MagicStarter\Models\ProcessedWebhookEvent;
use Illuminate\Database\Eloquent\Builder;

/**
 * The webhook events the billing rails claimed as processed, read-only.
 *
 * The table has no provider column, so the provider is derived from the claim:
 * RevenueCat ids are stored under {@see SyncRevenueCatEntitlement::CLAIM_PREFIX}
 * and Stripe ids are stored as they arrive. The view page lists the billing
 * events recorded under the same raw id; that is every entitlement, refusal and
 * trial-recorded row the delivery caused. A delivery that changed nothing has
 * none, and rows keyed on a checkout session or a subscription id never join.
 */
class WebhookDeliveryResource extends MagicStarterResource
{
    protected const STRIPE = 'stripe';

    protected const REVENUECAT = 'revenuecat';

    protected const ABSENT = '-';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static function resolveModel(): string
    {
        return ProcessedWebhookEvent::class;
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('magic-starter::admin_misc.webhook_deliveries.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return (string) __('magic-starter::admin_misc.webhook_deliveries.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('magic-starter::admin_misc.webhook_deliveries.plural_model_label');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('processed_at')
                    ->label(__('magic-starter::admin_misc.webhook_deliveries.columns.processed_at'))
                    ->dateTime()
                    ->sinceTooltip()
                    ->sortable(),
                TextColumn::make('provider')
                    ->label(__('magic-starter::admin_misc.webhook_deliveries.columns.provider'))
                    ->badge()
                    ->state(static fn (ProcessedWebhookEvent $record): string => static::providerLabel($record)),
                TextColumn::make('event_id')
                    ->label(__('magic-starter::admin_misc.webhook_deliveries.columns.event_id'))
                    ->formatStateUsing(static fn (string $state): string => static::rawEventId($state))
                    ->fontFamily(FontFamily::Mono)
                    ->searchable(),
                TextColumn::make('type')
                    ->label(__('magic-starter::admin_misc.webhook_deliveries.columns.type'))
                    ->searchable(),
            ])
            ->filters([
                SelectFilter::make('provider')
                    ->label(__('magic-starter::admin_misc.webhook_deliveries.filters.provider'))
                    ->options([
                        static::STRIPE => __('magic-starter::admin_misc.webhook_deliveries.providers.stripe'),
                        static::REVENUECAT => __('magic-starter::admin_misc.webhook_deliveries.providers.revenuecat'),
                    ])
                    ->query(static fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        static::REVENUECAT => $query->whereLike('event_id', static::claimPattern()),
                        static::STRIPE => $query->whereNotLike('event_id', static::claimPattern()),
                        default => $query,
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('processed_at', 'desc');
    }

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
                    TextEntry::make('provider')
                        ->label(__('magic-starter::admin_misc.webhook_deliveries.columns.provider'))
                        ->badge()
                        ->state(static fn (ProcessedWebhookEvent $record): string => static::providerLabel($record)),
                    TextEntry::make('event_id')
                        ->label(__('magic-starter::admin_misc.webhook_deliveries.columns.event_id'))
                        ->formatStateUsing(static fn (string $state): string => static::rawEventId($state))
                        ->fontFamily(FontFamily::Mono)
                        ->copyable()
                        ->extraAttributes(['style' => 'overflow-wrap: anywhere;']),
                    TextEntry::make('type')
                        ->label(__('magic-starter::admin_misc.webhook_deliveries.columns.type')),
                    TextEntry::make('processed_at')
                        ->label(__('magic-starter::admin_misc.webhook_deliveries.columns.processed_at'))
                        ->dateTime()
                        ->sinceTooltip(),
                ]),
            Section::make(__('magic-starter::admin_misc.webhook_deliveries.view.billing_events'))
                ->description(__('magic-starter::admin_misc.webhook_deliveries.view.billing_events_note'))
                ->columnSpanFull()
                ->schema([
                    RepeatableEntry::make('billing_events')
                        ->hiddenLabel()
                        ->placeholder(__('magic-starter::admin_misc.webhook_deliveries.view.no_billing_events'))
                        ->state(static fn (ProcessedWebhookEvent $record): array => static::billingEventRows($record))
                        ->table(array_map(
                            static fn (string $column): TableColumn => TableColumn::make(
                                __("magic-starter::admin_misc.billing_events.columns.{$column}"),
                            ),
                            ['created_at', 'type', 'source', 'reason'],
                        ))
                        ->schema([
                            TextEntry::make('created_at')
                                ->label(__('magic-starter::admin_misc.billing_events.columns.created_at'))
                                ->dateTime(),
                            TextEntry::make('type')
                                ->label(__('magic-starter::admin_misc.billing_events.columns.type'))
                                ->fontFamily(FontFamily::Mono),
                            TextEntry::make('source')
                                ->label(__('magic-starter::admin_misc.billing_events.columns.source')),
                            TextEntry::make('reason')
                                ->label(__('magic-starter::admin_misc.billing_events.columns.reason'))
                                ->placeholder(static::ABSENT),
                        ]),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWebhookDeliveries::route('/'),
            'view' => ViewWebhookDelivery::route('/{record}'),
        ];
    }

    /**
     * The LIKE pattern every RevenueCat claim matches.
     */
    protected static function claimPattern(): string
    {
        return SyncRevenueCatEntitlement::CLAIM_PREFIX . '%';
    }

    /**
     * The id as the provider issued it: the RevenueCat claim prefix is the
     * package's own and means nothing to an operator searching the dashboard.
     */
    protected static function rawEventId(string $eventId): string
    {
        return str_starts_with($eventId, SyncRevenueCatEntitlement::CLAIM_PREFIX)
            ? substr($eventId, strlen(SyncRevenueCatEntitlement::CLAIM_PREFIX))
            : $eventId;
    }

    protected static function isRevenueCat(ProcessedWebhookEvent $record): bool
    {
        return str_starts_with((string) $record->getAttribute('event_id'), SyncRevenueCatEntitlement::CLAIM_PREFIX);
    }

    protected static function providerLabel(ProcessedWebhookEvent $record): string
    {
        $provider = static::isRevenueCat($record)
            ? static::REVENUECAT
            : static::STRIPE;

        return (string) __("magic-starter::admin_misc.webhook_deliveries.providers.{$provider}");
    }

    /**
     * The billing events recorded under the delivery's raw id, oldest first so
     * the rows read in the order the delivery caused them.
     *
     * @return list<array<string, string|null>>
     */
    protected static function billingEventRows(ProcessedWebhookEvent $record): array
    {
        return BillingEvent::query()
            ->where('external_id', static::rawEventId((string) $record->getAttribute('event_id')))
            ->orderBy('created_at')
            ->get()
            ->map(static fn (BillingEvent $event): array => [
                'created_at' => $event->created_at?->toIso8601String(),
                'type' => $event->type->value,
                'source' => $event->source->value,
                'reason' => $event->reason,
            ])
            ->all();
    }
}
