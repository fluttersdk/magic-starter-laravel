<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Http\Controllers\BillingController;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Models\Team;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use FlutterSdk\MagicStarter\Support\StripeSubscriptionState;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Laravel\Cashier\Checkout;
use LogicException;
use Stripe\Checkout\Session as StripeCheckoutSession;

/**
 * The three billing WRITES, driven through the routes the package registers.
 *
 * THE CLAIM THIS FILE EXISTS FOR is that a checkout is validated against the
 * adopter's own published catalogue rather than against a tier vocabulary the
 * package does not have. That claim cannot be tested from the populated case
 * alone: a rule accepting anything and a rule reading the published ranking both
 * answer 200 to a published tier, and only the two refusals tell them apart. So
 * every catalogue test here is a PAIR, one limb refusing and one accepting, and
 * neither limb is decorative.
 *
 * The unpublished case is the one with money behind it. An adopter who has
 * published nothing must not be able to sell anything, and the sentence they get
 * has to name the config they are missing, because the fault is in their config
 * file and every other clue points at the client's request body.
 *
 * The rail is a fixture ({@see BillingWriteRail}) and never a live Stripe call:
 * the billable model belongs to the consuming application, so what these
 * endpoints depend on is that a model carries the Cashier verbs at all, and a
 * fixture is the honest way to drive both the present and the absent case.
 */
class BillingWriteEndpointsTest extends TestCase
{
    /**
     * The Stripe customer id the billable fixtures carry.
     */
    private const STRIPE_ID = 'cus_billing_write_test';

    /**
     * The two URLs Stripe sends a finished or abandoned checkout back to.
     */
    private const SUCCESS_URL = 'https://app.example.test/billing/success';

    private const CANCEL_URL = 'https://app.example.test/billing/cancel';

    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::reset();
        BillingWriteRail::reset();

        config([
            'database.default' => 'testing',
            'database.connections.testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'magic-starter.models.user' => BillingWriteUser::class,
            'magic-starter.models.team' => BillingWriteTeam::class,
            'magic-starter.models.membership' => \FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeamUser::class,
            'magic-starter.route_prefix' => '',
            // The adopter's ranking, cheapest first, and the prices that sell two
            // of its three tiers. `business` is deliberately unpriced: it is what
            // makes the config-gap refusal drivable without editing the ranking.
            'magic-starter.billing.tier_order' => ['free', 'pro', 'business'],
            'magic-starter.billing.plans' => [
                ['id' => 'free', 'name' => 'Free'],
                ['id' => 'pro', 'name' => 'Pro'],
                ['id' => 'business', 'name' => 'Business'],
            ],
            'magic-starter.billing.prices' => [
                'price_free' => 'free',
                'price_pro' => 'pro',
            ],
            'auth.providers.users' => [
                'driver' => 'eloquent',
                'model' => BillingWriteUser::class,
            ],
            // The same harness shim the read tests carry: the route file puts
            // every billing endpoint behind `auth:sanctum` and Sanctum's token
            // driver is not registered in a Testbench skeleton, so the guard NAME
            // points at the session driver. The middleware string stays under
            // test; Sanctum's own token resolution is not this step's subject.
            'auth.guards.sanctum' => [
                'driver' => 'session',
                'provider' => 'users',
            ],
        ]);

        $schema = app('db.schema');

