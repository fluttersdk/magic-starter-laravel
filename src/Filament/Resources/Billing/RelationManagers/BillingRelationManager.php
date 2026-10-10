<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\Billing\RelationManagers;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use FlutterSdk\MagicStarter\Contracts\AdministersBilling;
use FlutterSdk\MagicStarter\Enums\BillingProvider;
use FlutterSdk\MagicStarter\Enums\PlanStatus;
use FlutterSdk\MagicStarter\Filament\Resources\BillingEvents\BillingEventResource;
use FlutterSdk\MagicStarter\Filament\Support\BillingAuthorization;
use FlutterSdk\MagicStarter\Filament\Support\ContractAction;
use FlutterSdk\MagicStarter\Models\BillingEvent;
use FlutterSdk\MagicStarter\Models\BillingGrant;
use FlutterSdk\MagicStarter\Support\BillingCatalogue;
use FlutterSdk\MagicStarter\Support\BillingLog;
use FlutterSdk\MagicStarter\Support\ReadsBillableAttributes;
use FlutterSdk\MagicStarter\Support\StoreRailConfiguration;
use FlutterSdk\MagicStarter\Support\StripeBillingState;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription as CashierSubscription;
use Livewire\Attributes\Locked;
use Stripe\Exception\ApiErrorException;

/**
 * The Billing tab of the billable (a user or a team, per
 * `magic-starter.billing.billable`): its billing history, a summary of what it
 * holds, and the operator's eight billing actions.
 *
 * Every action goes through {@see AdministersBilling}; nothing here calls
 * Stripe, RevenueCat or the entitlement writer. Each is shown only to an admin
 * {@see BillingAuthorization} allows AND only in the state it applies to, and
 * Filament refuses to mount or call a hidden action, so both hold against a
 * crafted Livewire call. The contract re-checks the state and refuses on its own
 * as the second line.
 *
 * The visibility and the summary read local rows only (the billable, its open
 * grant, Cashier's local subscription), since they run on every render. The one
 * rail read is the refund modal's, made once when it opens.
 */
class BillingRelationManager extends RelationManager
{
    use ReadsBillableAttributes;

    /**
     * The payment the open refund modal would refund, as read when it opened.
     * Display only: the refund re-reads it, so a stale value cannot misdirect it.
     *
     * @var array<string, mixed>|null
     */
    #[Locked]
    public ?array $refundablePayment = null;

    /**
     * The refusal key the refund modal shows instead of a refund, or null when
     * there is a payment to refund.
     */
    #[Locked]
    public ?string $refundUnavailable = null;

    /**
     * The open grant as read for this request, or false until it is read: the
     * summary and three actions ask for it on every render. Not a public
     * property, so it never outlives the request that read it.
     */
    protected BillingGrant|false|null $loadedOpenGrant = false;

    /**
     * Fixed on, like the other tabs: the panel is the gate, the billing actions
     * add their own, and there is no relation on the owner for a policy lookup.
     */
    public static function shouldSkipAuthorization(): bool
    {
        return true;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return (string) __('magic-starter::admin_billing.title');
    }

    /**
     * The owner's billing events, wrapped in a relation.
     *
     * Built like the audits tab and for the same reasons: the owner model needs
     * no `billingEvents()` relation, the relation is built without its own
     * constraints, and the key is compared as a string, which is how
     * `billing_events` stores it: an integer binding against a varchar column
     * fails on PostgreSQL.
     *
     * @return HasMany<BillingEvent, Model>
     */
    public function getRelationship(): HasMany
    {
        $owner = $this->getOwnerRecord();

        $events = BillingEvent::query()
            ->where('billable_type', $owner->getMorphClass())
            ->where('billable_id', (string) $owner->getKey());

        return Relation::noConstraints(
            static fn (): HasMany => new HasMany($events, $owner, 'billable_id', $owner->getKeyName()),
        );
    }

