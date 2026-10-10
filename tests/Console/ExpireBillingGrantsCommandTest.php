<?php

namespace FlutterSdk\MagicStarter\Tests\Console;

use Carbon\CarbonImmutable;
use FlutterSdk\MagicStarter\Console\ExpireBillingGrantsCommand;
use FlutterSdk\MagicStarter\Contracts\AdministersBilling;
use FlutterSdk\MagicStarter\Contracts\WritesEntitlement;
use FlutterSdk\MagicStarter\Enums\BillingEventType;
use FlutterSdk\MagicStarter\Enums\BillingProvider;
use FlutterSdk\MagicStarter\Enums\BillingSource;
use FlutterSdk\MagicStarter\Enums\GrantEndReason;
use FlutterSdk\MagicStarter\Enums\PlanStatus;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\BillingEvent;
use FlutterSdk\MagicStarter\Models\BillingGrant;
use FlutterSdk\MagicStarter\Models\Subscription;
use FlutterSdk\MagicStarter\Support\EntitlementWrite;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Laravel\Cashier\Billable;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription as CashierSubscription;

/**
 * `magic-starter:billing:expire-grants` releases a manual grant at its expiry,
 * but only while the billable is still on that grant, and ends every grant it
 * looks at whatever the write path decides, so nothing is retried hourly.
 */
