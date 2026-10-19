<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\Subscriptions;

use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FlutterSdk\MagicStarter\Filament\AdminNavigationGroup;
use FlutterSdk\MagicStarter\Filament\Resources\MagicStarterResource;
use FlutterSdk\MagicStarter\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use FlutterSdk\MagicStarter\MagicStarter;
use Laravel\Cashier\Cashier;
use UnitEnum;

/**
 * A read-only list of Cashier's subscription rows.
 *
 * Nothing here edits a row: the subscription row is a projection of the billing
 * rails, and the entitlement columns move only through the entitlement
 * contract. The list page carries two actions: "Grant a plan" (a manual plan
 * through the billing contract, listed under Manual plans) and the reconcile
 * sweep.
 */
class SubscriptionResource extends MagicStarterResource
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Billing;

    protected static ?int $navigationSort = 10;

    protected static function resolveModel(): string
    {
        return Cashier::$subscriptionModel;
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('magic-starter::admin_misc.subscriptions.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return (string) __('magic-starter::admin_misc.subscriptions.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('magic-starter::admin_misc.subscriptions.plural_model_label');
    }

    public static function table(Table $table): Table
    {
        // The billable column is named after the subject Cashier relates the
        // row to, the same way the migration derives it.
        $billableModel = MagicStarter::billableModel();

        return $table
            ->columns([
                TextColumn::make((new $billableModel)->getForeignKey())
                    ->label(__('magic-starter::admin_misc.subscriptions.columns.billable'))
                    ->searchable(),
                TextColumn::make('type')
                    ->label(__('magic-starter::admin_misc.subscriptions.columns.type')),
                TextColumn::make('stripe_status')
                    ->label(__('magic-starter::admin_misc.subscriptions.columns.status'))
                    ->badge()
                    ->color(static fn (?string $state): string => match ($state) {
                        'active', 'trialing' => 'success',
                        'past_due', 'incomplete' => 'warning',
                        default => 'gray',
                    })
                    ->searchable(),
                TextColumn::make('stripe_price')
                    ->label(__('magic-starter::admin_misc.subscriptions.columns.price'))
                    ->searchable(),
                TextColumn::make('ends_at')
                    ->label(__('magic-starter::admin_misc.subscriptions.columns.ends_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubscriptions::route('/'),
        ];
    }
}