    /**
     * Filament's own layout with the summary above the history.
     */
    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getTabsContentComponent(),
                $this->summary(),
                RenderHook::make(PanelsRenderHook::RESOURCE_RELATION_MANAGER_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_RELATION_MANAGER_AFTER),
            ]);
    }

    /**
     * The history with the billing events resource's own columns, filters and
     * newest-first order, and the billing actions in its header. The billable
     * column is hidden: every row here is the owner's.
     */
    public function table(Table $table): Table
    {
        $table = BillingEventResource::table($table)
            ->recordActions($this->rowActions())
            ->headerActions([
                $this->grantAction(),
                $this->revokeAction(),
                $this->extendTrialAction(),
                $this->endTrialAction(),
                $this->cancelSubscriptionAction(),
                $this->resumeSubscriptionAction(),
                $this->refundAction(),
                $this->syncAction(),
            ]);

        $table->getColumn('billable')?->hidden();

        return $table;
    }

    protected function grantAction(): Action
    {
        return $this->billingAction('grant', Heroicon::OutlinedGift, $this->offersGrant(...))
            ->schema([
                $this->planField(),
                $this->reasonField(),
                DatePicker::make('expires_at')
                    ->label(__('magic-starter::admin_billing.fields.expires_at'))
                    ->helperText(__('magic-starter::admin_billing.fields.expires_at_help'))
                    ->after('today'),
            ])
            ->action(function (Action $action, array $data): void {
                $plan = (string) $data['plan'];
                $reason = (string) $data['reason'];
                $expiresAt = $this->endOfDay($data['expires_at'] ?? null);

                $this->administer(
                    $action,
                    'billing.grant_added',
                    static fn (AdministersBilling $billing, Authenticatable $actor, Model $owner) => $billing
                        ->grant($actor, $owner, $plan, $reason, $expiresAt),
                    [
                        'plan' => $plan,
                        'expires_at' => $expiresAt?->toIso8601String(),
                    ],
                );
            });
    }

    protected function revokeAction(): Action
    {
        return $this->billingAction('revoke', Heroicon::OutlinedNoSymbol, $this->offersRevoke(...))
            ->color('danger')
            ->schema([
                $this->reasonField(),
            ])
            ->action(function (Action $action, array $data): void {
                $reason = (string) $data['reason'];

                $this->administer(
                    $action,
                    'billing.grant_revoked',
                    static fn (AdministersBilling $billing, Authenticatable $actor, Model $owner) => $billing
                        ->revoke($actor, $owner, $reason),
                );
            });
    }

    protected function extendTrialAction(): Action
    {
        return $this->billingAction('extendTrial', Heroicon::OutlinedClock, $this->isTrialing(...))
            ->schema([
                DatePicker::make('until')
                    ->label(__('magic-starter::admin_billing.fields.until'))
                    ->required()
                    ->minDate(fn (): CarbonInterface => $this->earliestTrialExtension($this->getOwnerRecord())),
            ])
            ->action(function (Action $action, array $data): void {
                $until = CarbonImmutable::parse((string) $data['until']);

                $this->administer(
                    $action,
                    'billing.trial_extended',
                    static fn (AdministersBilling $billing, Authenticatable $actor, Model $owner) => $billing
                        ->extendTrial($actor, $owner, $until),
                    [
                        'until' => $until->toIso8601String(),
                    ],
                );
            });
    }

    protected function endTrialAction(): Action
    {
        return $this->billingAction('endTrial', Heroicon::OutlinedStopCircle, $this->isTrialing(...))
            ->color('warning')
            ->action(function (Action $action): void {
                $this->administer(
                    $action,
                    'billing.trial_ended',
                    static fn (AdministersBilling $billing, Authenticatable $actor, Model $owner) => $billing
                        ->endTrial($actor, $owner),
                );
            });
    }

    protected function cancelSubscriptionAction(): Action
    {
        return $this->billingAction('cancelSubscription', Heroicon::OutlinedXCircle, $this->offersCancel(...))
            ->color('danger')
            ->action(function (Action $action): void {
                $this->administer(
                    $action,
                    'billing.subscription_cancelled',
                    static fn (AdministersBilling $billing, Authenticatable $actor, Model $owner) => $billing
                        ->cancel($actor, $owner),
                );
            });
    }

    protected function resumeSubscriptionAction(): Action
    {
        return $this->billingAction('resumeSubscription', Heroicon::OutlinedPlayCircle, $this->offersResume(...))
            ->action(function (Action $action): void {
                $this->administer(
                    $action,
                    'billing.subscription_resumed',
                    static fn (AdministersBilling $billing, Authenticatable $actor, Model $owner) => $billing
                        ->resume($actor, $owner),
                );
            });
    }

    /**
     * What the refund would return is read once, when the modal opens; with
     * nothing to refund, or Stripe out of reach, the modal says so and offers
     * no submit.
     */
    protected function refundAction(): Action
    {
        return $this->billingAction('refund', Heroicon::OutlinedReceiptRefund, $this->offersRefund(...))
            ->color('danger')
            ->mountUsing(function (?Schema $schema): void {
                $this->loadRefundablePayment($this->getOwnerRecord());

                $schema?->fill();
            })
            ->modalDescription(fn (): string => $this->refundDescription())
            ->modalSubmitAction(
                fn (Action $action): Action|false => $this->refundablePayment === null ? false : $action,
            )
            ->schema([
                Select::make('reason')
                    ->label(__('magic-starter::admin_billing.fields.refund_reason'))
                    ->options([
                        'requested_by_customer' => __(
                            'magic-starter::admin_billing.refund_reasons.requested_by_customer',
                        ),
                        'duplicate' => __('magic-starter::admin_billing.refund_reasons.duplicate'),
                    ])
                    ->required()
                    ->visible(fn (): bool => $this->refundablePayment !== null),
            ])
            ->action(function (Action $action, array $data): void {
                $reason = (string) ($data['reason'] ?? '');

                // The invoice the modal showed. A modal that showed none offers
                // no submit, and an empty id matches no invoice, so a crafted
                // call cannot refund one the operator never saw.
                $invoiceId = (string) ($this->refundablePayment['invoice_id'] ?? '');

                $this->administer(
                    $action,
                    'billing.invoice_refunded',
                    static fn (AdministersBilling $billing, Authenticatable $actor, Model $owner): string => $billing
                        ->refundLastInvoice($actor, $owner, $reason, $invoiceId),
                    [
                        'reason' => $reason,
                    ],
                );
            });
    }

    protected function syncAction(): Action
    {
        return $this->billingAction('sync', Heroicon::OutlinedArrowPath, $this->offersSync(...))
            ->action(function (Action $action): void {
                $this->administer(
                    $action,
                    'billing.synced',
                    static fn (AdministersBilling $billing, Authenticatable $actor, Model $owner) => $billing
                        ->sync($actor, $owner),
                );
            });
    }

    /**
     * Whether a manual grant is offered: never while a paid rail grants.
     */
    protected function offersGrant(Model $owner): bool
    {
        return ! app(AdministersBilling::class)->paidRailGrants($owner);
    }

    /**
     * Whether a revoke is offered: an open grant the record is MANUAL on, or a
     * store record while the store rail is configured, of which the contract
     * revokes only a sandbox-only one after reading the store.
     */
    protected function offersRevoke(Model $owner): bool
    {
        $provider = $this->provider($owner);

        return ($provider === BillingProvider::MANUAL && $this->openGrant($owner) !== null)
            || ($provider->isStore() && StoreRailConfiguration::railIsConfigured());
    }

    /**
     * Whether the trial actions are offered: a trial that is not cancelled,
     * since the contract refuses to move or end a cancelled one.
     */
    protected function isTrialing(Model $owner): bool
    {
        $subscription = $this->subscription($owner);

        return $subscription !== null && $subscription->onTrial() && ! $subscription->onGracePeriod();
    }

    /**
     * Whether a cancel is offered: the subscription runs and is not already
     * cancelled.
     */
    protected function offersCancel(Model $owner): bool
    {
        $subscription = $this->subscription($owner);

        return $subscription !== null && $subscription->active() && ! $subscription->onGracePeriod();
    }

    protected function offersResume(Model $owner): bool
    {
        return $this->subscription($owner)?->onGracePeriod() === true;
    }

    /**
     * Whether a refund is offered: a Stripe customer with a local subscription.
     */
    protected function offersRefund(Model $owner): bool
    {
        return method_exists($owner, 'hasStripeId')
            && $owner->hasStripeId()
            && $this->subscription($owner) !== null;
    }

    /**
     * Whether a sync is offered, mirroring what the contract reads: a local
     * Stripe subscription, or the store rail for a store record or for a
     * record of nobody or of an ended comp, behind which a store payer may sit.
     */
    protected function offersSync(Model $owner): bool
    {
        if ($this->subscription($owner) !== null) {
            return true;
        }

        if (! StoreRailConfiguration::railIsConfigured()) {
            return false;
        }

        $provider = $this->provider($owner);

        return $provider->isStore()
            || (in_array($provider, [BillingProvider::MANUAL, BillingProvider::NONE], true)
                && $this->openGrant($owner) === null);
    }

    /**
     * The parts every billing action shares: its labels, a confirmation, and
     * visibility that requires both the billing permission and the state.
     *
     * @param  string  $name  the action name; its strings live under the snake_case key
     * @param  Closure(Model): bool  $offered  whether the owner's state calls for the action
     */
    protected function billingAction(string $name, Heroicon $icon, Closure $offered): Action
    {
        $key = 'magic-starter::admin_billing.actions.' . str($name)->snake();

        return Action::make($name)
            ->label(__("{$key}.label"))
            ->icon($icon)
            ->requiresConfirmation()
            ->modalHeading(__("{$key}.heading"))
            ->modalDescription(__("{$key}.description"))
            ->successNotificationTitle(__("{$key}.success"))
            ->visible(fn (): bool => $this->canAdministerBilling() && $offered($this->getOwnerRecord()));
    }

    /**
     * Run one billing write through the contract as the signed-in panel user,
     * audited as `$event`, then re-read the owner and its open grant so the
     * summary and the actions show what it changed.
     *
     * @param  Closure(AdministersBilling, Authenticatable, Model): mixed  $call
     * @param  array<string, mixed>  $context
     *
     * @throws AuthenticationException when no panel user is signed in
     */
    protected function administer(Action $action, string $event, Closure $call, array $context = []): void
    {
        $owner = $this->getOwnerRecord();
        $actor = Filament::auth()->user() ?? throw new AuthenticationException;

        ContractAction::run(
            $action,
            static fn (): mixed => $call(app(AdministersBilling::class), $actor, $owner),
            $event,
            $owner,
            $context,
        );

        $owner->refresh();
        $this->loadedOpenGrant = false;

        $action->success();
    }

    protected function canAdministerBilling(): bool
    {
        $admin = Filament::auth()->user();

        return $admin !== null && BillingAuthorization::allows($admin);
    }

    /**
     * A row opens in the billing events resource, when the panel mounts one.
     *
     * The action is replaced rather than dropped: the resource's table already
     * registered a `view` action under that name, which would otherwise stay
     * callable as a modal with nothing in it.
     *
     * @return list<Action>
     */
    protected function rowActions(): array
    {
        $resource = Filament::getModelResource(BillingEvent::class);
        $linked = $resource !== null && $resource::hasPage('view');

        return [
            ViewAction::make()
                ->url(static fn (BillingEvent $record): ?string => $linked
                    ? $resource::getUrl('view', ['record' => $record])
                    : null)
                ->visible($linked),
        ];
    }

    /**
     * What the billable holds, from its own row, its open grant and Cashier's
     * local subscription.
     */
    protected function summary(): Section
    {
        $key = 'magic-starter::admin_billing.summary';
        $none = __("{$key}.none");

        return Section::make(__("{$key}.heading"))
            ->columns([
                'default' => 1,
                'md' => 3,
            ])
            ->schema([
                TextEntry::make('summary_plan')
                    ->label(__("{$key}.plan"))
                    ->state(fn (): ?string => $this->stringAttribute($this->getOwnerRecord(), 'plan'))
                    ->placeholder($none),
                TextEntry::make('summary_status')
                    ->label(__("{$key}.status"))
                    ->state(fn (): string => PlanStatus::fromWire(
                        $this->stringAttribute($this->getOwnerRecord(), 'plan_status'),
                    )->label()),
                TextEntry::make('summary_provider')
                    ->label(__("{$key}.provider"))
                    ->state(fn (): string => $this->provider($this->getOwnerRecord())->label()),
                TextEntry::make('summary_period_end')
                    ->label(__("{$key}.period_end"))
                    ->state(fn (): ?CarbonInterface => $this->dateAttribute(
                        $this->getOwnerRecord(),
                        'plan_current_period_end',
                    ))
                    ->date()
                    ->placeholder($none),
                TextEntry::make('summary_grant')
                    ->label(__("{$key}.grant"))
                    ->state(fn (): ?string => $this->openGrant($this->getOwnerRecord())?->plan)
                    ->placeholder($none),
                TextEntry::make('summary_grant_expires')
                    ->label(__("{$key}.grant_expires"))
                    ->state(fn (): ?CarbonInterface => $this->openGrant($this->getOwnerRecord())?->expires_at)
                    ->date()
                    ->placeholder(__("{$key}.no_expiry"))
                    ->visible(fn (): bool => $this->openGrant($this->getOwnerRecord()) !== null),
                TextEntry::make('summary_trial_ends')
                    ->label(__("{$key}.trial_ends"))
                    ->state(fn (): ?CarbonInterface => $this->trialEndsAt($this->getOwnerRecord()))
                    ->date()
                    ->placeholder($none),
            ]);
    }

    /**
     * Read what a refund would return, once, for the modal opening now.
     *
     * A Stripe failure here is a display read failing, not an operator act, so
     * it becomes the modal's "unreachable" state and a warning, not a crash and
     * not a refusal row.
     */
    protected function loadRefundablePayment(Model $owner): void
    {
        $this->refundablePayment = null;
        $this->refundUnavailable = null;

        try {
            $this->refundablePayment = app(AdministersBilling::class)->refundablePayment($owner);
        } catch (ApiErrorException $exception) {
            BillingLog::warning('The refund modal could not read the refundable payment from Stripe.', [
                'billable_type' => $owner->getMorphClass(),
                'billable_id' => (string) $owner->getKey(),
                'error' => $exception->getMessage(),
            ]);

            $this->refundUnavailable = 'rail_error';

            return;
        }

        if ($this->refundablePayment === null) {
            $this->refundUnavailable = 'nothing_refundable';
        }
    }

    /**
     * The confirmation naming the amount and the invoice, or why there is
     * nothing to confirm.
     */
    protected function refundDescription(): string
    {
        if ($this->refundablePayment === null) {
            $refusal = $this->refundUnavailable ?? 'nothing_refundable';

            return (string) __("magic-starter::admin_billing.refusals.{$refusal}");
        }

        return (string) __('magic-starter::admin_billing.actions.refund.description', [
            'amount' => Cashier::formatAmount(
                (int) $this->refundablePayment['amount'],
                (string) $this->refundablePayment['currency'],
            ),
            'invoice' => (string) $this->refundablePayment['invoice_id'],
        ]);
    }

    /**
     * The plan picker: the catalogue's tiers when it publishes a ranking, a free
     * text id otherwise, which is what the contract accepts in each case.
     */
    protected function planField(): Select|TextInput
    {
        $label = __('magic-starter::admin_billing.fields.plan');
        $tiers = BillingCatalogue::tiers();

        if ($tiers === []) {
            return TextInput::make('plan')
                ->label($label)
                ->required()
                ->maxLength(255);
        }

        return Select::make('plan')
            ->label($label)
            ->options(array_map(
                static fn (array $tier): string => is_string($tier['name'] ?? null)
                    ? $tier['name']
                    : (string) $tier['id'],
                $tiers,
            ))
            ->required();
    }

    protected function reasonField(): Textarea
    {
        return Textarea::make('reason')
            ->label(__('magic-starter::admin_billing.fields.reason'))
            ->required()
            ->maxLength(500);
    }

    /**
     * A date picked in the form as the end of that day, or null when empty: a
     * grant that "expires after" a day lasts through it.
     */
    protected function endOfDay(mixed $date): ?CarbonImmutable
    {
        return is_string($date) && $date !== '' ? CarbonImmutable::parse($date)->endOfDay() : null;
    }

    /**
     * The first day a trial extension may pick: the day after the current
     * trial end, since the contract refuses an end that is not later.
     */
    protected function earliestTrialExtension(Model $owner): CarbonInterface
    {
        $trialEndsAt = $this->trialEndsAt($owner);

        return $trialEndsAt === null
            ? CarbonImmutable::tomorrow()
            : CarbonImmutable::instance($trialEndsAt)->addDay()->startOfDay();
    }

    protected function provider(Model $owner): BillingProvider
    {
        return BillingProvider::fromWire($this->stringAttribute($owner, 'plan_provider'));
    }

    protected function openGrant(Model $owner): ?BillingGrant
    {
        if ($this->loadedOpenGrant === false) {
            $this->loadedOpenGrant = BillingGrant::forBillable($owner)->open()->first();
        }

        return $this->loadedOpenGrant;
    }

    /**
     * Cashier's local `default` subscription, or null when there is none.
     */
    protected function subscription(Model $owner): ?CashierSubscription
    {
        $subscription = StripeBillingState::defaultSubscription($owner);

        return $subscription instanceof CashierSubscription ? $subscription : null;
    }

    protected function trialEndsAt(Model $owner): ?CarbonInterface
    {
        $subscription = $this->subscription($owner);

        return $subscription === null ? null : $this->dateAttribute($subscription, 'trial_ends_at');
    }
}