class ExpireBillingGrantsCommandTest extends TestCase
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
            'magic-starter.features' => [Features::billing()],
            'magic-starter.billing' => BillingManifestCommandTest::billing(),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::useUserModel(GrantSweepUser::class);
        Cashier::useSubscriptionModel(Subscription::class);

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

    public function test_an_expired_grant_the_billable_is_still_on_is_revoked_and_ended(): void
    {
        $billable = $this->makeBillable();
        $grant = $this->grant($billable, 'pro', $this->now()->addDay());

        $this->travelTo($this->now()->addDays(2));

        $this->artisan(ExpireBillingGrantsCommand::NAME)
            ->expectsOutputToContain('Expired 1 grant(s), superseded 0.')
            ->assertSuccessful();

        $grant->refresh();
        $this->assertSame(GrantEndReason::EXPIRED, $grant->end_reason);
        $this->assertNotNull($grant->ended_at);

        $billable->refresh();
        $this->assertNull($billable->getAttribute('plan'));
        $this->assertSame(PlanStatus::EXPIRED->value, $billable->getAttribute('plan_status'));
        $this->assertSame(BillingProvider::MANUAL->value, $billable->getAttribute('plan_provider'));

        $expired = $this->eventsOf(BillingEventType::GRANT_EXPIRED);
        $this->assertCount(1, $expired);
        $this->assertSame(BillingSource::ADMIN, $expired[0]->source);
        $this->assertNull($expired[0]->actor_user_id);
        $this->assertSame($grant->getKey(), $expired[0]->properties['grant_id']);
        $this->assertTrue($expired[0]->properties['entitlement_written']);
    }

    /**
     * Granted and expired inside one frozen second. Stamped `now()`, the
     * revocation would tie with the grant and rule 1b would drop it as a
     * same-instant revocation; one second past the stored stamp, it applies.
     */
    public function test_an_expiry_in_the_grants_own_second_still_applies(): void
    {
        $billable = $this->makeBillable();
        $grant = $this->grant($billable, 'pro', $this->now()->addDay());
        $grant->forceFill(['expires_at' => $this->now()])->save();

        $this->artisan(ExpireBillingGrantsCommand::NAME)->assertSuccessful();

        $billable->refresh();
        $this->assertNull($billable->getAttribute('plan'));
        $this->assertSame(PlanStatus::EXPIRED->value, $billable->getAttribute('plan_status'));
        $this->assertSame(GrantEndReason::EXPIRED, $grant->refresh()->end_reason);
        $this->assertCount(0, $this->eventsOf(BillingEventType::ENTITLEMENT_DROPPED));
    }

    public function test_an_older_grant_a_newer_one_replaced_ends_superseded_and_writes_nothing(): void
    {
        $billable = $this->makeBillable();
        $newer = $this->grant($billable, 'business', null);

        // An older grant still open beside it, which is the state a crash
        // between the two writes of a re-grant could leave behind.
        $older = BillingGrant::query()->create([
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
            'plan' => 'pro',
            'reason' => 'Older comp',
            'expires_at' => $this->now()->subHour(),
        ]);

        $before = BillingEvent::query()->count();

        $this->artisan(ExpireBillingGrantsCommand::NAME)
            ->expectsOutputToContain('Expired 0 grant(s), superseded 1.')
            ->assertSuccessful();

        $this->assertSame(GrantEndReason::SUPERSEDED, $older->refresh()->end_reason);
        $this->assertNull($newer->refresh()->ended_at);

        $billable->refresh();
        $this->assertSame('business', $billable->getAttribute('plan'));
        $this->assertSame('grant:' . $newer->getKey(), $billable->getAttribute('plan_product_id'));
        $this->assertSame($before, BillingEvent::query()->count());
    }

    public function test_an_open_grant_a_paid_rail_took_over_ends_superseded_without_a_write(): void
    {
        $billable = $this->makeBillable();
        $grant = $this->grant($billable, 'pro', $this->now()->addMonth());

        $billable->forceFill([
            'plan' => 'business',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::STRIPE->value,
            'plan_product_id' => 'price_business_monthly',
        ])->save();

        $this->artisan(ExpireBillingGrantsCommand::NAME)->assertSuccessful();

        $this->assertSame(GrantEndReason::SUPERSEDED, $grant->refresh()->end_reason);
        $this->assertSame(BillingProvider::STRIPE->value, $billable->refresh()->getAttribute('plan_provider'));
        $this->assertCount(0, $this->eventsOf(BillingEventType::GRANT_EXPIRED));
    }

    public function test_an_unexpired_grant_the_billable_is_on_is_left_open(): void
    {
        $billable = $this->makeBillable();
        $grant = $this->grant($billable, 'pro', $this->now()->addMonth());

        $this->artisan(ExpireBillingGrantsCommand::NAME)
            ->expectsOutputToContain('Expired 0 grant(s), superseded 0.')
            ->assertSuccessful();

        $this->assertNull($grant->refresh()->ended_at);
        $this->assertSame('pro', $billable->refresh()->getAttribute('plan'));
    }

    public function test_an_expiry_reprojects_an_active_cashier_subscription(): void
    {
        $billable = $this->makeBillable();
        $this->grant($billable, 'pro', $this->now()->addDay());
        $this->makeSubscription($billable, 'price_business_monthly');

        $this->travelTo($this->now()->addDays(2));

        $this->artisan(ExpireBillingGrantsCommand::NAME)->assertSuccessful();

        $billable->refresh();
        $this->assertSame('business', $billable->getAttribute('plan'));
        $this->assertSame(PlanStatus::ACTIVE->value, $billable->getAttribute('plan_status'));
        $this->assertSame(BillingProvider::STRIPE->value, $billable->getAttribute('plan_provider'));
    }

    public function test_the_grant_ends_even_when_the_write_path_drops_the_revocation(): void
    {
        $billable = $this->makeBillable();
        $grant = $this->grant($billable, 'pro', $this->now()->addDay());

        $this->app->bind(WritesEntitlement::class, fn (): WritesEntitlement => new class implements WritesEntitlement
        {
            public function write(EntitlementWrite $write): bool
            {
                return false;
            }
        });

        $this->travelTo($this->now()->addDays(2));

        $this->artisan(ExpireBillingGrantsCommand::NAME)->assertSuccessful();

        $this->assertSame(GrantEndReason::EXPIRED, $grant->refresh()->end_reason);
        $this->assertFalse($this->eventsOf(BillingEventType::GRANT_EXPIRED)[0]->properties['entitlement_written']);

        // A second run finds nothing open, so a dropped write is not retried hourly.
        $this->artisan(ExpireBillingGrantsCommand::NAME)
            ->expectsOutputToContain('Expired 0 grant(s), superseded 0.')
            ->assertSuccessful();
    }

    private function grant(Model $billable, string $plan, ?CarbonImmutable $expiresAt): BillingGrant
    {
        return $this->app->make(AdministersBilling::class)
            ->grant($this->makeBillable(['name' => 'Operator']), $billable, $plan, 'Comp', $expiresAt);
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeBillable(array $attributes = []): GrantSweepUser
    {
        return GrantSweepUser::query()->create([
            'name' => 'Payer',
            'email' => 'payer-' . Str::random(10) . '@example.test',
            'password' => 'secret',
            ...$attributes,
        ]);
    }

    private function makeSubscription(Model $billable, string $priceId): Subscription
    {
        $subscription = new Subscription;

        $subscription->forceFill([
            $billable->getForeignKey() => $billable->getKey(),
            'type' => 'default',
            'stripe_id' => 'sub_' . Str::random(10),
            'stripe_status' => 'active',
            'stripe_price' => $priceId,
            'quantity' => 1,
        ])->save();

        return $subscription;
    }
}

/**
 * The billable, with Cashier's trait. Its basename is not `User`, so the
 * foreign key Cashier and the subscriptions migration derive is pinned to the
 * column a consumer's `users` billable actually has.
 */
class GrantSweepUser extends ConcreteUser
{
    use Billable;

    protected $table = 'users';

    public function getForeignKey(): string
    {
        return 'user_id';
    }
}
