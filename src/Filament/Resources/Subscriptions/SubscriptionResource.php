<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\Subscriptions;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use FlutterSdk\MagicStarter\Enums\BillingProvider;
use FlutterSdk\MagicStarter\Enums\PlanStatus;
use FlutterSdk\MagicStarter\Filament\AdminNavigationGroup;
use FlutterSdk\MagicStarter\Filament\MagicStarterPlugin;
use FlutterSdk\MagicStarter\Filament\Resources\Billing\RelationManagers\BillingRelationManager;
use FlutterSdk\MagicStarter\Filament\Resources\MagicStarterResource;
use FlutterSdk\MagicStarter\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Support\BillingCatalogue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Cashier\Billable;
use UnitEnum;

/**
 * Every customer a billing rail has touched, whichever rail it was.
 *
 * One row per billable (a user or a team, per `magic-starter.billing.billable`),
 * read from the entitlement columns every rail writes through the entitlement
 * contract: `plan`, `plan_status`, `plan_provider` and the rest. A card
 * subscription, an App Store or Google Play purchase and a manual plan therefore
 * sit in one list, and a purchase that lapsed stays in it with its last status.
 * Cashier's own rows are only the card rail's detail, so a billable that has one
 * but no entitlement yet (a dropped webhook) is listed too, as "not billed".
 *
 * Nothing here edits a row. "Grant a plan" and the reconcile sweep are header
 * actions of the list page, and each row opens its customer's Billing tab, where
 * grant, revoke, cancel, resume, refund and sync are offered by the state of
 * that rail. A store purchase is managed in the store, so the tab offers it
 * nothing to cancel.
 */
class SubscriptionResource extends MagicStarterResource
{
    /**
     * Shown for a column a row does not carry.
     */
    protected const ABSENT = '-';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Billing;

    protected static ?int $navigationSort = 10;

    protected static function resolveModel(): string
    {
        return MagicStarter::billableModel();
    }

