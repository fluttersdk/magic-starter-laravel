<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Actions\SubscriptionGuardedDeleteTeam;
use FlutterSdk\MagicStarter\Contracts\ReportsUsage;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Http\Controllers\BillingController;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Models\BillingTrial;
use FlutterSdk\MagicStarter\Models\Team;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Cashier\Invoice;
use Laravel\Cashier\PaymentMethod;
use RuntimeException;
use Stripe\Exception\ApiConnectionException;
use Stripe\Invoice as StripeInvoice;
use Stripe\PaymentMethod as StripePaymentMethod;
use Stripe\Subscription as StripeSubscription;

/**
 * The seven billing READ endpoints, driven through the routes the package
 * actually registers.
 *
 * Driven through the real route file rather than through hand-registered routes,
 * because two of this step's claims are properties OF THE REGISTRATION and are
 * invisible to a test that calls the controller directly: that `billing/usage`
 * does not exist until a consumer binds {@see ReportsUsage}, and that all seven
 * sit inside the authenticated group.
 *
 * THE TEST THAT CARRIES THIS FILE is the paired `paymentMethod` one. Before this
 * step the endpoint caught every `Throwable` and answered 200 with the same five
 * nulls whether Stripe had been unreachable or had answered "no card on file", so
 * the two bodies were byte-identical and a test exercising either one alone
 * passed against the defect. It is asserted here as a DIFFERENCE between two
 * bodies produced by one billable, which is the only shape that can fail for the
 * right reason.
 *
 * The rail itself is a fixture ({@see BillingTestRail}) and never a live Stripe
 * call. That is not only speed: the billable model belongs to the consuming
 * application, so what these endpoints really depend on is that a model carries
 * the Cashier methods at all, and a fixture is the honest way to drive both the
 * present and the absent case.
 */
class BillingControllerTest extends TestCase
{
    /**
     * The instant the renewal-date assertions are written against.
     */
    private const TRIAL_END = '2026-10-01 09:00:00';

    private const TRIAL_END_ISO = '2026-10-01T09:00:00+00:00';

    /**
     * The Stripe customer id both billable fixtures carry.
     */
    private const STRIPE_ID = 'cus_billing_test';

    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::reset();
        BillingTestRail::reset();

