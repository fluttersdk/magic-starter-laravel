<?php

namespace FlutterSdk\MagicStarter\Tests\Traits;

use FlutterSdk\MagicStarter\Enums\BillingProvider;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\TestCase;
use FlutterSdk\MagicStarter\Traits\HasEntitlement;
use Illuminate\Support\Carbon;

/**
 * The billable model answers "is this paid for, and at what tier" the same way
 * for every rail: an App Store buyer is entitled exactly like a Stripe buyer.
 */
class HasEntitlementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'magic-starter.billing.tier_order' => ['free', 'pro', 'business'],
            'magic-starter.billing.products' => [
                'pro_monthly' => [
                    'type' => 'subscription',
                    'tier' => 'pro',
                    'cycle' => 'monthly',
                    'refs' => [
                        'stripe_price' => 'price_pro_monthly',
                        'app_store' => 'com.example.pro.monthly',
                        'play' => 'pro_sub:monthly',
                    ],
                ],
                'business_monthly' => [
                    'type' => 'subscription',
                    'tier' => 'business',
                    'cycle' => 'monthly',
                    'refs' => [
                        'stripe_price' => 'price_business_monthly',
                    ],
                ],
            ],
        ]);
    }

    public function test_a_store_billed_paid_tier_is_entitled(): void
    {
        $team = $this->billable(['plan' => 'pro', 'plan_status' => 'active', 'plan_provider' => 'app_store']);

        $this->assertTrue($team->entitled());
        $this->assertTrue($team->onTier('pro'));
        $this->assertFalse($team->onTier('business'));
        $this->assertTrue($team->tierAtLeast('pro'));
        $this->assertFalse($team->tierAtLeast('business'));
        $this->assertSame(BillingProvider::APP_STORE, $team->entitlementProvider());
    }

    public function test_a_stripe_buyer_answers_exactly_like_a_store_buyer(): void
    {
        $team = $this->billable(['plan' => 'pro', 'plan_status' => 'active', 'plan_provider' => 'stripe']);

        $this->assertTrue($team->entitled());
        $this->assertTrue($team->onTier('pro'));
        $this->assertTrue($team->tierAtLeast('pro'));
        $this->assertFalse($team->tierAtLeast('business'));
        $this->assertSame(BillingProvider::STRIPE, $team->entitlementProvider());
    }

    public function test_the_free_floor_is_not_entitled(): void
    {
        $team = $this->billable(['plan' => 'free', 'plan_status' => 'active', 'plan_provider' => 'none']);

        $this->assertFalse($team->entitled());
        $this->assertTrue($team->onTier('free'));
        $this->assertTrue($team->tierAtLeast('free'));
        $this->assertFalse($team->tierAtLeast('pro'));
    }

    public function test_a_subscriber_with_no_plan_is_on_the_floor(): void
    {
        $team = $this->billable([]);

        $this->assertFalse($team->entitled());
        $this->assertTrue($team->onTier('free'));
        $this->assertFalse($team->onTier('pro'));
        $this->assertSame(BillingProvider::NONE, $team->entitlementProvider());
    }

    public function test_a_finished_plan_reads_as_the_floor_even_while_the_tier_is_stored(): void
    {
        $team = $this->billable(['plan' => 'pro', 'plan_status' => 'canceled', 'plan_provider' => 'app_store']);

        $this->assertFalse($team->entitled());
        $this->assertFalse($team->onTier('pro'));
        $this->assertTrue($team->onTier('free'));
        $this->assertFalse($team->tierAtLeast('pro'));
    }

    public function test_a_dunning_plan_is_still_entitled(): void
    {
        $team = $this->billable(['plan' => 'business', 'plan_status' => 'past_due', 'plan_provider' => 'stripe']);

        $this->assertTrue($team->entitled());
        $this->assertTrue($team->tierAtLeast('pro'));
    }

    public function test_a_tier_outside_the_ranking_is_never_reached(): void
    {
        $team = $this->billable(['plan' => 'pro', 'plan_status' => 'active', 'plan_provider' => 'stripe']);

        $this->assertFalse($team->tierAtLeast('platinum'));
    }

    public function test_a_stored_tier_outside_the_ranking_reaches_nothing(): void
    {
        $team = $this->billable(['plan' => 'legacy', 'plan_status' => 'active', 'plan_provider' => 'manual']);

        $this->assertTrue($team->entitled());
        $this->assertFalse($team->tierAtLeast('pro'));
    }

    public function test_the_grace_period_is_a_future_end_date(): void
    {
        Carbon::setTestNow('2026-10-09 12:00:00');

        $inside = $this->billable(['plan_grace_period_ends_at' => '2026-10-10 12:00:00']);
        $over = $this->billable(['plan_grace_period_ends_at' => '2026-10-08 12:00:00']);
        $none = $this->billable([]);

        $this->assertTrue($inside->onGracePeriod());
        $this->assertFalse($over->onGracePeriod());
        $this->assertFalse($none->onGracePeriod());
    }

    public function test_the_entitled_product_is_found_by_store_id_and_by_stripe_price(): void
    {
        $store = $this->billable([
            'plan' => 'pro',
            'plan_status' => 'active',
            'plan_provider' => 'app_store',
            'plan_product_id' => 'com.example.pro.monthly',
        ]);
        $stripe = $this->billable([
            'plan' => 'business',
            'plan_status' => 'active',
            'plan_provider' => 'stripe',
            'plan_product_id' => 'price_business_monthly',
        ]);

        $this->assertSame('pro_monthly', $store->entitledProduct());
        $this->assertSame('business_monthly', $stripe->entitledProduct());
    }

    public function test_no_product_is_named_without_an_entitlement_or_a_catalogue_match(): void
    {
        $lapsed = $this->billable([
            'plan' => 'pro',
            'plan_status' => 'expired',
            'plan_provider' => 'app_store',
            'plan_product_id' => 'com.example.pro.monthly',
        ]);
        $unmapped = $this->billable([
            'plan' => 'pro',
            'plan_status' => 'active',
            'plan_provider' => 'app_store',
            'plan_product_id' => 'com.example.unknown',
        ]);
        $unrecorded = $this->billable(['plan' => 'pro', 'plan_status' => 'active', 'plan_provider' => 'manual']);

        $this->assertNull($lapsed->entitledProduct());
        $this->assertNull($unmapped->entitledProduct());
        $this->assertNull($unrecorded->entitledProduct());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function billable(array $attributes): HasEntitlementTestTeam
    {
        return (new HasEntitlementTestTeam)->forceFill($attributes);
    }
}

/**
 * A billable carrying the trait, with no casts on the billing columns.
 */
class HasEntitlementTestTeam extends ConcreteTeam
{
    use HasEntitlement;
}
