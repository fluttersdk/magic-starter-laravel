<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\BillingGrants;

use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use FlutterSdk\MagicStarter\Filament\AdminNavigationGroup;
use FlutterSdk\MagicStarter\Filament\Resources\BillingGrants\Pages\ListBillingGrants;
use FlutterSdk\MagicStarter\Filament\Resources\MagicStarterResource;
use FlutterSdk\MagicStarter\Models\BillingGrant;
use FlutterSdk\MagicStarter\Support\BillingCatalogue;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Manual plans: every grant an operator made, open or ended, newest first.
 *
 * Read-only like the other billing lists: a grant is made with the list's
 * "Grant a plan" action (or the customer's Billing tab) and revoked from the
 * customer's Billing tab, both through the billing contract, never by editing
 * a row here.
 */
class BillingGrantResource extends MagicStarterResource
{
    /**
     * Shown for a column a row does not carry.
     */
    protected const ABSENT = '-';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Billing;

    protected static ?int $navigationSort = 15;

    protected static function resolveModel(): string
    {
        return BillingGrant::class;
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('magic-starter::admin_misc.billing_grants.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return (string) __('magic-starter::admin_misc.billing_grants.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('magic-starter::admin_misc.billing_grants.plural_model_label');
    }

    public static function table(Table $table): Table
    {
        $key = 'magic-starter::admin_misc.billing_grants';

        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with(['billable', 'grantedBy']))
            ->columns([
                TextColumn::make('created_at')
                    ->label(__("{$key}.columns.created_at"))
                    ->dateTime()
                    ->sinceTooltip()
                    ->sortable(),
                TextColumn::make('billable')
                    ->label(__("{$key}.columns.billable"))
                    ->state(static fn (BillingGrant $record): string => static::billableLabel($record)),
                TextColumn::make('plan')
                    ->label(__("{$key}.columns.plan"))
                    ->formatStateUsing(static fn (string $state): string => static::planName($state))
                    ->searchable(),
                TextColumn::make('reason')
                    ->label(__("{$key}.columns.reason"))
                    ->wrap()
                    ->searchable(),
                TextColumn::make('status')
                    ->label(__("{$key}.columns.status"))
                    ->badge()
                    ->state(static fn (BillingGrant $record): string => __(
                        "{$key}.status." . ($record->end_reason->value ?? 'open'),
                    ))
                    ->color(static fn (BillingGrant $record): string => $record->ended_at === null ? 'success' : 'gray'),
                TextColumn::make('expires_at')
                    ->label(__("{$key}.columns.expires_at"))
                    ->dateTime()
                    ->placeholder(__("{$key}.no_expiry")),
                TextColumn::make('grantedBy.email')
                    ->label(__("{$key}.columns.granted_by"))
                    ->placeholder(static::ABSENT),
            ])
            ->filters([
                Filter::make('open')
                    ->label(__("{$key}.filters.open"))
                    ->toggle()
                    ->query(static fn (Builder $query): Builder => $query->whereNull('ended_at')),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBillingGrants::route('/'),
        ];
    }

    /**
     * The customer's name, or its type and key once it was deleted: a grant
     * outlives its billable on purpose.
     */
    protected static function billableLabel(BillingGrant $record): string
    {
        $name = $record->billable?->getAttribute('name');

        return is_string($name) && $name !== ''
            ? $name
            : trim(class_basename($record->billable_type) . ' ' . $record->billable_id);
    }

    /**
     * The tier's display name from the catalogue, or the stored id for a plan
     * the catalogue no longer ranks.
     */
    protected static function planName(string $plan): string
    {
        $name = BillingCatalogue::tiers()[$plan]['name'] ?? null;

        return is_string($name) ? $name : $plan;
    }
}