    /**
     * The billables a rail has touched: one that holds a tier above the floor,
     * one that names a rail (a lapsed purchase keeps it), or one with a Cashier
     * subscription row, so a webhook that never arrived is visible here.
     *
     * A billable that holds nothing and names no rail is left out: the list is
     * the customers of the billing rails, not every account.
     */
    public static function getEloquentQuery(): Builder
    {
        $model = app(static::getModel());
        $floor = BillingCatalogue::tierOrder()[0] ?? null;

        return parent::getEloquentQuery()->where(
            static function (Builder $query) use ($model, $floor): void {
                $query->where(static function (Builder $held) use ($floor): void {
                    $held->whereNotNull('plan');

                    if ($floor !== null) {
                        $held->where('plan', '!=', $floor);
                    }
                })->orWhere(static function (Builder $named): void {
                    $named->whereNotNull('plan_provider')
                        ->where('plan_provider', '!=', BillingProvider::NONE->value);
                });

                if (in_array(Billable::class, class_uses_recursive($model), true)) {
                    $query->orWhereHas('subscriptions');
                }
            },
        );
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
        $key = 'magic-starter::admin_misc.subscriptions';
        $userBillable = config('magic-starter.billing.billable', 'user') === 'user';

        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__("{$key}.columns.billable"))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label(__("{$key}.columns.email"))
                    ->searchable()
                    ->visible($userBillable),
                TextColumn::make('plan')
                    ->label(__("{$key}.columns.plan"))
                    ->badge()
                    ->formatStateUsing(static fn (mixed $state): ?string => static::planName(static::wire($state)))
                    ->placeholder(static::ABSENT),
                // A blank state skips the formatter, so the default is what
                // names a billable no rail has written a status or a rail for.
                TextColumn::make('plan_status')
                    ->label(__("{$key}.columns.status"))
                    ->badge()
                    ->default(PlanStatus::NONE->value)
                    ->formatStateUsing(
                        static fn (mixed $state): string => PlanStatus::fromWire(static::wire($state))->label(),
                    )
                    ->color(static fn (mixed $state): string => match (PlanStatus::fromWire(static::wire($state))) {
                        PlanStatus::ACTIVE, PlanStatus::TRIALING => 'success',
                        PlanStatus::PAST_DUE, PlanStatus::GRACE => 'warning',
                        PlanStatus::NONE, PlanStatus::CANCELED, PlanStatus::EXPIRED, PlanStatus::PAUSED => 'gray',
                    }),
                TextColumn::make('plan_provider')
                    ->label(__("{$key}.columns.provider"))
                    ->badge()
                    ->default(BillingProvider::NONE->value)
                    ->formatStateUsing(
                        static fn (mixed $state): string => BillingProvider::fromWire(static::wire($state))->label(),
                    )
                    ->color(static fn (mixed $state): string => match (BillingProvider::fromWire(static::wire($state))) {
                        BillingProvider::STRIPE => 'info',
                        BillingProvider::APP_STORE, BillingProvider::PLAY_STORE => 'primary',
                        BillingProvider::MANUAL => 'warning',
                        BillingProvider::NONE => 'gray',
                    }),
                TextColumn::make('plan_product_id')
                    ->label(__("{$key}.columns.product"))
                    ->searchable()
                    ->placeholder(static::ABSENT)
                    ->toggleable(),
                TextColumn::make('plan_current_period_end')
                    ->label(__("{$key}.columns.period_end"))
                    ->dateTime()
                    ->sortable()
                    ->placeholder(static::ABSENT),
            ])
            ->filters([
                SelectFilter::make('plan')
                    ->label(__("{$key}.filters.plan"))
                    ->options(static::planOptions()),
                SelectFilter::make('plan_status')
                    ->label(__("{$key}.filters.status"))
                    ->options(static::statusOptions()),
                SelectFilter::make('plan_provider')
                    ->label(__("{$key}.filters.provider"))
                    ->options(static::providerOptions()),
            ])
            // A link and not a write: the Billing tab offers each action only
            // in the state it applies to, through the billing contract.
            ->recordUrl(static fn (Model $record): ?string => static::billingUrl($record))
            ->recordActions([
                Action::make('manage')
                    ->label(__("{$key}.actions.manage"))
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(static fn (Model $record): ?string => static::billingUrl($record))
                    ->visible(static fn (Model $record): bool => static::billingUrl($record) !== null),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubscriptions::route('/'),
        ];
    }

    /**
     * The Billing tab of the billable's own edit page, or null when the
     * application mounts no resource for it or gave it no edit page.
     *
     * The resource comes from the plugin, so an application's `teamResource()`
     * override links to its own routes. The tab is addressed by its place in the
     * resource's relation list, which is how Filament keys it in `?relation=`.
     */
    protected static function billingUrl(Model $record): ?string
    {
        $mounted = config('magic-starter.billing.billable', 'user') === 'team' ? 'teams' : 'users';
        $resource = MagicStarterPlugin::get()->resourceFor($mounted);

        if ($resource === null || ! array_key_exists('edit', $resource::getPages())) {
            return null;
        }

        $parameters = ['record' => $record];
        $tab = array_search(BillingRelationManager::class, $resource::getRelations(), true);

        if ($tab !== false) {
            $parameters['relation'] = (string) $tab;
        }

        return $resource::getUrl('edit', $parameters);
    }

    /**
     * The stored word, whether or not the application casts the column to an
     * enum of its own. Display only: no entitlement decision reads this.
     */
    protected static function wire(mixed $state): ?string
    {
        if ($state instanceof BackedEnum) {
            $state = $state->value;
        }

        return is_string($state) && $state !== '' ? $state : null;
    }

    /**
     * The tier's display name from the catalogue, or the stored id for a plan
     * the catalogue does not name.
     */
    protected static function planName(?string $plan): ?string
    {
        if ($plan === null) {
            return null;
        }

        $name = BillingCatalogue::tiers()[$plan]['name'] ?? null;

        return is_string($name) ? $name : $plan;
    }

    /**
     * @return array<string, string> tier id => display name, cheapest first
     */
    protected static function planOptions(): array
    {
        $options = [];

        foreach (BillingCatalogue::tierOrder() as $plan) {
            $options[$plan] = (string) static::planName($plan);
        }

        return $options;
    }

    /**
     * Every lifecycle a rail reports; "no plan" is left out because the rows it
     * describes mostly store no status at all, which an equality filter cannot match.
     *
     * @return array<string, string>
     */
    protected static function statusOptions(): array
    {
        $options = [];

        foreach (PlanStatus::cases() as $status) {
            if ($status !== PlanStatus::NONE) {
                $options[$status->value] = $status->label();
            }
        }

        return $options;
    }

    /**
     * Every rail a billable can name; "not billed" is the absence of one.
     *
     * @return array<string, string>
     */
    protected static function providerOptions(): array
    {
        $options = [];

        foreach (BillingProvider::cases() as $provider) {
            if ($provider !== BillingProvider::NONE) {
                $options[$provider->value] = $provider->label();
            }
        }

        return $options;
    }
}