        $schema->create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->uuid('current_team_id')->nullable();
            $table->string('stripe_id')->nullable();
            $this->addProvenanceColumns($table);
            $table->timestamps();
        });

        $schema->create('teams', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('name');
            $table->boolean('personal_team')->default(false);
            $table->string('stripe_id')->nullable();
            $this->addProvenanceColumns($table);
            $table->timestamps();
        });

        $schema->create('team_user', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->uuid('user_id');
            $table->string('role')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        BillingWriteRail::reset();
        MagicStarter::reset();

        parent::tearDown();
    }

    /**
     * A tier in the published ranking is sold; one outside it is refused.
     *
     * The pair is the test. The accepting limb alone passes against a rule that
     * accepts any string, which is precisely the rule this step replaced, and the
     * refusing limb alone passes against a rule that accepts nothing at all.
     */
    public function test_a_published_tier_is_sold_and_a_tier_outside_the_ranking_is_refused(): void
    {
        $this->bootBillingRoutes('user');

        $user = $this->createUser('catalogue@example.test');

        $this->buy($user, 'pro')
            ->assertOk()
            ->assertJsonPath('checkout_url', 'https://checkout.stripe.test/session')
            ->assertJsonPath('session_id', 'cs_test_write');

        // The price the rail was asked for is the one the adopter's map says
        // sells `pro`, which is the half a test asserting only the status code
        // would miss: a checkout against the wrong price still answers 200.
        $this->assertSame(['price_pro' => 1], BillingWriteRail::$checkoutItems);

        // And it is a SUBSCRIPTION session under the name the other two writes
        // act on. `swap` and `cancel` both reach for `subscription('default')`,
        // so a checkout opened under any other name sells a subscription the
        // customer can then neither move nor cancel.
        $this->assertSame('default', BillingWriteRail::$checkoutSubscriptionType);

        $this->buy($user, 'enterprise')
            ->assertStatus(422)
            ->assertJsonValidationErrors('plan');
    }

    /**
     * The cycle decides which of a tier's prices is charged, and a cycle the
     * adopter does not sell is refused rather than substituted.
     *
     * THE PAIR IS THE TEST, and neither limb means anything alone. Two prices for
     * one tier makes "the tier is sellable" true for both requests, so an
     * implementation that ignored the cycle, or that fell back to whichever price
     * it found first, would pass a single-limb assertion and charge the customer
     * the other figure. That is not hypothetical: it is what shipped, and a
     * screen offering an annual discount was billing the monthly rate.
     *
     * The refusing limb carries the same weight. An adopter selling `business`
     * annually only has to REFUSE a monthly checkout, because the alternative is
     * a customer who asked for one price being charged another.
     */
    public function test_each_cycle_reaches_its_own_price_and_an_unsold_cycle_is_refused(): void
    {
        config([
            'magic-starter.billing.prices' => [
                'price_pro_monthly' => ['tier' => 'pro', 'cycle' => 'monthly'],
                'price_pro_annual' => ['tier' => 'pro', 'cycle' => 'annual'],
                'price_business_annual' => ['tier' => 'business', 'cycle' => 'annual'],
            ],
        ]);

        $this->bootBillingRoutes('user');

        $user = $this->createUser('cycles@example.test');

        $this->buy($user, 'pro', StripeSubscriptionState::CYCLE_MONTHLY)->assertOk();
        $this->assertSame(['price_pro_monthly' => 1], BillingWriteRail::$checkoutItems);

        $this->buy($user, 'pro', StripeSubscriptionState::CYCLE_ANNUAL)->assertOk();
        $this->assertSame(['price_pro_annual' => 1], BillingWriteRail::$checkoutItems);

        // Sold annually only, so the monthly request is refused and no session
        // is opened against the annual price behind it.
        BillingWriteRail::$checkoutItems = null;

        $this->buy($user, 'business', StripeSubscriptionState::CYCLE_MONTHLY)
            ->assertStatus(422);

        $this->assertNull(
            BillingWriteRail::$checkoutItems,
            'A cycle the adopter does not sell must open no session at all.',
        );

        // The cycle is a closed vocabulary, so a word outside it never reaches
        // the price lookup.
        $this->write($user, '/billing/checkout', [
            'plan' => 'pro',
            'cycle' => 'quarterly',
            'success_url' => self::SUCCESS_URL,
            'cancel_url' => self::CANCEL_URL,
        ])->assertStatus(422)->assertJsonValidationErrors('cycle');

        // An ABSENT cycle is refused too rather than defaulted, which is what
        // stops a client from buying whichever price the adopter listed first.
        $this->write($user, '/billing/checkout', [
            'plan' => 'pro',
            'success_url' => self::SUCCESS_URL,
            'cancel_url' => self::CANCEL_URL,
        ])->assertStatus(422)->assertJsonValidationErrors('cycle');
    }

    /**
     * A swap can move the cycle without moving the tier.
     *
     * The case a tier-only swap cannot express at all: a customer on `pro`
     * monthly who takes the annual discount is not changing tier, so an endpoint
     * reading the plan alone would answer 200 and leave them on the price they
     * were trying to leave.
     */
    public function test_a_swap_can_change_the_cycle_while_the_tier_stays_put(): void
    {
        config([
            'magic-starter.billing.prices' => [
                'price_pro_monthly' => ['tier' => 'pro', 'cycle' => 'monthly'],
                'price_pro_annual' => ['tier' => 'pro', 'cycle' => 'annual'],
            ],
        ]);

        $this->bootBillingRoutes('user');

        $user = $this->createUser('cycle-swap@example.test');
        BillingWriteRail::$subscription = new BillingWriteSubscription;

        $this->write($user, '/billing/swap', [
            'plan' => 'pro',
            'cycle' => StripeSubscriptionState::CYCLE_ANNUAL,
        ])->assertOk();

        $this->assertSame('price_pro_annual', BillingWriteSubscription::$swappedTo);
    }

    /**
     * An adopter who has published nothing sells nothing, and the sentence names
     * the config that is missing.
     *
     * This is the step's QA, both limbs of it. The refusal is asserted for the
     * SAME tier id the second limb then sells, so the difference between them is
     * the published ranking and nothing else. And the message is asserted to name
     * BOTH keys, because either one is a valid answer to it: an adopter reading a
     * sentence that named only the other would go looking in the wrong file.
     */
    public function test_an_unpublished_catalogue_refuses_every_checkout_and_names_both_config_keys(): void
    {
        config([
            'magic-starter.billing.tier_order' => [],
            'magic-starter.billing.plans' => [],
            'magic-starter.billing.prices' => ['price_starter' => 'starter'],
        ]);

        $this->bootBillingRoutes('user');

        $user = $this->createUser('unpublished@example.test');

        $refused = $this->buy($user, 'starter');

        $refused->assertStatus(422)->assertJsonValidationErrors('plan');

        $message = $refused->json('message');

        $this->assertSame($this->shippedLine('en', 'no_published_catalogue'), $message);
        $this->assertStringContainsString('magic-starter.billing.plans', $message);
        $this->assertStringContainsString('magic-starter.billing.tier_order', $message);

        // The disarming limb: publish a two-entry ranking and the FIRST of them
        // sells. Without it this test passes against an endpoint that refuses
        // every checkout there has ever been.
        config(['magic-starter.billing.tier_order' => ['starter', 'scale']]);

        $this->buy($user, 'starter')->assertOk();

        $this->assertSame(['price_starter' => 1], BillingWriteRail::$checkoutItems);
    }

    /**
     * An adopter who published only the CATALOGUE still sells its tiers.
     *
     * The ranking falls back to the catalogue's entry ids when no explicit order
     * is published, so such an adopter has a valid list. An endpoint validating
     * against the raw `billing.tier_order` key instead would refuse every tier
     * they sell while their own billing screen rendered all of them, and nothing
     * in the populated fixture above can see that: it publishes both lists.
     */
    public function test_a_catalogue_published_without_a_ranking_still_sells_its_tiers(): void
    {
        config([
            'magic-starter.billing.tier_order' => [],
            'magic-starter.billing.plans' => [
                ['id' => 'starter', 'name' => 'Starter'],
                ['id' => 'scale', 'name' => 'Scale'],
            ],
            'magic-starter.billing.prices' => [
                'price_starter' => 'starter',
                'price_scale' => 'scale',
            ],
        ]);

        $this->bootBillingRoutes('user');

        $user = $this->createUser('catalogue-only@example.test');

        $this->buy($user, 'scale')->assertOk();
        $this->assertSame(['price_scale' => 1], BillingWriteRail::$checkoutItems);

        // Still a ranking and not a free-for-all: a tier absent from the
        // catalogue is refused exactly as one absent from an explicit list is.
        $this->buy($user, 'enterprise')
            ->assertStatus(422)
            ->assertJsonValidationErrors('plan');
    }

    /**
     * A tier the adopter sells with no Stripe price behind it is a config gap,
     * refused by its own sentence rather than checked out against nothing.
     */
    public function test_a_sellable_tier_with_no_mapped_price_is_refused_by_its_own_sentence(): void
    {
        $this->bootBillingRoutes('user');

        $user = $this->createUser('unpriced@example.test');

        $refused = $this->buy($user, 'business');

        $refused->assertStatus(422);

        // The sentence NAMES the cycle, which is the dimension that failed. An
        // adopter selling a tier one way only meets this on every checkout for
        // the other, and a message that told them to map a price they had
        // already mapped sent them to the right file looking for the wrong
        // thing. Asserted against the shipped line with the placeholder filled,
        // so dropping `:cycle` from the catalogue fails here rather than
        // shipping a sentence with a literal `:cycle` in it.
        $this->assertSame(
            str_replace(':cycle', 'monthly', $this->shippedLine('en', 'unmapped_price')),
            $refused->json('message'),
        );
        $this->assertStringContainsString('monthly', (string) $refused->json('message'));
        $this->assertNull(BillingWriteRail::$checkoutItems, 'No session may be opened against an unmapped tier.');

        // The disarming limb: the neighbouring tier IS mapped and sells, so the
        // refusal above is the missing price and not a broken endpoint.
        $this->buy($user, 'pro')->assertOk();
    }

    /**
     * An empty price map is refused per tier and never resolved to an empty
     * price id.
     *
     * The map's reader strips empty keys, and this is the endpoint half of that
     * guard: an adopter assembling the map from unset environment variables
     * writes `'' => 'pro'`, and a reverse lookup that honoured it would open a
     * Stripe session against no price at all.
     */
    public function test_an_empty_price_id_never_sells_a_tier(): void
    {
        config(['magic-starter.billing.prices' => ['' => 'pro']]);

        $this->bootBillingRoutes('user');

        $this->buy($this->createUser('empty-price@example.test'), 'pro')
            ->assertStatus(422);

        $this->assertNull(BillingWriteRail::$checkoutItems);
    }

    /**
     * A swap moves the subscription onto the price that sells the requested tier.
     *
     * The wire afterwards still carries the LOCAL entitlement, which is correct
     * and worth pinning: the provenance columns are the webhook's to write, and
     * an endpoint that patched them here would make the billing screen disagree
     * with the rail permanently whenever the event never arrived.
     */
    public function test_a_swap_moves_the_subscription_onto_the_requested_tiers_price(): void
    {
        $this->bootBillingRoutes('user');

        $user = $this->createUser('swap@example.test', [
            'plan' => 'free',
            'plan_status' => 'active',
            'plan_provider' => 'stripe',
            'stripe_id' => self::STRIPE_ID,
        ]);

        BillingWriteRail::$hasStripeId = true;
        BillingWriteRail::$subscription = new BillingWriteSubscription;

        $this->write($user, '/billing/swap', ['plan' => 'pro', 'cycle' => 'monthly'])
            ->assertOk()
            ->assertJsonPath('data.plan', 'free');

        $this->assertSame('price_pro', BillingWriteSubscription::$swappedTo);

        // A tier outside the ranking is refused here too, and before the rail is
        // touched: the swap must not be reachable by a plan id a checkout could
        // not have named.
        BillingWriteSubscription::reset();

        $this->write($user, '/billing/swap', ['plan' => 'enterprise', 'cycle' => 'monthly'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('plan');

        $this->assertNull(BillingWriteSubscription::$swappedTo);
    }

    /**
     * A cancel ends the subscription at the period the customer has paid for.
     */
    public function test_a_cancel_ends_the_default_subscription(): void
    {
        $this->bootBillingRoutes('user');

        $user = $this->createUser('cancel@example.test', [
            'plan' => 'pro',
            'plan_status' => 'active',
            'plan_provider' => 'stripe',
            'stripe_id' => self::STRIPE_ID,
        ]);

        BillingWriteRail::$hasStripeId = true;
        BillingWriteRail::$subscription = new BillingWriteSubscription;

        $this->write($user, '/billing/cancel')
            ->assertOk()
            ->assertJsonPath('data.plan', 'pro');

        $this->assertTrue(BillingWriteSubscription::$cancelled);
    }

    /**
     * With no subscription on this rail, a swap and a cancel are 404 with the
     * shipped sentence.
     *
     * A different fact from the store 409 rather than a milder version of it, and
     * reachable only because the store guard has already run.
     */
    public function test_a_subject_with_no_subscription_cannot_swap_or_cancel(): void
    {
        $this->bootBillingRoutes('user');

        $user = $this->createUser('nothing@example.test', [
            'plan_status' => 'none',
            'plan_provider' => 'none',
        ]);

        BillingWriteRail::$subscription = null;

        foreach ([['/billing/swap', ['plan' => 'pro', 'cycle' => 'monthly']], ['/billing/cancel', []]] as [$path, $payload]) {
            $response = $this->write($user, $path, $payload);

            $response->assertNotFound();
            $this->assertSame($this->shippedLine('en', 'no_subscription'), $response->json('message'));
        }
    }

    /**
     * A store-sold subscription refuses all three writes, with the reason, the
     * rail and the caller's own locale.
     *
     * Asserted against the SHIPPED catalogue value rather than a literal here,
     * because a hardcoded expectation passes whether or not the sentence was ever
     * translated.
     */
    public function test_a_store_owned_subscription_refuses_all_three_writes(): void
    {
        $this->bootBillingRoutes('user');

        $user = $this->createUser('store-write@example.test', [
            'plan' => 'pro',
            'plan_status' => 'active',
            'plan_provider' => 'app_store',
            'stripe_id' => self::STRIPE_ID,
        ]);

        BillingWriteRail::$hasStripeId = true;
        BillingWriteRail::$subscription = new BillingWriteSubscription;

        app()->setLocale('tr');

        foreach ([
            ['/billing/checkout', $this->checkoutPayload('pro')],
            ['/billing/swap', ['plan' => 'pro', 'cycle' => 'monthly']],
            ['/billing/cancel', []],
        ] as [$path, $payload]) {
            $this->write($user, $path, $payload)
                ->assertStatus(409)
                ->assertJsonPath('message', $this->shippedLine('tr', 'managed_by_store'))
                ->assertJsonPath('billing.reason', BillingController::REASON_MANAGED_BY_STORE)
                ->assertJsonPath('billing.provider', 'app_store');
        }

        // Nothing reached the rail: the refusal is at the point of sale, not
        // after it.
        $this->assertNull(BillingWriteRail::$checkoutItems);
        $this->assertNull(BillingWriteSubscription::$swappedTo);
        $this->assertFalse(BillingWriteSubscription::$cancelled);

        // The other locale answers with its own sentence, from the same key.
        app()->setLocale('en');

        $this->write($user, '/billing/cancel')
            ->assertStatus(409)
            ->assertJsonPath('message', $this->shippedLine('en', 'managed_by_store'));
    }

    /**
     * A store-billed subject with NO card-rail subscription is still the store
     * refusal, never "there is nothing to cancel".
     *
     * This is the ORDER of the two guards, and it is the realistic shape rather
     * than an edge case: somebody who only ever subscribed in the App Store has
     * no Stripe subscription at all, so the card-rail lookup finds nothing. If
     * the 404 ran first, the answer would be that they have nothing to cancel,
     * to a customer the store is charging every month, and the true instruction
     * ("cancel it where you bought it") would never reach them.
     *
     * The neighbouring test cannot catch this: its fixture gives the store-billed
     * subject a card-rail subscription as well, so the lookup succeeds and the
     * guard is reached whichever order the two run in. Measured, with the guard
     * and the lookup swapped in `cancel()`, that test and the other twelve all
     * stay green.
     */
    public function test_a_store_billed_subject_without_a_card_subscription_is_still_the_store_refusal(): void
    {
        $this->bootBillingRoutes('user');

        $user = $this->createUser('store-only@example.test', [
            'plan' => 'pro',
            'plan_status' => 'active',
            'plan_provider' => 'play_store',
        ]);

        // The whole point: no Stripe customer and no card-rail subscription.
        BillingWriteRail::$hasStripeId = false;
        BillingWriteRail::$subscription = null;

        $this->write($user, '/billing/cancel')
            ->assertStatus(409)
            ->assertJsonPath('billing.reason', BillingController::REASON_MANAGED_BY_STORE)
            ->assertJsonPath('billing.provider', 'play_store');

        $this->write($user, '/billing/swap', ['plan' => 'business'])
            ->assertStatus(409)
            ->assertJsonPath('billing.reason', BillingController::REASON_MANAGED_BY_STORE);
    }

    /**
     * A billable whose application never applied Cashier's trait is refused
     * rather than fataling.
     *
     * An application selling only through the two app stores has no reason to
     * have applied the trait, and a direct `newSubscription()` on such a model
     * is a fatal `Error` on the billing screen.
     *
     * The CONFIGURED team model is swapped, not just the row's class. Creating a
     * trait-less row and leaving the config pointed at the trait-carrying fixture
     * proves nothing: `currentTeam` resolves through
     * {@see MagicStarter::teamModel()}, so the controller would hydrate the same
     * row back into the model that HAS the trait and the endpoint would answer
     * exactly as it does for everybody else.
     */
    public function test_a_billable_with_no_cashier_trait_cannot_check_out(): void
    {
        config(['magic-starter.models.team' => CashierlessWriteTeam::class]);

        $this->bootBillingRoutes('team');

        $owner = $this->createUser('no-cashier-write@example.test');
        $team = CashierlessWriteTeam::query()->create([
            'user_id' => $owner->getKey(),
            'name' => 'Store Only',
            'plan_status' => 'none',
            'plan_provider' => 'none',
        ]);
        $team->users()->attach($owner->getKey(), ['role' => 'owner']);
        $owner->forceFill(['current_team_id' => $team->getKey()])->save();

        // The method the guard probes, which is also the one the checkout calls.
        $this->assertFalse(method_exists($team, 'newSubscription'));
        $this->assertSame(CashierlessWriteTeam::class, MagicStarter::billableModel());

        $this->buy($owner, 'pro')
            ->assertStatus(409)
            ->assertJsonPath('billing.reason', BillingController::REASON_NO_BILLING_ACCOUNT)
            ->assertJsonPath('billing.provider', 'none');
    }

    /**
     * Only the owner may spend the subject's money.
     *
     * The 403 lands before anything about the subscription is read, so a refused
     * caller learns nothing about whether one exists.
     */
    public function test_a_member_who_does_not_own_the_billable_cannot_write(): void
    {
        $this->bootBillingRoutes('team');

        $owner = $this->createUser('write-owner@example.test');
        $member = $this->createUser('write-member@example.test');
        $team = $this->createTeam($owner, [
            'plan' => 'pro',
            'plan_status' => 'active',
            'plan_provider' => 'stripe',
            'stripe_id' => self::STRIPE_ID,
        ]);
        $team->users()->attach($member->getKey(), ['role' => 'admin']);
        $member->forceFill(['current_team_id' => $team->getKey()])->save();
        $owner->forceFill(['current_team_id' => $team->getKey()])->save();

        BillingWriteRail::$hasStripeId = true;
        BillingWriteRail::$subscription = new BillingWriteSubscription;

        foreach ([
            ['/billing/checkout', $this->checkoutPayload('pro')],
            ['/billing/swap', ['plan' => 'pro', 'cycle' => 'monthly']],
            ['/billing/cancel', []],
        ] as [$path, $payload]) {
            $this->write($member, $path, $payload)->assertForbidden();
        }

        // The disarming limb: the owner of the same team is served, so the 403s
        // above are about this caller and not about the team or the routes.
        $this->write($owner, '/billing/cancel')->assertOk();
    }

    /**
     * No write endpoint answers an unauthenticated caller.
     */
    public function test_no_write_endpoint_answers_an_unauthenticated_caller(): void
    {
        $this->bootBillingRoutes('user');

        foreach ([
            ['/billing/checkout', $this->checkoutPayload('pro')],
            ['/billing/swap', ['plan' => 'pro', 'cycle' => 'monthly']],
            ['/billing/cancel', []],
        ] as [$path, $payload]) {
            $this->postJson($path, $payload)->assertUnauthorized();
        }
    }

    /**
     * Nothing write-shaped is registered while the billing feature is off.
     */
    public function test_no_write_route_exists_while_the_feature_is_off(): void
    {
        config([
            'magic-starter.features' => [Features::teams()],
            'magic-starter.billing.billable' => 'user',
        ]);

        $this->app['router']->setRoutes(new RouteCollection);
        (new MagicStarterServiceProvider($this->app))->boot();

        $user = $this->createUser('write-feature-off@example.test');

        $this->write($user, '/billing/checkout', $this->checkoutPayload('pro'))->assertNotFound();
        $this->write($user, '/billing/swap', ['plan' => 'pro', 'cycle' => 'monthly'])->assertNotFound();
        $this->write($user, '/billing/cancel')->assertNotFound();

        // The disarming limb: the provider booted and registered its other
        // routes, so the 404s above are the billing gate and not an empty router.
        $this->actingAs($user->fresh(), 'sanctum')->getJson('/teams')->assertOk();
    }

    /**
     * Open a checkout for one tier, as the given caller.
     */
    private function buy(
        Model $user,
        string $plan,
        string $cycle = StripeSubscriptionState::CYCLE_MONTHLY,
    ): TestResponse {
        return $this->write($user, '/billing/checkout', $this->checkoutPayload($plan, $cycle));
    }

    /**
     * Drive one write endpoint as the given caller.
     *
     * The acting instance is re-read rather than reused, because a real request
     * resolves its own model and this harness would otherwise carry a relation
     * loaded by an earlier call in the same test.
     *
     * @param  array<string, mixed>  $payload
     */
    private function write(Model $user, string $path, array $payload = []): TestResponse
    {
        return $this->actingAs($user->fresh(), 'sanctum')->postJson($path, $payload);
    }

    /**
     * A well-formed checkout body for one tier.
     *
     * @return array<string, string>
     */
    private function checkoutPayload(string $plan, string $cycle = StripeSubscriptionState::CYCLE_MONTHLY): array
    {
        return [
            'plan' => $plan,
            'cycle' => $cycle,
            'success_url' => self::SUCCESS_URL,
            'cancel_url' => self::CANCEL_URL,
        ];
    }

    /**
     * Re-register the package's routes against a billable subject.
     *
     * The gate is thrown away with them: it is a container singleton resolved
     * during the application's own boot, so without this the `manageBilling`
     * ability would be whatever the default feature set left behind and the
     * ownership refusal could never fire.
     */
    private function bootBillingRoutes(string $billable): void
    {
        config([
            'magic-starter.features' => [Features::teams(), Features::billing()],
            'magic-starter.billing.billable' => $billable,
        ]);

        $this->app['router']->setRoutes(new RouteCollection);
        $this->app->forgetInstance(GateContract::class);
        Gate::clearResolvedInstance(GateContract::class);

        (new MagicStarterServiceProvider($this->app))->boot();
    }

    /**
     * Read a refusal sentence out of the SHIPPED catalogue for a locale.
     *
     * The file is reached through `__DIR__` and never through `base_path()`,
     * which under Testbench resolves into the skeleton application rather than
     * into this package.
     */
    private function shippedLine(string $locale, string $key): string
    {
        $lines = require __DIR__ . '/../../../lang/' . $locale . '/billing.php';

        $this->assertIsArray($lines);
        $this->assertArrayHasKey($key, $lines['refusals']);

        return $lines['refusals'][$key];
    }

    /**
     * The ten provenance columns, as a consumer's migration would add them.
     *
     * Deliberately UNCAST on the fixture models below: the package ships these
     * columns and not the model that owns them, so a fixture that decoded them
     * would certify a decode no adopter is obliged to perform.
     */
    private function addProvenanceColumns(Blueprint $table): void
    {
        $table->string('plan')->nullable();
        $table->string('plan_status')->nullable();
        $table->string('plan_provider')->nullable();
        $table->string('plan_provider_status')->nullable();
        $table->string('plan_product_id')->nullable();
        $table->string('plan_manage_url')->nullable();
        $table->string('plan_renews')->nullable();
        $table->string('plan_current_period_end')->nullable();
        $table->string('plan_grace_period_ends_at')->nullable();
        $table->string('plan_source_event_at')->nullable();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createUser(string $email, array $attributes = []): BillingWriteUser
    {
        return BillingWriteUser::query()->create(array_merge([
            'name' => 'Billing Writer',
            'email' => $email,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createTeam(BillingWriteUser $owner, array $attributes = []): BillingWriteTeam
    {
        $team = BillingWriteTeam::query()->create(array_merge([
            'user_id' => $owner->getKey(),
            'name' => 'Billed Team',
            'personal_team' => false,
        ], $attributes));

        $team->users()->attach($owner->getKey(), ['role' => 'owner']);

        return $team;
    }
}

/**
 * The rail's answers and what it was asked, set and read per test.
 *
 * A static registry rather than a mock, because the subject under test is what
 * the controller sends the rail, and both fixture billables below have to answer
 * identically without duplicating the doubles.
 */
final class BillingWriteRail
{
    public static bool $hasStripeId = false;

    public static ?Model $subscription = null;

    /**
     * The items a checkout was opened with, or null when none was.
     *
     * @var array<string, int>|null
     */
    public static ?array $checkoutItems = null;

    /**
     * The subscription name a checkout was opened under, or null when none was.
     *
     * It has to match the one `swap` and `cancel` operate on, or a customer
     * checks out into a subscription those two can never reach.
     */
    public static ?string $checkoutSubscriptionType = null;

    public static function reset(): void
    {
        self::$hasStripeId = false;
        self::$subscription = null;
        self::$checkoutItems = null;
        self::$checkoutSubscriptionType = null;

        BillingWriteSubscription::reset();
    }
}

/**
 * The Cashier surface a consuming application's billable model would carry.
 *
 * Applied to both fixture billables and deliberately NOT to
 * {@see CashierlessWriteTeam}, which is what makes the absent-trait path
 * drivable.
 */
trait BillingWriteBillable
{
    public function hasStripeId(): bool
    {
        return BillingWriteRail::$hasStripeId;
    }

    public function subscription(string $type = 'default'): ?Model
    {
        return BillingWriteRail::$subscription;
    }

    /**
     * The ONE-OFF charge session, which is what Cashier's `Billable::checkout()`
     * opens: it routes to `Checkout::create`, whose mode defaults to
     * `Session::MODE_PAYMENT`.
     *
     * It refuses here for the reason Stripe refuses there. Every price a
     * subscription catalogue sells is recurring, and Stripe rejects a recurring
     * price in payment mode outright, so this call can never open a session for
     * anything this package sells. A fixture that quietly recorded the items
     * instead certified exactly that call as correct, and no assertion in this
     * file could go red, because Stripe was never asked whether it was valid.
     *
     * @param  array<string, int>|string  $items
     * @param  array<string, mixed>  $sessionOptions
     * @param  array<string, mixed>  $customerOptions
     */
    public function checkout($items, array $sessionOptions = [], array $customerOptions = []): Checkout
    {
        throw new LogicException(
            'You specified `payment` mode but passed a recurring price. '
            . 'A subscription checkout goes through newSubscription()->checkout().',
        );
    }

    /**
     * The SUBSCRIPTION checkout builder, which is the one that asks Stripe for
     * `mode: subscription`.
     *
     * @param  array<int, string>|string  $prices
     */
    public function newSubscription(string $type, $prices = []): BillingWriteSubscriptionBuilder
    {
        return new BillingWriteSubscriptionBuilder($this, $type, $prices);
    }
}

/**
 * Stands in for Cashier's `SubscriptionBuilder`, recording what a checkout was
 * opened against so the assertions can read it back.
 *
 * It normalises `$prices` into the `[price => quantity]` shape the rail's
 * recorder already used, so the price a session was opened against is asserted
 * the same way whichever call shape produced it.
 */
final class BillingWriteSubscriptionBuilder
{
    /**
     * @param  array<int, string>|string  $prices
     */
    public function __construct(
        protected object $billable,
        protected string $type,
        protected array|string $prices,
    ) {}

    /**
     * @param  array<string, mixed>  $sessionOptions
     * @param  array<string, mixed>  $customerOptions
     */
    public function checkout(array $sessionOptions = [], array $customerOptions = []): Checkout
    {
        $items = [];

        foreach ((array) $this->prices as $price) {
            $items[(string) $price] = 1;
        }

        BillingWriteRail::$checkoutItems = $items;
        BillingWriteRail::$checkoutSubscriptionType = $this->type;

        return new Checkout($this->billable, StripeCheckoutSession::constructFrom([
            'id' => 'cs_test_write',
            'url' => 'https://checkout.stripe.test/session',
            'success_url' => $sessionOptions['success_url'] ?? null,
            'cancel_url' => $sessionOptions['cancel_url'] ?? null,
        ]));
    }
}

class BillingWriteUser extends Model implements AuthenticatableContract
{
    use AuthenticatableTrait;
    use Authorizable;
    use BillingWriteBillable;
    use ConditionallyUsesUuids;
    use \FlutterSdk\MagicStarter\Traits\HasTeams;

    protected $table = 'users';

    protected $guarded = [];
}

class BillingWriteTeam extends Team
{
    use BillingWriteBillable;

    protected $table = 'teams';

    /**
     * Emptied because the package's own Team declares a four-key `$fillable`, and
     * a non-empty one wins over `$guarded`: without this every provenance column
     * a fixture sets is silently dropped.
     */
    protected $fillable = [];

    protected $guarded = [];
}

/**
 * A billable whose application never applied Cashier's trait.
 */
class CashierlessWriteTeam extends Team
{
    protected $table = 'teams';

    protected $fillable = [];

    protected $guarded = [];
}

/**
 * A subscription row that records the verb it was asked for.
 */
class BillingWriteSubscription extends Model
{
    public static ?string $swappedTo = null;

    public static bool $cancelled = false;

    protected $table = 'subscriptions';

    protected $guarded = [];

    public static function reset(): void
    {
        self::$swappedTo = null;
        self::$cancelled = false;
    }

    public function swap(string $price): self
    {
        self::$swappedTo = $price;

        return $this;
    }

    public function cancel(): self
    {
        self::$cancelled = true;

        return $this;
    }
}
