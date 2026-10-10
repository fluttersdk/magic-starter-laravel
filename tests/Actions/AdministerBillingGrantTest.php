<?php

namespace FlutterSdk\MagicStarter\Tests\Actions;

use Carbon\CarbonImmutable;
use FlutterSdk\MagicStarter\Actions\AdministerBilling;
use FlutterSdk\MagicStarter\Contracts\AdministersBilling;
use FlutterSdk\MagicStarter\Contracts\WritesEntitlement;
use FlutterSdk\MagicStarter\Enums\BillingEventType;
use FlutterSdk\MagicStarter\Enums\BillingProvider;
use FlutterSdk\MagicStarter\Enums\BillingSource;
use FlutterSdk\MagicStarter\Enums\GrantEndReason;
use FlutterSdk\MagicStarter\Enums\PlanStatus;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\BillingEvent;
use FlutterSdk\MagicStarter\Models\BillingGrant;
use FlutterSdk\MagicStarter\Models\Subscription;
use FlutterSdk\MagicStarter\Support\BillingAdministrationRefused;
use FlutterSdk\MagicStarter\Support\EntitlementWrite;
use FlutterSdk\MagicStarter\Support\RevenueCatClient;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Cashier\Billable;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription as CashierSubscription;

/**
 * Locks the operator side of billing: a manual grant, its revoke, and the
 * refusals around both.
 *
 * Every scenario runs on the package's own migrations and the real
 * {@see WritesEntitlement}, wrapped in {@see CapturingEntitlementWriter} so the
 * claim the action hands over (authoritative or not, which stamp) is asserted as
 * well as the columns it leaves behind. The clock is frozen, because two of the
 * rules this file depends on compare whole seconds.
 */
class AdministerBillingGrantTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set([
            'database.default' => 'testing',
            'database.connections.testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'magic-starter.use_uuids' => false,
            'magic-starter.billing.billable' => 'user',
            'magic-starter.billing.tier_order' => ['free', 'pro', 'business'],
            'magic-starter.billing.products' => [
                'pro_monthly' => [
                    'type' => 'subscription',
                    'tier' => 'pro',
                    'cycle' => 'monthly',
                    'refs' => ['stripe_price' => 'price_pro'],
                ],
                'business_monthly' => [
                    'type' => 'subscription',
                    'tier' => 'business',
                    'cycle' => 'monthly',
                    'refs' => ['stripe_price' => 'price_business', 'app_store' => 'starter_business_monthly'],
                ],
            ],
            'magic-starter.billing.revenuecat.secret_api_key' => null,
            'magic-starter.billing.revenuecat.base_url' => RevenueCatClient::DEFAULT_BASE_URL,
            'magic-starter.billing.revenuecat.accept_sandbox' => false,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::useUserModel(User::class);
        Cashier::useSubscriptionModel(Subscription::class);

        CapturingEntitlementWriter::reset();
        $this->app->extend(
            WritesEntitlement::class,
            fn (WritesEntitlement $inner): WritesEntitlement => new CapturingEntitlementWriter($inner),
        );

        $this->travelTo($this->now());

        foreach ([
            'create_users_table.php',
            'add_cashier_customer_columns_to_billable_table.php',
            'add_entitlement_provenance_to_billable_table.php',
            'create_subscriptions_table.php',
            'create_subscription_items_table.php',
            'create_billing_events_table.php',
            'create_billing_grants_table.php',
        ] as $migration) {
            (require __DIR__ . '/../../database/migrations/' . $migration)->up();
        }
    }

    protected function tearDown(): void
    {
        Cashier::useSubscriptionModel(CashierSubscription::class);
        MagicStarter::reset();

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Binding
    // -------------------------------------------------------------------------

    public function test_the_contract_resolves_to_the_package_action(): void
    {
        $this->assertInstanceOf(AdministerBilling::class, $this->app->make(AdministersBilling::class));
    }

    // -------------------------------------------------------------------------
    // Grant
    // -------------------------------------------------------------------------

    public function test_a_grant_applies_on_a_billable_no_rail_bills(): void
    {
        $operator = $this->operator();
        $billable = $this->makeBillable();
        $expiresAt = $this->now()->addMonth();

        $grant = $this->administer()->grant($operator, $billable, 'business', 'Conference comp', $expiresAt);

        // 1. The grant row, naming who gave what, why and until when.
        $this->assertTrue($grant->exists);
        $this->assertSame('business', $grant->plan);
        $this->assertSame('Conference comp', $grant->reason);
        $this->assertSame((string) $billable->getKey(), $grant->billable_id);
        $this->assertSame($billable->getMorphClass(), $grant->billable_type);
        $this->assertEquals($operator->getKey(), $grant->granted_by);
        $this->assertSame($expiresAt->getTimestamp(), $grant->expires_at?->getTimestamp());
        $this->assertNull($grant->ended_at);

        // 2. The entitlement, stamped with the grant so expiry and revoke can
        //    tell the billable is still on it.
        $billable->refresh();
        $this->assertSame('business', $billable->getAttribute('plan'));
        $this->assertSame(PlanStatus::ACTIVE->value, $billable->getAttribute('plan_status'));
        $this->assertSame(BillingProvider::MANUAL->value, $billable->getAttribute('plan_provider'));
        $this->assertSame('grant:' . $grant->getKey(), $billable->getAttribute('plan_product_id'));

        // 3. Written as a projection, never as a rail speaking for itself.
        $this->assertCount(1, CapturingEntitlementWriter::$writes);
        $claim = CapturingEntitlementWriter::$writes[0];
        $this->assertFalse($claim->authoritative);
        $this->assertSame(BillingSource::ADMIN, $claim->source);
        $this->assertFalse($claim->renews);
        $this->assertSame($expiresAt->getTimestamp(), $claim->currentPeriodEnd?->getTimestamp());

        // 4. One row for the operator's act and one for the entitlement it moved.
        $added = $this->eventsOf(BillingEventType::GRANT_ADDED);
        $this->assertCount(1, $added);
        $this->assertSame(BillingSource::ADMIN, $added[0]->source);
        $this->assertSame(BillingProvider::MANUAL, $added[0]->provider);
        $this->assertEquals($operator->getKey(), $added[0]->actor_user_id);
        $this->assertSame('business', $added[0]->properties['plan']);
        $this->assertSame('Conference comp', $added[0]->properties['reason']);
        $this->assertSame($grant->getKey(), $added[0]->properties['grant_id']);
        $this->assertSame($expiresAt->toIso8601ZuluString(), $added[0]->properties['expires_at']);

        $applied = $this->eventsOf(BillingEventType::ENTITLEMENT_APPLIED);
        $this->assertCount(1, $applied);
        $this->assertSame(BillingSource::ADMIN, $applied[0]->source);
    }

    public function test_a_grant_is_refused_while_a_stripe_record_grants(): void
    {
        $billable = $this->makeBillable([
            'plan' => 'pro',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::STRIPE->value,
            'plan_source_event_at' => $this->now()->subDay(),
        ]);

        $this->assertRefused('paid_rail_active', fn () => $this->administer()->grant(
            $this->operator(),
            $billable,
            'business',
            'Comp',
            null,
        ));

        $this->assertSame(0, BillingGrant::query()->count());
        $this->assertSame(BillingProvider::STRIPE->value, $billable->refresh()->getAttribute('plan_provider'));
        $this->assertSame([], CapturingEntitlementWriter::$writes);

        $refused = $this->eventsOf(BillingEventType::REQUEST_REFUSED);
        $this->assertCount(1, $refused);
        $this->assertSame(BillingSource::ADMIN, $refused[0]->source);
        $this->assertSame('paid_rail_active', $refused[0]->reason);
        $this->assertSame(BillingProvider::STRIPE, $refused[0]->provider);
    }

    /**
     * The stranded payer: a checkout over a comp was dropped by the write rules,
     * so the record says NONE while a local Cashier subscription is billing.
     * The record alone is not proof, so the local row is read too.
     */
    public function test_a_grant_is_refused_when_only_the_local_cashier_subscription_grants(): void
    {
        $billable = $this->makeBillable();
        $this->makeSubscription($billable, 'price_pro');

        $this->assertTrue($this->administer()->paidRailGrants($billable));

        $this->assertRefused('paid_rail_active', fn () => $this->administer()->grant(
            $this->operator(),
            $billable,
            'business',
            'Comp',
            null,
        ));

        $this->assertSame(0, BillingGrant::query()->count());
        $this->assertSame(BillingProvider::STRIPE, $this->eventsOf(BillingEventType::REQUEST_REFUSED)[0]->provider);
    }

    /**
     * A checkout that lands after the up-front check and before the lock is
     * caught by the re-check on the locked row. The refusal is recorded after
     * the grant's transaction rolled back, so its row survives that rollback.
     */
    public function test_a_checkout_landing_before_the_lock_is_refused_on_the_locked_reread(): void
    {
        $billable = $this->makeBillable();

        Event::listen(TransactionBeginning::class, function () use ($billable): void {
            if (Subscription::query()->count() === 0) {
                $this->makeSubscription($billable, 'price_pro');
            }
        });

        $this->assertRefused('paid_rail_active', fn () => $this->administer()->grant(
            $this->operator(),
            $billable,
            'business',
            'Comp',
            null,
        ));

        $this->assertSame(0, BillingGrant::query()->count());
        $this->assertSame([], CapturingEntitlementWriter::$writes);

        $refused = $this->eventsOf(BillingEventType::REQUEST_REFUSED);
        $this->assertCount(1, $refused);
        $this->assertSame(BillingProvider::STRIPE, $refused[0]->provider);
    }

    public function test_an_ended_local_subscription_is_not_a_paid_rail(): void
    {
        $billable = $this->makeBillable();
        $this->makeSubscription($billable, 'price_pro', endsAt: $this->now()->subDay());

        $this->assertFalse($this->administer()->paidRailGrants($billable));
    }

    public function test_a_manual_record_alone_is_not_a_paid_rail(): void
    {
        $billable = $this->makeBillable([
            'plan' => 'pro',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::MANUAL->value,
        ]);

        $this->assertFalse($this->administer()->paidRailGrants($billable));
    }

    public function test_a_grant_of_a_tier_the_catalogue_does_not_rank_is_refused(): void
    {
        $billable = $this->makeBillable();

        $this->assertRefused('unknown_plan', fn () => $this->administer()->grant(
            $this->operator(),
            $billable,
            'enterprise',
            'Comp',
            null,
        ));

        $this->assertSame(0, BillingGrant::query()->count());
        $this->assertCount(1, $this->eventsOf(BillingEventType::REQUEST_REFUSED));
    }

    public function test_a_grant_expiring_now_or_earlier_is_refused(): void
    {
        $billable = $this->makeBillable();

        $this->assertRefused('expiry_in_past', fn () => $this->administer()->grant(
            $this->operator(),
            $billable,
            'pro',
            'Comp',
            $this->now(),
        ));

        $this->assertSame(0, BillingGrant::query()->count());
    }

    public function test_a_second_grant_supersedes_the_first(): void
    {
        $operator = $this->operator();
        $billable = $this->makeBillable();

        $first = $this->administer()->grant($operator, $billable, 'business', 'First', null);

        // Same frozen second, and a lower tier: the operator's latest word
        // still has to land.
        $second = $this->administer()->grant($operator, $billable, 'pro', 'Corrected', null);

        $first->refresh();
        $this->assertNotNull($first->ended_at);
        $this->assertSame(GrantEndReason::SUPERSEDED, $first->end_reason);
        $this->assertNull($second->refresh()->ended_at);
        $this->assertSame(1, BillingGrant::forBillable($billable)->open()->count());

        $billable->refresh();
        $this->assertSame('pro', $billable->getAttribute('plan'));
        $this->assertSame('grant:' . $second->getKey(), $billable->getAttribute('plan_product_id'));
        $this->assertCount(2, $this->eventsOf(BillingEventType::GRANT_ADDED));
    }

    /**
     * A write the rules drop rolls the whole grant back, the superseding of
     * the previous grant included, and the refusal row is the record because
     * it is written after that rollback.
     */
    public function test_a_dropped_write_rolls_the_grant_back_and_leaves_the_refusal(): void
    {
        $operator = $this->operator();
        $billable = $this->makeBillable();
        $first = $this->administer()->grant($operator, $billable, 'business', 'First', null);

        CapturingEntitlementWriter::$answer = false;

        $this->assertRefused('entitlement_refused', fn () => $this->administer()->grant(
            $operator,
            $billable,
            'pro',
            'Second',
            null,
        ));

        $this->assertSame(1, BillingGrant::query()->count());
        $this->assertNull($first->refresh()->ended_at);
        $this->assertCount(1, $this->eventsOf(BillingEventType::GRANT_ADDED));

        $refused = $this->eventsOf(BillingEventType::REQUEST_REFUSED);
        $this->assertCount(1, $refused);
        $this->assertSame('entitlement_refused', $refused[0]->reason);
        $this->assertSame(BillingProvider::MANUAL, $refused[0]->provider);
    }

    // -------------------------------------------------------------------------
    // Revoke
    // -------------------------------------------------------------------------

    /**
     * A customer who bought during the comp keeps access: their checkout was
     * dropped while the comp held the record, so ending the comp has to put
     * the paid rail back rather than leave them on nothing.
     */
    public function test_revoking_the_open_grant_reprojects_an_active_cashier_subscription(): void
    {
        $operator = $this->operator();
        $billable = $this->makeBillable();
        $grant = $this->administer()->grant($operator, $billable, 'pro', 'Comp', null);

        $this->makeSubscription($billable, 'price_business');

        $this->administer()->revoke($operator, $billable, 'Bought a plan');

        $grant->refresh();
        $this->assertSame(GrantEndReason::REVOKED, $grant->end_reason);
        $this->assertNotNull($grant->ended_at);

        $billable->refresh();
        $this->assertSame('business', $billable->getAttribute('plan'));
        $this->assertSame(PlanStatus::ACTIVE->value, $billable->getAttribute('plan_status'));
        $this->assertSame(BillingProvider::STRIPE->value, $billable->getAttribute('plan_provider'));

        $revocation = CapturingEntitlementWriter::$writes[1];
        $this->assertNull($revocation->plan);
        $this->assertSame(PlanStatus::CANCELED, $revocation->status);
        $this->assertSame(BillingProvider::MANUAL, $revocation->provider);
        $this->assertTrue($revocation->authoritative);
        $this->assertSame('grant:' . $grant->getKey(), $revocation->productId);

        $revoked = $this->eventsOf(BillingEventType::GRANT_REVOKED);
        $this->assertCount(1, $revoked);
        $this->assertSame(BillingSource::ADMIN, $revoked[0]->source);
        $this->assertSame('Bought a plan', $revoked[0]->properties['reason']);
        $this->assertSame($grant->getKey(), $revoked[0]->properties['grant_id']);
    }

    /**
     * Granted and revoked inside one frozen second: the revocation is stamped
     * one second past the grant, or rule 1b would drop it as a same-instant
     * revocation and the comp would outlive its revoke.
     */
    public function test_a_revoke_in_the_grants_own_second_still_applies(): void
    {
        $operator = $this->operator();
        $billable = $this->makeBillable();
        $this->administer()->grant($operator, $billable, 'pro', 'Comp', null);

        $this->administer()->revoke($operator, $billable, 'Mistake');

        $billable->refresh();
        $this->assertNull($billable->getAttribute('plan'));
        $this->assertSame(PlanStatus::CANCELED->value, $billable->getAttribute('plan_status'));
        $this->assertSame(
            $this->now()->addSecond()->getTimestamp(),
            CapturingEntitlementWriter::$writes[1]->eventAt->getTimestamp(),
        );
    }

    public function test_revoking_a_stripe_record_is_refused_as_not_manual(): void
    {
        $billable = $this->makeBillable([
            'plan' => 'pro',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::STRIPE->value,
        ]);

        $this->assertRefused('not_manual', fn () => $this->administer()->revoke(
            $this->operator(),
            $billable,
            'Wrong customer',
        ));

        $this->assertSame([], CapturingEntitlementWriter::$writes);
        $this->assertSame(BillingProvider::STRIPE->value, $billable->refresh()->getAttribute('plan_provider'));

        $refused = $this->eventsOf(BillingEventType::REQUEST_REFUSED);
        $this->assertCount(1, $refused);
        $this->assertSame('not_manual', $refused[0]->reason);
        $this->assertSame(BillingSource::ADMIN, $refused[0]->source);
    }

    /**
     * A store grant left behind by a delisted sandbox id: the store job will
     * never revoke it (a sandbox-only subscriber is no evidence), so the
     * operator can.
     */
    public function test_revoking_a_sandbox_only_store_record_writes_an_expiry(): void
    {
        config(['magic-starter.billing.revenuecat.secret_api_key' => 'sk_test_revenuecat_secret']);

        $billable = $this->makeBillable([
            'plan' => 'business',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::APP_STORE->value,
            'plan_source_event_at' => $this->now(),
        ]);

        Http::fake([
            '*' => Http::response($this->subscriber([
                'starter_business_monthly' => $this->storeSubscription(['is_sandbox' => true]),
            ])),
        ]);

        $this->administer()->revoke($this->operator(), $billable, 'Sandbox tester');

        $billable->refresh();
        $this->assertNull($billable->getAttribute('plan'));
        $this->assertSame(PlanStatus::EXPIRED->value, $billable->getAttribute('plan_status'));
        $this->assertSame(BillingProvider::APP_STORE->value, $billable->getAttribute('plan_provider'));

        $revocation = CapturingEntitlementWriter::$writes[0];
        $this->assertTrue($revocation->authoritative);
        $this->assertSame(BillingSource::ADMIN, $revocation->source);

        $revoked = $this->eventsOf(BillingEventType::GRANT_REVOKED);
        $this->assertCount(1, $revoked);
        $this->assertTrue($revoked[0]->properties['store_sandbox_only']);
        $this->assertSame(BillingProvider::APP_STORE, $revoked[0]->provider);
    }

    public function test_a_store_record_with_a_production_subscription_is_refused_as_not_manual(): void
    {
        config(['magic-starter.billing.revenuecat.secret_api_key' => 'sk_test_revenuecat_secret']);

        $billable = $this->makeBillable([
            'plan' => 'business',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::APP_STORE->value,
        ]);

        Http::fake([
            '*' => Http::response($this->subscriber([
                'starter_business_monthly' => $this->storeSubscription(),
            ])),
        ]);

        $this->assertRefused('not_manual', fn () => $this->administer()->revoke(
            $this->operator(),
            $billable,
            'Wrong customer',
        ));

        $this->assertSame([], CapturingEntitlementWriter::$writes);
    }

    public function test_a_failed_revenuecat_read_is_a_recorded_rail_error(): void
    {
        config(['magic-starter.billing.revenuecat.secret_api_key' => 'sk_test_revenuecat_secret']);

        $billable = $this->makeBillable([
            'plan' => 'business',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::APP_STORE->value,
        ]);

        Http::fake(['*' => Http::response(['message' => 'unauthorized'], 401)]);

        $this->assertRefused('rail_error', fn () => $this->administer()->revoke(
            $this->operator(),
            $billable,
            'Sandbox tester',
        ));

        $this->assertSame([], CapturingEntitlementWriter::$writes);
        $this->assertSame(PlanStatus::ACTIVE->value, $billable->refresh()->getAttribute('plan_status'));

        $refused = $this->eventsOf(BillingEventType::REQUEST_REFUSED);
        $this->assertCount(1, $refused);
        $this->assertSame('rail_error', $refused[0]->reason);
        $this->assertSame(BillingProvider::APP_STORE, $refused[0]->provider);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Run a call that must refuse, and assert the refusal's stable reason and
     * that its message is the translated sentence rather than the key.
     */
    private function assertRefused(string $reason, callable $call): void
    {
        try {
            $call();
            $this->fail("The operation was not refused with [{$reason}].");
        } catch (BillingAdministrationRefused $refusal) {
            $this->assertSame($reason, $refusal->reason());
            $this->assertSame(__('magic-starter::admin_billing.refusals.' . $reason), $refusal->getMessage());
            $this->assertStringNotContainsString('admin_billing', $refusal->getMessage());
        }
    }

    private function administer(): AdministersBilling
    {
        return $this->app->make(AdministersBilling::class);
    }

    /**
     * @return list<BillingEvent>
     */
    private function eventsOf(BillingEventType $type): array
    {
        return BillingEvent::query()->where('type', $type->value)->orderBy('id')->get()->all();
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-10 12:00:00');
    }

    private function operator(): User
    {
        return $this->makeBillable(['name' => 'Operator']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeBillable(array $attributes = []): User
    {
        return User::query()->create([
            'name' => 'Payer',
            'email' => 'payer-' . Str::random(10) . '@example.test',
            'password' => 'secret',
            ...$attributes,
        ]);
    }

    private function makeSubscription(
        Model $billable,
        string $priceId,
        string $status = 'active',
        ?CarbonImmutable $endsAt = null,
    ): Subscription {
        $subscription = new Subscription;

        $subscription->forceFill([
            $billable->getForeignKey() => $billable->getKey(),
            'type' => 'default',
            'stripe_id' => 'sub_' . Str::random(10),
            'stripe_status' => $status,
            'stripe_price' => $priceId,
            'quantity' => 1,
            'ends_at' => $endsAt,
        ])->save();

        return $subscription;
    }

    /**
     * @param  array<string, array<string, mixed>>  $subscriptions
     * @return array<string, mixed>
     */
    private function subscriber(array $subscriptions): array
    {
        return [
            'subscriber' => [
                'original_app_user_id' => 'irrelevant',
                'management_url' => 'https://apps.apple.com/account/subscriptions',
                'subscriptions' => $subscriptions,
                'entitlements' => [],
                'non_subscriptions' => [],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function storeSubscription(array $overrides = []): array
    {
        return [
            'expires_date' => $this->now()->addMonth()->toIso8601ZuluString(),
            'grace_period_expires_date' => null,
            'is_sandbox' => false,
            'store' => 'app_store',
            'period_type' => 'normal',
            'ownership_type' => 'PURCHASED',
            'purchase_date' => $this->now()->subMonth()->toIso8601ZuluString(),
            ...$overrides,
        ];
    }
}

/**
 * The billable, carrying Cashier's trait and the basename the subscriptions
 * migration derives its foreign key from.
 */
class User extends ConcreteUser
{
    use Billable;

    protected $table = 'users';
}

/**
 * Hands every claim to the real writer and keeps it, so a test can assert the
 * claim as well as its outcome; `$answer = false` stands in for a rule that
 * drops the write.
 */
class CapturingEntitlementWriter implements WritesEntitlement
{
    /** @var list<EntitlementWrite> */
    public static array $writes = [];

    public static ?bool $answer = null;

    public function __construct(private WritesEntitlement $inner) {}

    public static function reset(): void
    {
        self::$writes = [];
        self::$answer = null;
    }

    public function write(EntitlementWrite $write): bool
    {
        self::$writes[] = $write;

        return self::$answer ?? $this->inner->write($write);
    }
}