        config([
            'database.default' => 'testing',
            'database.connections.testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'magic-starter.models.user' => BillingTestUser::class,
            'magic-starter.models.team' => BillingTestTeam::class,
            'magic-starter.models.membership' => \FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeamUser::class,
            'magic-starter.route_prefix' => '',
            // The adopter's ranking, cheapest first. `free` being index 0 is
            // what makes `pro` a PAID tier to the shared floor reader.
            'magic-starter.billing.tier_order' => ['free', 'pro', 'business'],
            // The adopter's tier definitions, served verbatim by the plans
            // endpoint. `limits` is deliberately here: it is product knowledge the
            // package names nowhere, so a test that did not carry one could not
            // tell "passed through untouched" from "happened to keep the fields
            // the package does know".
            'magic-starter.billing.tiers' => [
                'free' => [
                    'name' => 'Free',
                    'monthly' => 0,
                    'currency' => 'usd',
                    'features' => ['One of everything'],
                    'recommended' => false,
                    'limits' => ['seats' => 1],
                ],
                'pro' => [
                    'name' => 'Pro',
                    'monthly' => 1900,
                    'currency' => 'usd',
                    'features' => ['Rather more of everything'],
                    'recommended' => true,
                    'limits' => ['seats' => 10],
                ],
                'business' => [
                    'name' => 'Business',
                    'monthly' => 4900,
                    'currency' => 'usd',
                    'features' => ['All of it'],
                    'recommended' => false,
                    'limits' => ['seats' => null],
                ],
            ],
            'auth.providers.users' => [
                'driver' => 'eloquent',
                'model' => BillingTestUser::class,
            ],
            // A harness shim, said plainly: the route file puts every billing
            // endpoint behind `auth:sanctum`, and Sanctum's token driver is not
            // registered in a Testbench skeleton. Pointing the guard NAME at the
            // session driver keeps the middleware string under test (the routes
            // really are authenticated) without pulling a token stack in. What it
            // does not test is Sanctum's own token resolution, which is not this
            // step's subject.
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

        // The trial history the plans rows are decided against, from the
        // package's own migration so the columns read are the shipped ones.
        $this->artisan('migrate', [
            '--path' => __DIR__ . '/../../../database/migrations/create_billing_trials_table.php',
            '--realpath' => true,
        ]);
    }

    protected function tearDown(): void
    {
        BillingTestRail::reset();
        MagicStarter::reset();

        parent::tearDown();
    }

    /**
     * All seven answer for the owner of a team-shaped billable.
     */
    public function test_the_seven_read_endpoints_answer_under_the_team_subject(): void
    {
        $this->bindUsageReporter();
        $this->bootBillingRoutes('team');

        $owner = $this->createUser('team-reads@example.test');
        $team = $this->createTeam($owner, [
            'plan' => 'pro',
            'plan_status' => 'active',
            'plan_provider' => 'stripe',
            'stripe_id' => self::STRIPE_ID,
        ]);
        $this->setCurrentTeam($owner, $team);

        BillingTestRail::$hasStripeId = true;
        BillingTestRail::$portalUrl = 'https://billing.stripe.test/session/team';
        BillingTestRail::$subscription = $this->subscriptionWithTrial();
        BillingTestRail::$invoices = $this->invoicePage($team);
        BillingTestRail::$paymentMethod = $this->visaPaymentMethod($team);

        $this->assertSame('pro', $this->ask($owner, '/billing')->json('data.plan'));
        $this->assertSame(['free', 'pro', 'business'], array_column($this->ask($owner, '/billing/plans')->json('data'), 'id'));
        $this->assertSame(3, $this->ask($owner, '/billing/usage')->json('seats.used'));
        $this->assertSame('in_test_1', $this->ask($owner, '/billing/invoices')->json('data.0.id'));
        $this->assertSame(null, $this->ask($owner, '/billing/store-funded-team')->json('store_funded_team'));
        $this->assertSame(
            'https://billing.stripe.test/session/team',
            $this->ask($owner, '/billing/portal')->json('portal_url'),
        );

        $card = $this->ask($owner, '/billing/payment-method');
        $this->assertSame(true, $card->json('available'));
        $this->assertSame(self::TRIAL_END_ISO, $card->json('renewal_date'));
        $this->assertSame('visa', $card->json('brand'));
    }

    /**
     * A card a hosted CHECKOUT left on the subscription is reported, even though
     * the customer carries no default payment method of its own.
     *
     * This is the state every web purchase lands in, and it was reported as "no
     * card on file" to the customer who had just paid with one. Stripe Checkout
     * attaches the payment method to the SUBSCRIPTION and leaves the customer's
     * `invoice_settings.default_payment_method` null; Cashier's
     * `defaultPaymentMethod()` reads only the customer, so it answered null for a
     * card that was genuinely on file and about to be charged again.
     *
     * The PAIR is the test. The first limb is the checkout state (customer null,
     * subscription set) and the second is the state a portal update leaves
     * (customer set), because a reader that consulted only the subscription would
     * pass the first limb and report a stale card in the second: after a portal
     * change the customer's default is the new card while the subscription may
     * still name the old one, so the customer has to keep precedence.
     */
    public function test_a_card_left_on_the_subscription_by_checkout_is_reported(): void
    {
        $this->bootBillingRoutes('team');

        $owner = $this->createUser('checkout-card@example.test');
        $team = $this->createTeam($owner, [
            'plan' => 'pro',
            'plan_status' => 'active',
            'plan_provider' => 'stripe',
            'stripe_id' => self::STRIPE_ID,
        ]);
        $this->setCurrentTeam($owner, $team);

        BillingTestRail::$hasStripeId = true;
        BillingTestRail::$subscription = new BillingTestSubscription;

        // Limb one: what a hosted checkout leaves behind.
        BillingTestRail::$paymentMethod = null;
        BillingTestRail::$subscriptionPaymentMethod = StripePaymentMethod::constructFrom([
            'id' => 'pm_test_checkout',
            'card' => [
                'brand' => 'visa',
                'last4' => '4242',
                'exp_month' => 12,
                'exp_year' => 2034,
            ],
        ]);

        $card = $this->ask($owner, '/billing/payment-method');
        $this->assertSame(true, $card->json('available'));
        $this->assertSame('visa', $card->json('brand'));
        $this->assertSame('4242', $card->json('last4'));

        // Limb two: the customer's own default wins when it exists, so a portal
        // update is not shadowed by whatever the subscription still names.
        BillingTestRail::$paymentMethod = $this->visaPaymentMethod($team);

        $this->assertSame('4242', $this->ask($owner, '/billing/payment-method')->json('last4'));
        $this->assertSame(2030, $this->ask($owner, '/billing/payment-method')->json('exp_year'));
    }

    /**
     * The same seven answer when the billable subject is the caller themselves.
     *
     * The disarming limb is the FIRST assertion: this configuration is the one in
     * which there is no team in the resolution path at all, so an implementation
     * that reached for `currentTeam` unconditionally would 404 every endpoint
     * here while the team-subject test above stayed green.
     */
    public function test_the_seven_read_endpoints_answer_under_the_user_subject(): void
    {
        $this->bindUsageReporter();
        $this->bootBillingRoutes('user');

        $this->assertSame(MagicStarter::userModel(), MagicStarter::billableModel());

        $user = $this->createUser('user-reads@example.test', [
            'plan' => 'business',
            'plan_status' => 'active',
            'plan_provider' => 'stripe',
            'stripe_id' => self::STRIPE_ID,
        ]);

        BillingTestRail::$hasStripeId = true;
        BillingTestRail::$portalUrl = 'https://billing.stripe.test/session/user';
        BillingTestRail::$subscription = $this->subscriptionWithTrial();
        BillingTestRail::$invoices = $this->invoicePage($user);

        $this->assertSame('business', $this->ask($user, '/billing')->json('data.plan'));
        $this->assertSame(['free', 'pro', 'business'], array_column($this->ask($user, '/billing/plans')->json('data'), 'id'));
        $this->assertSame(10, $this->ask($user, '/billing/usage')->json('seats.limit'));
        $this->assertSame('in_test_1', $this->ask($user, '/billing/invoices')->json('data.0.id'));
        $this->assertSame(null, $this->ask($user, '/billing/store-funded-team')->json('store_funded_team'));
        $this->assertSame(
            'https://billing.stripe.test/session/user',
            $this->ask($user, '/billing/portal')->json('portal_url'),
        );
        $this->assertSame(true, $this->ask($user, '/billing/payment-method')->json('available'));
    }

    /**
     * A Stripe outage and an absent card are two DIFFERENT bodies.
     *
     * The step's QA, driven twice against one billable so the difference is the
     * assertion rather than a shape read off one path. Under the code this step
     * replaced both bodies were `renewal_date` plus four nulls, byte for byte, so
     * either half alone passes against the defect and only their inequality can
     * fail for the right reason.
     */
    public function test_a_stripe_outage_and_an_absent_card_are_distinguishable_bodies(): void
    {
        $this->bootBillingRoutes('user');

        $user = $this->createUser('card@example.test', [
            'plan' => 'pro',
            'plan_status' => 'active',
            'plan_provider' => 'stripe',
            'stripe_id' => self::STRIPE_ID,
        ]);

        BillingTestRail::$hasStripeId = true;

        // 1. The rail answers and has nothing to show.
        BillingTestRail::$paymentMethod = null;
        $absent = $this->ask($user, '/billing/payment-method');
        $absent->assertOk();

        // 2. The rail cannot be asked at all.
        BillingTestRail::$failure = new ApiConnectionException('Could not reach Stripe.');
        $outage = $this->ask($user, '/billing/payment-method');
        $outage->assertOk();

        // 3. The claim: the client can tell them apart.
        $this->assertNotSame($absent->json(), $outage->json());
        $this->assertSame(true, $absent->json('available'));
        $this->assertSame(false, $outage->json('available'));

        // And the half that keeps the addition ADDITIVE: the five original
        // fields keep their names and their nullability on both paths, so no
        // existing client branch stops resolving.
        foreach (['renewal_date', 'brand', 'last4', 'exp_month', 'exp_year'] as $field) {
            $this->assertArrayHasKey($field, $absent->json());
            $this->assertArrayHasKey($field, $outage->json());
            $this->assertNull($absent->json($field));
            $this->assertNull($outage->json($field));
        }
    }

    /**
     * A failure that is not the rail's propagates instead of being absorbed.
     *
     * This is what stops the narrow catch being widened back to `Throwable`. With
     * a blanket catch this endpoint answers 200 and `available: false`, which
     * reports an outage that never happened and hides a real bug behind it.
     */
    public function test_a_failure_that_is_not_the_rails_is_not_reported_as_an_outage(): void
    {
        $this->bootBillingRoutes('user');
        $this->withoutExceptionHandling();

        $user = $this->createUser('card-bug@example.test', [
            'plan' => 'pro',
            'plan_status' => 'active',
            'plan_provider' => 'stripe',
            'stripe_id' => self::STRIPE_ID,
        ]);

        BillingTestRail::$hasStripeId = true;
        BillingTestRail::$failure = new RuntimeException('A bug that has nothing to do with Stripe.');

        $this->expectException(RuntimeException::class);

        $this->ask($user, '/billing/payment-method');
    }

    /**
     * The usage endpoint does not exist until a consumer binds the contract.
     *
     * Both halves are needed and neither is the other's negative control: the
     * absent half alone passes against a route registered under a condition that
     * is simply never true, and the bound half alone passes against a route
     * registered unconditionally.
     */
    public function test_the_usage_endpoint_is_absent_until_a_consumer_binds_the_contract(): void
    {
        $this->bootBillingRoutes('user');

        $this->assertFalse(app()->bound(ReportsUsage::class));

        $user = $this->createUser('usage@example.test');

        $this->ask($user, '/billing/usage')->assertNotFound();

        // The disarming limb: the rest of the billing surface IS registered, so
        // the 404 above is this one route's absence rather than a boot that
        // registered nothing at all.
        $this->ask($user, '/billing/plans')->assertOk();

        // Bind, re-register, and the same path answers with the consumer's map.
        $this->bindUsageReporter();
        $this->bootBillingRoutes('user');

        $this->ask($user, '/billing/usage')
            ->assertOk()
            ->assertExactJson([
                'seats' => [
                    'used' => 3,
                    'limit' => 10,
                ],
            ]);
    }

    /**
     * A caller pointed at a billable that is not theirs is answered 404.
     *
     * `current_team_id` survives the membership it points at being removed, so
     * this is an ex-member holding a live pointer at a team they no longer belong
     * to. It is masked as absence: a 403 would confirm the team exists, and a 200
     * would hand them its billing state.
     */
    public function test_a_caller_who_does_not_belong_to_the_billable_is_answered_404(): void
    {
        $this->bootBillingRoutes('team');

        $owner = $this->createUser('foreign-owner@example.test');
        $stranger = $this->createUser('foreign-stranger@example.test');
        $team = $this->createTeam($owner, [
            'plan' => 'pro',
            'plan_status' => 'active',
            'plan_provider' => 'stripe',
        ]);

        // The pointer is set and the membership is not, which is the shape that
        // outlives a removal.
        $this->setCurrentTeam($stranger, $team);

        $this->assertFalse($stranger->fresh()->belongsToTeam($team));

        $this->ask($stranger, '/billing')->assertNotFound();
        $this->ask($stranger, '/billing/payment-method')->assertNotFound();
        $this->ask($stranger, '/billing/portal')->assertNotFound();

        // The disarming limb: the owner of the same team is answered, so the 404
        // is about this caller and not about the team or the route.
        $this->setCurrentTeam($owner, $team);
        $this->ask($owner, '/billing')->assertOk();
    }

    /**
     * A member may read, and only the owner may open a portal session.
     */
    public function test_a_member_reads_the_billing_surface_but_cannot_open_the_portal(): void
    {
        $this->bootBillingRoutes('team');

        $owner = $this->createUser('portal-owner@example.test');
        $member = $this->createUser('portal-member@example.test');
        $team = $this->createTeam($owner, [
            'plan' => 'pro',
            'plan_status' => 'active',
            'plan_provider' => 'stripe',
            'stripe_id' => self::STRIPE_ID,
        ]);
        $team->users()->attach($member->getKey(), ['role' => 'admin']);
        $this->setCurrentTeam($member, $team);

        BillingTestRail::$hasStripeId = true;
        BillingTestRail::$portalUrl = 'https://billing.stripe.test/session/member';

        $this->ask($member, '/billing')->assertOk();
        $this->ask($member, '/billing/portal')->assertForbidden();

        $this->setCurrentTeam($owner, $team);
        $this->ask($owner, '/billing/portal')->assertOk();
    }

    /**
     * The portal refuses a store-managed subscription with a localised 409.
     *
     * Asserted against the shipped `tr` catalogue VALUE rather than against a
     * literal in this file, because a hardcoded expectation passes whether or not
     * the sentence was ever translated. What that proves is that the endpoint
     * reads the catalogue, NOT that the Turkish is Turkish: an English sentence
     * pasted into the `tr` file would satisfy it too.
     */
    public function test_the_portal_refuses_a_store_managed_subscription_in_the_callers_locale(): void
    {
        $this->bootBillingRoutes('user');

        $user = $this->createUser('store@example.test', [
            'plan' => 'pro',
            'plan_status' => 'active',
            'plan_provider' => 'app_store',
            'stripe_id' => self::STRIPE_ID,
        ]);

        BillingTestRail::$hasStripeId = true;

        app()->setLocale('tr');

        $this->ask($user, '/billing/portal')
            ->assertStatus(409)
            ->assertJsonPath('message', $this->shippedLine('tr', 'managed_by_store'))
            ->assertJsonPath('billing.reason', BillingController::REASON_MANAGED_BY_STORE)
            ->assertJsonPath('billing.provider', 'app_store');

        // The other locale answers with its own sentence, from the same key.
        app()->setLocale('en');

        $this->ask($user, '/billing/portal')
            ->assertStatus(409)
            ->assertJsonPath('message', $this->shippedLine('en', 'managed_by_store'));
    }

    /**
     * A subject nothing has ever charged is refused with the OTHER reason.
     *
     * The two refusals are distinct facts leading to opposite next steps, so the
     * reason codes must differ; a shared code would leave the client guessing.
     */
    public function test_the_portal_refuses_a_subject_with_no_billing_account(): void
    {
        $this->bootBillingRoutes('user');

        $user = $this->createUser('no-account@example.test', [
            'plan_status' => 'none',
            'plan_provider' => 'none',
        ]);

        BillingTestRail::$hasStripeId = false;

        app()->setLocale('tr');

        $this->ask($user, '/billing/portal')
            ->assertStatus(409)
            ->assertJsonPath('message', $this->shippedLine('tr', 'no_billing_account'))
            ->assertJsonPath('billing.reason', BillingController::REASON_NO_BILLING_ACCOUNT)
            ->assertJsonPath('billing.provider', 'none');

        $this->assertNotSame(
            BillingController::REASON_MANAGED_BY_STORE,
            BillingController::REASON_NO_BILLING_ACCOUNT,
        );
    }

    /**
     * The store-conflict read names the caller's OTHER store-funded team.
     *
     * The team the caller is currently on is excluded, which is the half a bare
     * "any owned team a store bills" implementation would get wrong: it would
     * report the subject against itself and hide the purchase CTA from everybody
     * who already bought.
     */
    public function test_the_store_conflict_read_names_another_owned_team_and_never_the_current_one(): void
    {
        $this->bootBillingRoutes('team');

        $owner = $this->createUser('conflict@example.test');
        $current = $this->createTeam($owner, [
            'name' => 'Current Team',
            'plan_status' => 'none',
            'plan_provider' => 'none',
        ]);
        $funded = $this->createTeam($owner, [
            'name' => 'Store Funded Team',
            'plan' => 'pro',
            'plan_status' => 'active',
            'plan_provider' => 'play_store',
        ]);
        $this->setCurrentTeam($owner, $current);

        $this->ask($owner, '/billing/store-funded-team')
            ->assertOk()
            ->assertJsonPath('store_funded_team.id', $funded->getKey())
            ->assertJsonPath('store_funded_team.name', 'Store Funded Team');

        // Standing ON the funded team reports nothing: the conflict is with
        // ANOTHER subject, and a subject is never its own conflict.
        $this->setCurrentTeam($owner, $funded);

        $this->ask($owner, '/billing/store-funded-team')
            ->assertOk()
            ->assertJsonPath('store_funded_team', null);
    }

    /**
     * A store-billed team sitting on the adopter's FLOOR tier is not a conflict.
     *
     * The rule lives in one place ({@see ReadsBillableAttributes::holdsPaidTier()})
     * and this endpoint asks it rather than re-deciding it, which is what this
     * test pins. A local `plan !== null` here would report the team below and
     * hide the purchase button from a customer who has bought nothing; a local
     * `plan !== 'free'` would name a tier this package has no vocabulary for. The
     * floor comes from the adopter's own `tier_order`, cheapest first.
     */
    public function test_a_store_billed_subject_on_the_floor_tier_is_not_reported_as_a_conflict(): void
    {
        $this->bootBillingRoutes('team');

        $owner = $this->createUser('floor@example.test');
        $current = $this->createTeam($owner, [
            'name' => 'Current Team',
            'plan_status' => 'none',
            'plan_provider' => 'none',
        ]);
        $this->createTeam($owner, [
            'name' => 'Free Store Team',
            'plan' => 'free',
            'plan_status' => 'active',
            'plan_provider' => 'app_store',
        ]);
        $this->setCurrentTeam($owner, $current);

        $this->ask($owner, '/billing/store-funded-team')
            ->assertOk()
            ->assertJsonPath('store_funded_team', null);

        // The disarming limb: move that same team one tier up and it IS a
        // conflict, so the null above is the floor rule and not a fixture that
        // failed to save its rail.
        BillingTestTeam::query()->where('name', 'Free Store Team')->update(['plan' => 'pro']);

        $this->ask($owner, '/billing/store-funded-team')
            ->assertOk()
            ->assertJsonPath('store_funded_team.name', 'Free Store Team');
    }

    /**
     * A billable whose application never applied Cashier's trait still answers.
     *
     * The billable model belongs to the consuming application, so an application
     * that sells only in the two app stores has no reason to carry the trait. A
     * direct Cashier call on such a model is a fatal on the billing screen, and
     * these three endpoints are where one would land.
     */
    public function test_a_billable_with_no_cashier_trait_answers_instead_of_fataling(): void
    {
        // LOAD-BEARING, and it looks redundant beside the CashierlessTeam rows
        // below, which is exactly why it is worth a comment. The controller does
        // not receive the model this test builds: it resolves the subject through
        // `MagicStarter::teamModel()`, so without this line the row is written by
        // the Cashierless class and hydrated straight back into the one that HAS
        // the trait. Measured: with the line removed, `defaultCard()`'s
        // `method_exists()` guard can be disabled outright and this test still
        // passes all eight assertions, because the rail fixture answers empty on
        // these three endpoints anyway. With it, disabling the guard fails at the
        // payment-method assertion, which is the whole claim in the method name.
        config(['magic-starter.models.team' => CashierlessTeam::class]);

        $this->bootBillingRoutes('team');

        $owner = $this->createUser('no-cashier@example.test');
        $team = CashierlessTeam::query()->create([
            'user_id' => $owner->getKey(),
            'name' => 'Store Only',
            'plan' => 'pro',
            'plan_status' => 'active',
            'plan_provider' => 'app_store',
        ]);
        $team->users()->attach($owner->getKey(), ['role' => 'owner']);
        $this->setCurrentTeam($owner, $team);

        $this->assertFalse(method_exists($team, 'defaultPaymentMethod'));
        $this->assertSame(
            CashierlessTeam::class,
            MagicStarter::billableModel(),
            'The subject the controller resolves is the one without the trait, which is what '
            . 'makes the assertions below about an absent trait rather than about an empty rail.',
        );

        $this->ask($owner, '/billing/invoices')
            ->assertOk()
            ->assertExactJson([
                'data' => [],
                'next_cursor' => null,
            ]);

        $this->ask($owner, '/billing/payment-method')
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('brand', null);

        // The portal is the store refusal rather than a customer-less one,
        // because the rail is the more specific and the more actionable fact.
        $this->ask($owner, '/billing/portal')
            ->assertStatus(409)
            ->assertJsonPath('billing.reason', BillingController::REASON_MANAGED_BY_STORE);
    }

    /**
     * The catalogue endpoint serves the adopter's entries VERBATIM while no
     * translation exists for their copy.
     *
     * The assertion is exact-JSON against the configured array rather than a
     * field-by-field check, which is the only shape that can fail when the
     * package quietly drops or renames a key it does not itself name. `limits`
     * is the load-bearing part: it is product knowledge this package has no
     * schema for, and a client that renders a plan card needs it to arrive
     * unchanged.
     *
     * An unpublished catalogue is an empty list and not an error. Selling
     * nothing yet is a legitimate state, and it is a different fact from
     * "this endpoint is not wired", which is what a 404 would say.
     */
    public function test_the_plans_endpoint_serves_the_catalogue_verbatim(): void
    {
        $this->bootBillingRoutes('user');

        $user = $this->createUser('plans@example.test');

        // Every key the adopter wrote travels untouched; `cycles` and
        // `products` are the two fields the endpoint DERIVES from the products
        // that sell the tier. This catalogue sells nothing, so both are empty
        // LISTS rather than absent.
        $expected = [];

        foreach (config('magic-starter.billing.tiers') as $id => $definition) {
            $expected[] = ['id' => $id] + $definition + [
                'cycles' => [],
                'products' => [],
            ];
        }

        $this->ask($user, '/billing/plans')
            ->assertOk()
            ->assertExactJson(['data' => $expected]);

        config(['magic-starter.billing.tier_order' => []]);

        $this->ask($user, '/billing/plans')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    /**
     * Every display string of a tier is translated into the request locale.
     *
     * The English copy in config is the translation KEY, exactly like a JSON
     * translation file expects, so a multilingual application translates its
     * plan grid with `lang/tr.json` and nothing else. Each control below fails
     * a different mutant: the untranslated bullet one that drops a line it
     * cannot translate, `limits` one that walks into associative arrays, `id`
     * one that translates the identifier a client matches the entitlement
     * against, and the group name one that hands a client the whole PHP
     * translation file `__()` answers for it.
     */
    public function test_the_plans_endpoint_translates_tier_copy_into_the_request_locale(): void
    {
        $this->bootBillingRoutes('user');

        config([
            'magic-starter.billing.tier_order' => ['free', 'pro'],
            'magic-starter.billing.tiers' => [
                'free' => [
                    'name' => 'Free',
                ],
                'pro' => [
                    'name' => 'Pro',
                    'tagline' => 'For growing teams.',
                    'ai_line' => 'AI triage on every incident.',
                    'features' => [
                        'Unlimited monitors',
                        'An untranslated bullet',
                        42,
                    ],
                    'recommended' => true,
                    'limits' => [
                        'seats' => 10,
                        'label' => 'Unlimited monitors',
                    ],
                    'note' => 'validation',
                ],
            ],
        ]);

        // A JSON translation file, keyed by the English source string, which is
        // the shape an adopter's `lang/tr.json` has. It also translates `free`
        // and `pro`, so an `id` that went through `__()` would show.
        Lang::addJsonPath(__DIR__ . '/../../Fixtures/lang');

        app()->setLocale('tr');

        $data = $this->ask($this->createUser('locale@example.test'), '/billing/plans')
            ->assertOk()
            ->json('data');

        $this->assertSame(['id' => 'free', 'name' => 'Free', 'cycles' => [], 'products' => []], $data[0]);
        $this->assertSame([
            'id' => 'pro',
            'name' => 'Profesyonel',
            'tagline' => 'Buyuyen ekipler icin.',
            'ai_line' => 'Her olayda yapay zeka triyaji.',
            'features' => [
                'Sinirsiz monitor',
                'An untranslated bullet',
                42,
            ],
            'recommended' => true,
            'limits' => [
                'seats' => 10,
                'label' => 'Unlimited monitors',
            ],
            'note' => 'validation',
            'cycles' => [],
            'products' => [],
        ], $data[1]);

        // The control: the same request in the source locale is the config.
        app()->setLocale('en');

        $this->ask($this->createUser('source@example.test'), '/billing/plans')
            ->assertOk()
            ->assertJsonPath('data.1.name', 'Pro')
            ->assertJsonPath('data.1.features.0', 'Unlimited monitors');
    }

    /**
     * A ranked tier whose definition is not an object is served as its bare id.
     *
     * The endpoint promises a list of objects, so a string definition is never
     * served as one. It is not dropped either: the ranking says the tier exists,
     * and hiding a tier the rails can sell would leave a customer holding
     * something the screen cannot name.
     */
    public function test_the_plans_endpoint_serves_a_malformed_definition_as_the_bare_tier(): void
    {
        $this->bootBillingRoutes('user');

        config([
            'magic-starter.billing.tier_order' => ['pro', 'business'],
            'magic-starter.billing.tiers' => [
                'pro' => ['name' => 'Pro'],
                'business' => 'Business',
            ],
        ]);

        $this->ask($this->createUser('shape@example.test'), '/billing/plans')
            ->assertOk()
            ->assertExactJson(['data' => [
                ['id' => 'pro', 'name' => 'Pro', 'cycles' => [], 'products' => []],
                ['id' => 'business', 'cycles' => [], 'products' => []],
            ]]);
    }

    /**
     * Each tier row lists the catalogue products that sell it, with web prices
     * the client can show without doing amount math.
     *
     * `data` stays a LIST of tier rows, floor first, because the client decoder
     * refuses anything else; the products ride INSIDE the row they sell rather
     * than beside it. A one-off product names no tier, so it belongs to no row.
     *
     * The display string is checked per currency because the exponent differs:
     * a hardcoded `/ 100` would show the yen price a hundred times too small.
     */
    public function test_each_tier_row_lists_the_products_that_sell_it_with_web_prices(): void
    {
        $this->bootBillingRoutes('user');

        config([
            'magic-starter.billing.products' => [
                'pro_monthly' => [
                    'type' => 'subscription',
                    'tier' => 'pro',
                    'cycle' => 'monthly',
                    'prices' => [
                        'web' => [
                            'USD' => 1900,
                            'JPY' => 2900,
                        ],
                        'app_store' => ['USD' => 2299],
                    ],
                    'refs' => [
                        'stripe_price' => 'price_pro_monthly',
                        'app_store' => 'com.example.pro.monthly',
                    ],
                ],
                'pro_annual' => [
                    'type' => 'subscription',
                    'tier' => 'pro',
                    'cycle' => 'annual',
                    'refs' => ['play' => 'pro:annual'],
                ],
                'credits_100' => [
                    'type' => 'consumable',
                    'credits' => 100,
                    'prices' => ['web' => ['USD' => 500]],
                ],
                // A one-off naming a tier is still not a subscription of it.
                'pro_boost' => [
                    'type' => 'non_consumable',
                    'tier' => 'pro',
                    'prices' => ['web' => ['USD' => 900]],
                ],
            ],
        ]);

        $response = $this->ask($this->createUser('products@example.test'), '/billing/plans')->assertOk();
        $data = $response->json('data');

        $this->assertTrue(array_is_list($data));
        $this->assertSame(['free', 'pro', 'business'], array_column($data, 'id'));
        $this->assertSame([], $data[0]['products']);
        $this->assertSame([], $data[2]['products']);

        $this->assertSame([
            [
                'key' => 'pro_monthly',
                'type' => 'subscription',
                'tier' => 'pro',
                'cycle' => 'monthly',
                'sellable' => true,
                'trial_days' => 0,
                'store_ids' => [
                    'app_store' => 'com.example.pro.monthly',
                    'play' => null,
                ],
                'prices' => [
                    'web' => [
                        'USD' => [
                            'amount_minor' => 1900,
                            'display' => '19.00 USD',
                        ],
                        'JPY' => [
                            'amount_minor' => 2900,
                            'display' => '2900 JPY',
                        ],
                    ],
                ],
            ],
            [
                'key' => 'pro_annual',
                'type' => 'subscription',
                'tier' => 'pro',
                'cycle' => 'annual',
                'sellable' => true,
                'trial_days' => 0,
                'store_ids' => [
                    'app_store' => null,
                    'play' => 'pro:annual',
                ],
                'prices' => ['web' => []],
            ],
        ], $data[1]['products']);

        // A product with no web price still carries a `web` OBJECT, because a
        // client decoding a currency map cannot read `[]` as one.
        $this->assertStringContainsString('"prices":{"web":{}}', (string) $response->getContent());

        // Only a product with a Stripe price makes a cycle sellable on this rail.
        $this->assertSame(['monthly'], $data[1]['cycles']);
    }

    /**
     * A product kept for mapping is listed flagged `sellable: false` and is not
     * counted as a cycle, and the entitlement of a team still on its price still
     * names it.
     *
     * Listed rather than hidden because a client ranks what a customer holds
     * against the row: a grandfathered product missing from it could not be
     * placed at all. The flag is what keeps the client from offering it.
     */
    public function test_a_product_kept_for_mapping_is_not_offered_but_still_names_its_subscriber_tier(): void
    {
        config([
            'magic-starter.billing.products' => [
                'pro_monthly' => [
                    'type' => 'subscription',
                    'tier' => 'pro',
                    'cycle' => 'monthly',
                    'refs' => ['stripe_price' => 'price_pro_monthly'],
                ],
                'pro_monthly_2025' => [
                    'type' => 'subscription',
                    'tier' => 'pro',
                    'cycle' => 'monthly',
                    'sellable' => false,
                    'refs' => ['stripe_price' => 'price_old'],
                ],
                'business_monthly_2025' => [
                    'type' => 'subscription',
                    'tier' => 'business',
                    'cycle' => 'monthly',
                    'sellable' => false,
                    'refs' => ['stripe_price' => 'price_business_old'],
                ],
            ],
        ]);

        $this->bindUsageReporter();
        $this->bootBillingRoutes('team');

        $owner = $this->createUser('retired-product@example.test');
        $team = $this->createTeam($owner, [
            'plan' => 'pro',
            'plan_status' => 'active',
            'plan_provider' => 'stripe',
            'plan_product_id' => 'price_old',
        ]);
        $this->setCurrentTeam($owner, $team);

        $data = $this->ask($owner, '/billing/plans')->assertOk()->json('data');

        $this->assertSame(['pro_monthly', 'pro_monthly_2025'], array_column($data[1]['products'], 'key'));
        $this->assertSame([true, false], array_column($data[1]['products'], 'sellable'));
        $this->assertSame(['monthly'], $data[1]['cycles']);
        $this->assertSame(['business_monthly_2025'], array_column($data[2]['products'], 'key'));
        $this->assertSame([false], array_column($data[2]['products'], 'sellable'));
        $this->assertSame([], $data[2]['cycles']);

        $this->assertSame('pro_monthly_2025', $this->ask($owner, '/billing')->assertOk()->json('data.product'));
    }

    /**
     * The entitlement names the catalogue product it sits on and the consumer's
     * allowances, in the shapes the client decoder reads.
     *
     * `owned` is a LIST and `balances` and `allowances` are OBJECTS even when
     * empty: PHP encodes an empty array as `[]`, which a client decoding a map
     * refuses, so the empty object is asserted on the raw body.
     */
    public function test_the_entitlement_names_its_catalogue_product_and_the_bound_allowances(): void
    {
        config([
            'magic-starter.billing.products' => [
                'pro_monthly' => [
                    'type' => 'subscription',
                    'tier' => 'pro',
                    'cycle' => 'monthly',
                    'refs' => ['stripe_price' => 'price_pro_monthly'],
                ],
                'pro_annual' => [
                    'type' => 'subscription',
                    'tier' => 'pro',
                    'cycle' => 'annual',
                    'refs' => ['stripe_price' => 'price_pro_annual'],
                ],
            ],
        ]);

        $this->bindUsageReporter();
        $this->bootBillingRoutes('team');

        $owner = $this->createUser('product-read@example.test');
        $team = $this->createTeam($owner, [
            'plan' => 'pro',
            'plan_status' => 'active',
            'plan_provider' => 'stripe',
            'plan_product_id' => 'price_pro_annual',
        ]);
        $this->setCurrentTeam($owner, $team);

        $response = $this->ask($owner, '/billing')->assertOk();

        $this->assertSame('pro_annual', $response->json('data.product'));
        $this->assertSame('annual', $response->json('data.cycle'));
        $this->assertSame([], $response->json('data.owned'));
        $this->assertSame(['seats' => ['used' => 3, 'limit' => 10]], $response->json('data.allowances'));
        $this->assertStringContainsString('"owned":[]', (string) $response->getContent());
        $this->assertStringContainsString('"balances":{}', (string) $response->getContent());
    }

    /**
     * Each tier is told which cycles it can be SOLD on, from the price map.
     *
     * The catalogue and the price map are independent config keys and nothing on
     * the wire related them, so an adopter filling in both display figures for a
     * tier while mapping only its monthly price shipped an annual button on
     * every billing screen. The customer learned that price did not exist from a
     * 422 after committing to buy: the same screen-versus-charge divergence the
     * cycle was added to close, one step later.
     *
     * THE PAIR IS THE TEST and the empty limb is the one with money behind it. A
     * derivation that returned every cycle regardless would satisfy the first
     * two entries and still render the button that cannot be honoured.
     */
    public function test_each_tier_publishes_the_cycles_a_price_actually_sells(): void
    {
        $this->bootBillingRoutes('user');

        config([
            'magic-starter.billing.products' => [
                'pro_monthly' => [
                    'type' => 'subscription',
                    'tier' => 'pro',
                    'cycle' => 'monthly',
                    'refs' => ['stripe_price' => 'price_pro_monthly'],
                ],
                'pro_annual' => [
                    'type' => 'subscription',
                    'tier' => 'pro',
                    'cycle' => 'annual',
                    'refs' => ['stripe_price' => 'price_pro_annual'],
                ],
                'business_monthly' => [
                    'type' => 'subscription',
                    'tier' => 'business',
                    'cycle' => 'monthly',
                    'refs' => ['stripe_price' => 'price_business'],
                ],
            ],
        ]);

        $data = $this->ask($this->createUser('cycles@example.test'), '/billing/plans')
            ->assertOk()
            ->json('data');

        // Both, one, and none: a free tier nobody prices is as real a state as
        // the other two and must not read as sellable.
        $this->assertSame(['monthly', 'annual'], $data[1]['cycles']);
        $this->assertSame(['monthly'], $data[2]['cycles']);
        $this->assertSame([], $data[0]['cycles']);
    }

    /**
     * The ranking decides the floor, never the order the tiers map is written in.
     *
     * The definitions and the ranking are two keys, and a map is written in
     * whatever order its author typed it. Reading the floor off the map would
     * move a money rule (which tier is free) whenever somebody reordered display
     * copy. Asserted through the FLOOR rather than through the reader directly,
     * because the floor is what the money rules actually ask.
     */
    public function test_the_ranking_and_not_the_tiers_map_decides_the_floor(): void
    {
        config([
            'magic-starter.billing.tier_order' => ['scale', 'starter'],
            'magic-starter.billing.tiers' => [
                'starter' => ['name' => 'Starter'],
                'scale' => ['name' => 'Scale'],
            ],
        ]);

        $this->bootBillingRoutes('team');

        $owner = $this->createUser('explicit@example.test');
        $team = $this->createTeam($owner, [
            'plan' => 'scale',
            'plan_status' => 'active',
            'plan_provider' => 'app_store',
        ]);

        $this->assertFalse(
            SubscriptionGuardedDeleteTeam::storeIsBilling($team),
            'The ranking puts `scale` on the floor, so it is the ranking being read and not '
            . 'the tiers map, which orders the two the other way.',
        );

        $team->forceFill(['plan' => 'starter'])->save();

        $this->assertTrue(
            SubscriptionGuardedDeleteTeam::storeIsBilling($team),
            'The control: a tier above that floor still reads as paid, so the assertion '
            . 'above is not passing because nothing was recognised at all.',
        );
    }

    /**
     * Every billing endpoint sits behind the authenticated group.
     */
    public function test_no_billing_endpoint_answers_an_unauthenticated_caller(): void
    {
        $this->bindUsageReporter();
        $this->bootBillingRoutes('user');

        foreach ([
            '/billing',
            '/billing/plans',
            '/billing/usage',
            '/billing/invoices',
            '/billing/payment-method',
            '/billing/store-funded-team',
            '/billing/portal',
        ] as $path) {
            $this->getJson($path)->assertUnauthorized();
        }
    }

    /**
     * Nothing billing-shaped is registered while the feature is off.
     */
    public function test_no_billing_route_exists_while_the_feature_is_off(): void
    {
        $this->bindUsageReporter();

        config([
            'magic-starter.features' => [Features::teams()],
            'magic-starter.billing.billable' => 'user',
        ]);

        $this->app['router']->setRoutes(new RouteCollection);
        (new MagicStarterServiceProvider($this->app))->boot();

        $user = $this->createUser('feature-off@example.test');

        $this->ask($user, '/billing')->assertNotFound();
        $this->ask($user, '/billing/usage')->assertNotFound();

        // The disarming limb: the provider booted and registered its other
        // routes, so the 404s above are the billing gate and not an empty
        // router.
        $this->ask($user, '/teams')->assertOk();
    }

    /**
     * Each product row carries the trial THIS subject would get: the configured
     * days while the caller is eligible, 0 once they have trialed, and 0 on a
     * product that offers none.
     *
     * The pair is the test. A row that always carried the configured days
     * passes the first limb and promises a trial the checkout then refuses;
     * one that always carried 0 passes the second.
     */
    public function test_each_product_row_offers_its_trial_only_to_an_eligible_subject(): void
    {
        $this->configureTrialCatalogue();
        $this->bootBillingRoutes('user');

        $user = $this->createUser('trial-rows@example.test');

        $this->assertSame(
            [
                'pro_monthly' => 14,
                'pro_annual' => 0,
            ],
            $this->trialDaysByProduct($user),
        );

        $this->recordTrial($user, $user);

        $this->assertSame(
            [
                'pro_monthly' => 0,
                'pro_annual' => 0,
            ],
            $this->trialDaysByProduct($user),
        );
    }

    /**
     * Under the team subject the TEAM's history counts as well as the caller's:
     * an owner who never trialed is offered nothing on a team a member already
     * trialed, and the full trial on a team nobody did.
     */
    public function test_a_team_another_member_trialed_is_offered_no_trial(): void
    {
        $this->configureTrialCatalogue();
        $this->bootBillingRoutes('team');

        $owner = $this->createUser('trial-team-owner@example.test');
        $member = $this->createUser('trial-team-member@example.test');
        $team = $this->createTeam($owner);
        $team->users()->attach($member->getKey(), ['role' => 'admin']);
        $this->setCurrentTeam($owner, $team);

        $this->recordTrial($member, $team);

        $this->assertSame(0, $this->trialDaysByProduct($owner)['pro_monthly']);

        $fresh = $this->createTeam($owner, ['name' => 'Fresh Team']);
        $this->setCurrentTeam($owner, $fresh);

        $this->assertSame(14, $this->trialDaysByProduct($owner)['pro_monthly']);
    }

    /**
     * A team-subject caller with no current team still gets the catalogue, as
     * they always have, with no trial on any row: there is no subject whose
     * history could be read, and a 404 here would blank the plan grid of
     * somebody who has simply not picked a team yet.
     */
    public function test_a_team_subject_caller_with_no_current_team_gets_the_plans_with_no_trial(): void
    {
        $this->configureTrialCatalogue();
        $this->bootBillingRoutes('team');

        $owner = $this->createUser('no-team-trial@example.test');

        $this->assertSame(
            [
                'pro_monthly' => 0,
                'pro_annual' => 0,
            ],
            $this->trialDaysByProduct($owner),
        );

        // The same for a pointer at a team the caller no longer belongs to.
        $stranger = $this->createUser('stranger-owner@example.test');
        $this->setCurrentTeam($owner, $this->createTeam($stranger));

        $this->assertSame(0, $this->trialDaysByProduct($owner)['pro_monthly']);

        // The disarming limb: the same caller on a team of their own is offered
        // the trial, so the zeros above are the missing subject.
        $this->setCurrentTeam($owner, $this->createTeam($owner));

        $this->assertSame(14, $this->trialDaysByProduct($owner)['pro_monthly']);
    }

    /**
     * An adopter whose catalogue offers no trial is answered exactly as before,
     * every row at 0, WITHOUT reading the trial history: an application that
     * never ran the `billing_trials` migration must keep its plan grid.
     */
    public function test_plans_without_a_trial_product_read_no_trial_history(): void
    {
        config([
            'magic-starter.billing.products' => [
                'pro_monthly' => [
                    'type' => 'subscription',
                    'tier' => 'pro',
                    'cycle' => 'monthly',
                    'refs' => ['stripe_price' => 'price_pro_monthly'],
                ],
            ],
        ]);

        $this->bootBillingRoutes('user');

        $user = $this->createUser('unmigrated@example.test');

        app('db.schema')->drop('billing_trials');
        DB::enableQueryLog();

        $this->assertSame(['pro_monthly' => 0], $this->trialDaysByProduct($user));
        $this->assertSame([], array_filter(
            array_column(DB::getQueryLog(), 'query'),
            static fn (string $query): bool => str_contains($query, 'billing_trials'),
        ));
    }

    /**
     * A catalogue that offers a trial over a database that never ran the
     * `billing_trials` migration still serves the plan grid: no trial on any
     * row, and one warning naming the table, instead of a 500 on the billing
     * screen.
     */
    public function test_plans_offer_no_trial_while_the_trials_table_is_missing(): void
    {
        $this->configureTrialCatalogue();
        $this->bootBillingRoutes('user');

        $user = $this->createUser('unmigrated-trial@example.test');

        app('db.schema')->drop('billing_trials');
        Log::spy();

        $this->assertSame(
            [
                'pro_monthly' => 0,
                'pro_annual' => 0,
            ],
            $this->trialDaysByProduct($user),
        );

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => ($context['reason'] ?? null)
                === 'billing_trials_table_missing')
            ->once();
    }

    /**
     * Re-register the package's routes against a billable subject.
     *
     * The gate is thrown away with them: it is a container singleton resolved
     * during the application's own boot, so without this the `manageBilling`
     * ability would be whatever the default (all features off) feature set left
     * behind, and the ownership refusal could never fire.
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
     * Bind a consumer-side usage reporter, which is what turns the usage
     * endpoint on.
     */
    private function bindUsageReporter(): void
    {
        $this->app->bind(ReportsUsage::class, fn (): ReportsUsage => new BillingTestUsageReporter);
    }

    /**
     * Drive one endpoint as the given user.
     *
     * The acting instance is re-read rather than reused, because a real request
     * resolves its own model and this harness would otherwise carry a RELATION
     * loaded by an earlier call in the same test. That is not a detail: after
     * moving `current_team_id`, a cached `currentTeam` keeps answering with the
     * previous team, so an endpoint whose whole subject is "which billable is
     * this caller on" would be asserted against the wrong one.
     */
    private function ask(Model $user, string $path): TestResponse
    {
        return $this->actingAs($user->fresh(), 'sanctum')->getJson($path);
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
     * A subscription row carrying a trial end, which is the local read the
     * renewal date favours over the live one.
     */
    private function subscriptionWithTrial(): Model
    {
        $subscription = new BillingTestSubscription;
        $subscription->setAttribute('trial_ends_at', self::TRIAL_END);

        return $subscription;
    }

    /**
     * One page of real Cashier invoices for the given owner.
     */
    private function invoicePage(Model $owner): CursorPaginator
    {
        $invoice = new Invoice($owner, StripeInvoice::constructFrom([
            'id' => 'in_test_1',
            'number' => 'UPT-0001',
            'customer' => self::STRIPE_ID,
            'created' => 1790000000,
            'total' => 2900,
            'starting_balance' => 0,
            'currency' => 'usd',
            'status' => 'paid',
            'invoice_pdf' => 'https://stripe.test/invoice.pdf',
        ]));

        return new CursorPaginator([$invoice], BillingController::INVOICES_PER_PAGE);
    }

    /**
     * A Cashier payment method whose card is a Visa.
     */
    private function visaPaymentMethod(Model $owner): PaymentMethod
    {
        return new PaymentMethod($owner, StripePaymentMethod::constructFrom([
            'id' => 'pm_test_1',
            'customer' => self::STRIPE_ID,
            'card' => [
                'brand' => 'visa',
                'last4' => '4242',
                'exp_month' => 12,
                'exp_year' => 2030,
            ],
        ]));
    }

    private function createUser(string $email, array $attributes = []): BillingTestUser
    {
        return BillingTestUser::query()->create(array_merge([
            'name' => 'Billing User',
            'email' => $email,
        ], $attributes));
    }

    private function createTeam(BillingTestUser $owner, array $attributes = []): BillingTestTeam
    {
        $team = BillingTestTeam::query()->create(array_merge([
            'user_id' => $owner->getKey(),
            'name' => 'Billed Team',
            'personal_team' => false,
        ], $attributes));

        $team->users()->attach($owner->getKey(), ['role' => 'owner']);

        return $team;
    }

    private function setCurrentTeam(BillingTestUser $user, Model $team): void
    {
        $user->forceFill(['current_team_id' => $team->getKey()])->save();
    }

    /**
     * Two monthly and annual products of one tier, only the monthly offering a
     * trial.
     */
    private function configureTrialCatalogue(): void
    {
        config([
            'magic-starter.billing.products' => [
                'pro_monthly' => [
                    'type' => 'subscription',
                    'tier' => 'pro',
                    'cycle' => 'monthly',
                    'trial_days' => 14,
                    'refs' => ['stripe_price' => 'price_pro_monthly'],
                ],
                'pro_annual' => [
                    'type' => 'subscription',
                    'tier' => 'pro',
                    'cycle' => 'annual',
                    'refs' => ['stripe_price' => 'price_pro_annual'],
                ],
            ],
        ]);
    }

    /**
     * Each product row's `trial_days` from the plans endpoint, keyed by product.
     *
     * @return array<string, mixed>
     */
    private function trialDaysByProduct(Model $user): array
    {
        $data = $this->ask($user, '/billing/plans')->assertOk()->json('data');
        $products = array_merge([], ...array_column($data, 'products'));

        return array_column($products, 'trial_days', 'key');
    }

    /**
     * Record that [$user] started a trial on [$billable], as the trial webhook
     * would.
     */
    private function recordTrial(Model $user, Model $billable): void
    {
        BillingTrial::query()->create([
            'user_id' => $user->getKey(),
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'stripe_subscription_id' => 'sub_' . Str::random(12),
            'subscription_created_at' => now(),
        ]);
    }
}

/**
 * The rail's answers, set per test.
 *
 * A static registry rather than a mock, because the subject under test is what
 * the controller does with what a consumer's model hands back, and both models
 * below have to give the same answers without duplicating the doubles.
 */
final class BillingTestRail
{
    public static bool $hasStripeId = false;

    public static ?PaymentMethod $paymentMethod = null;

    public static ?\Throwable $failure = null;

    public static ?Model $subscription = null;

    public static ?CursorPaginator $invoices = null;

    public static string $portalUrl = 'https://billing.stripe.test/session';

    /**
     * The Stripe payment method the SUBSCRIPTION carries, which is where a
     * hosted checkout leaves the card, or null when it carries none.
     */
    public static ?StripePaymentMethod $subscriptionPaymentMethod = null;

    public static function reset(): void
    {
        self::$hasStripeId = false;
        self::$paymentMethod = null;
        self::$subscriptionPaymentMethod = null;
        self::$failure = null;
        self::$subscription = null;
        self::$invoices = null;
        self::$portalUrl = 'https://billing.stripe.test/session';
    }
}

/**
 * The Cashier surface a consuming application's billable model would carry.
 *
 * Applied to both fixture billables and deliberately NOT to
 * {@see CashierlessTeam}, which is what makes the absent-trait path drivable.
 */
trait BillingTestBillable
{
    public function hasStripeId(): bool
    {
        return BillingTestRail::$hasStripeId;
    }

    public function subscription(string $type = 'default'): ?Model
    {
        return BillingTestRail::$subscription;
    }

    public function defaultPaymentMethod(): ?PaymentMethod
    {
        if (BillingTestRail::$failure !== null) {
            throw BillingTestRail::$failure;
        }

        return BillingTestRail::$paymentMethod;
    }

    public function cursorPaginateInvoices(
        ?int $perPage = 24,
        array $parameters = [],
        string $cursorName = 'cursor',
        mixed $cursor = null,
    ): CursorPaginator {
        return BillingTestRail::$invoices ?? new CursorPaginator([], $perPage ?? 24);
    }

    public function billingPortalUrl(?string $returnUrl = null, array $options = []): string
    {
        return BillingTestRail::$portalUrl;
    }
}

class BillingTestUser extends Model implements AuthenticatableContract
{
    use AuthenticatableTrait;
    use Authorizable;
    use BillingTestBillable;
    use ConditionallyUsesUuids;
    use \FlutterSdk\MagicStarter\Traits\HasTeams;

    protected $table = 'users';

    protected $guarded = [];
}

class BillingTestTeam extends Team
{
    use BillingTestBillable;

    protected $table = 'teams';

    /**
     * Emptied because the package's own Team declares a four-key `$fillable`,
     * and a non-empty one wins over `$guarded`: without this every provenance
     * column a fixture sets is silently dropped, and each endpoint then reads
     * the unbilled default and passes for the wrong reason.
     */
    protected $fillable = [];

    protected $guarded = [];
}

/**
 * A billable whose application never applied Cashier's trait.
 */
class CashierlessTeam extends Team
{
    protected $table = 'teams';

    protected $fillable = [];

    protected $guarded = [];
}

/**
 * A subscription row, uncast like everything else the package does not own.
 */
class BillingTestSubscription extends Model
{
    protected $table = 'subscriptions';

    protected $guarded = [];

    /**
     * The live Stripe subscription, with `default_payment_method` expanded when
     * the caller asks for it.
     *
     * This is the card a hosted CHECKOUT leaves behind. Checkout attaches the
     * payment method to the subscription and does not touch the customer's
     * `invoice_settings.default_payment_method`, so a card that is genuinely on
     * file is invisible to a reader that consults the customer alone.
     *
     * @param  array<int, string>  $expand
     */
    public function asStripeSubscription(array $expand = []): StripeSubscription
    {
        // The key is ALWAYS present, null included, because Stripe always sends
        // it. Omitting it on the no-card path made `$subscription->
        // default_payment_method` an undefined property, which the SDK answers
        // with `Stripe Notice: Undefined property of Stripe\Subscription
        // instance` on stderr for every no-card read in the suite. A fixture
        // that is missing a key the producer always emits is not a smaller
        // fixture, it is a different payload.
        $payload = [
            'id' => 'sub_test_1',
            'default_payment_method' => null,
        ];

        if (BillingTestRail::$subscriptionPaymentMethod !== null) {
            $payload['default_payment_method'] = in_array('default_payment_method', $expand, true)
                ? BillingTestRail::$subscriptionPaymentMethod
                : 'pm_test_subscription';
        }

        return StripeSubscription::constructFrom($payload);
    }
}

/**
 * A consumer's usage reporter, which is what turns the usage endpoint on.
 */
class BillingTestUsageReporter implements ReportsUsage
{
    /**
     * @return array<string, array{used: int, limit: int|null}>
     */
    public function forBillable(Model $billable): array
    {
        return [
            'seats' => [
                'used' => 3,
                'limit' => 10,
            ],
        ];
    }
}
