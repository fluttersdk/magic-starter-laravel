<?php

namespace FlutterSdk\MagicStarter\Tests\Actions;

use Carbon\CarbonImmutable;
use FlutterSdk\MagicStarter\Contracts\AdministersBilling;
use FlutterSdk\MagicStarter\Contracts\WritesEntitlement;
use FlutterSdk\MagicStarter\Enums\BillingEventType;
use FlutterSdk\MagicStarter\Enums\BillingProvider;
use FlutterSdk\MagicStarter\Enums\BillingSource;
use FlutterSdk\MagicStarter\Enums\PlanStatus;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\BillingEvent;
use FlutterSdk\MagicStarter\Models\Subscription;
use FlutterSdk\MagicStarter\Models\SubscriptionItem;
use FlutterSdk\MagicStarter\Support\BillingAdministrationRefused;
use FlutterSdk\MagicStarter\Support\EntitlementWrite;
use FlutterSdk\MagicStarter\Support\RevenueCatClient;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\Support\StripeHttpStub;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Cashier\Billable;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription as CashierSubscription;
use Laravel\Cashier\SubscriptionItem as CashierSubscriptionItem;
use PDOException;

/**
 * Locks the Stripe side of the operator's billing actions: what each one sends
 * to Stripe, what it leaves in `billing_events`, and every refusal around them.
 *
 * Only Stripe's HTTP transport is faked ({@see StripeHttpStub}), so Cashier and
 * the SDK run as they do in production and the assertions are on the request
 * Stripe would have received. The store rail is answered through `Http::fake`.
 * The clock is frozen, because trial and grace-period checks compare it.
 */
class AdministerBillingStripeTest extends TestCase
{
    private StripeHttpStub $stripe;

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
            'cashier.secret' => 'sk_test_administer_billing',
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

        MagicStarter::useUserModel(StripePayer::class);
        Cashier::useCustomerModel(StripePayer::class);
        Cashier::useSubscriptionModel(Subscription::class);
        Cashier::useSubscriptionItemModel(SubscriptionItem::class);

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

        $this->stripe = StripeHttpStub::install();
    }

    protected function tearDown(): void
    {
        StripeHttpStub::uninstall();
        Cashier::useCustomerModel('App\\Models\\User');
        Cashier::useSubscriptionModel(CashierSubscription::class);
        Cashier::useSubscriptionItemModel(CashierSubscriptionItem::class);
        MagicStarter::reset();

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // The gate every Stripe operation shares
    // -------------------------------------------------------------------------

    public function test_every_stripe_operation_refuses_without_a_local_subscription(): void
    {
        $billable = $this->makeBillable();
        $operations = [
            'extendTrial' => fn () => $this->administer()->extendTrial(
                $this->operator(),
                $billable,
                $this->now()->addWeek(),
            ),
            'endTrial' => fn () => $this->administer()->endTrial($this->operator(), $billable),
            'cancel' => fn () => $this->administer()->cancel($this->operator(), $billable),
            'resume' => fn () => $this->administer()->resume($this->operator(), $billable),
            'refundLastInvoice' => fn () => $this->administer()->refundLastInvoice(
                $this->operator(),
                $billable,
                'requested_by_customer',
            ),
        ];

        foreach ($operations as $call) {
            $this->assertRefused('no_subscription', $call);
        }

        $this->assertSame([], $this->stripe->requests);

        $refused = $this->eventsOf(BillingEventType::REQUEST_REFUSED);
        $this->assertCount(5, $refused);
        $this->assertSame(
            array_keys($operations),
            array_map(fn (BillingEvent $row): string => $row->properties['operation'], $refused),
        );
        $this->assertSame(BillingSource::ADMIN, $refused[0]->source);
        $this->assertSame(BillingProvider::STRIPE, $refused[0]->provider);
    }

    /**
     * The gate is the local subscription, never the record: a payer whose
     * checkout was dropped under a comp is recorded as manual and still billed.
     */
    public function test_a_stripe_operation_does_not_refuse_on_the_recorded_provider(): void
    {
        $billable = $this->makeBillable([
            'plan' => 'pro',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::MANUAL->value,
        ]);
        $subscription = $this->makeSubscription($billable, trialEndsAt: $this->now()->addDays(3));

        $this->stripe->answer($this->stripeSubscription($subscription, ['status' => 'trialing']));

        $this->administer()->endTrial($this->operator(), $billable);

        $this->assertCount(1, $this->stripe->requestsTo('post', '/v1/subscriptions/' . $subscription->stripe_id));
    }

    // -------------------------------------------------------------------------
    // Trials
    // -------------------------------------------------------------------------

    public function test_extend_trial_moves_the_trial_end_on_stripe_and_records_it(): void
    {
        $operator = $this->operator();
        $billable = $this->makeBillable();
        $subscription = $this->makeSubscription($billable, 'trialing', trialEndsAt: $this->now()->addDays(3));
        $until = $this->now()->addDays(10);

        $this->stripe->answer($this->stripeSubscription($subscription, ['status' => 'trialing']));

        $this->administer()->extendTrial($operator, $billable, $until);

        $updates = $this->stripe->requestsTo('post', '/v1/subscriptions/' . $subscription->stripe_id);
        $this->assertCount(1, $updates);
        $this->assertSame($until->getTimestamp(), $updates[0]['params']['trial_end']);

        $this->assertSame($until->getTimestamp(), $subscription->refresh()->trial_ends_at?->getTimestamp());

        $extended = $this->eventsOf(BillingEventType::TRIAL_EXTENDED);
        $this->assertCount(1, $extended);
        $this->assertSame(BillingSource::ADMIN, $extended[0]->source);
        $this->assertSame(BillingProvider::STRIPE, $extended[0]->provider);
        $this->assertSame($subscription->stripe_id, $extended[0]->external_id);
        $this->assertSame($until->toIso8601ZuluString(), $extended[0]->properties['until']);
        $this->assertEquals($operator->getKey(), $extended[0]->actor_user_id);
    }

    public function test_extend_trial_is_refused_off_trial(): void
    {
        $billable = $this->makeBillable();
        $this->makeSubscription($billable);

        $this->assertRefused('not_trialing', fn () => $this->administer()->extendTrial(
            $this->operator(),
            $billable,
            $this->now()->addWeek(),
        ));

        $this->assertSame([], $this->stripe->requests);
    }

    public function test_extend_trial_to_a_past_date_is_refused(): void
    {
        $billable = $this->makeBillable();
        $this->makeSubscription($billable, 'trialing', trialEndsAt: $this->now()->addDays(3));

        $this->assertRefused('date_in_past', fn () => $this->administer()->extendTrial(
            $this->operator(),
            $billable,
            $this->now(),
        ));

        $this->assertSame([], $this->stripe->requests);
    }

    /**
     * Stripe refuses a trial end more than two years out with a 400; that
     * reaches the operator as a recorded `rail_error` carrying Stripe's words,
     * and the local trial end stays where it was.
     */
    public function test_a_stripe_4xx_is_a_recorded_rail_error(): void
    {
        $billable = $this->makeBillable();
        $trialEndsAt = $this->now()->addDays(3);
        $subscription = $this->makeSubscription($billable, 'trialing', trialEndsAt: $trialEndsAt);

        $this->stripe->answer([
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'trial_end must be at most two years from the billing cycle anchor.',
                'param' => 'trial_end',
            ],
        ], 400);

        $this->assertRefused('rail_error', fn () => $this->administer()->extendTrial(
            $this->operator(),
            $billable,
            $this->now()->addYears(3),
        ));

        $this->assertSame($trialEndsAt->getTimestamp(), $subscription->refresh()->trial_ends_at?->getTimestamp());
        $this->assertSame([], $this->eventsOf(BillingEventType::TRIAL_EXTENDED));

        $refused = $this->eventsOf(BillingEventType::REQUEST_REFUSED);
        $this->assertCount(1, $refused);
        $this->assertSame('rail_error', $refused[0]->reason);
        $this->assertSame(BillingProvider::STRIPE, $refused[0]->provider);
        $this->assertSame('extendTrial', $refused[0]->properties['operation']);
        $this->assertSame(
            'trial_end must be at most two years from the billing cycle anchor.',
            $refused[0]->properties['rail_message'],
        );
    }

    /**
     * An extension that does not move the end later would shorten the trial
     * or leave it as it is, neither of which is an extension.
     */
    public function test_extend_trial_to_a_date_not_after_the_current_trial_end_is_refused(): void
    {
        $billable = $this->makeBillable();
        $this->makeSubscription($billable, 'trialing', trialEndsAt: $this->now()->addDays(10));

        foreach ([
            $this->now()->addDays(5),
            $this->now()->addDays(10),
        ] as $until) {
            $this->assertRefused('not_later', fn () => $this->administer()->extendTrial(
                $this->operator(),
                $billable,
                $until,
            ));
        }

        $this->assertSame([], $this->stripe->requests);
    }

    /**
     * A cancelled trial keeps the end it was cancelled at in `ends_at`, which
     * a moved trial end would leave stale.
     */
    public function test_extend_trial_is_refused_while_the_trial_is_cancelled(): void
    {
        $billable = $this->makeBillable();
        $this->makeSubscription(
            $billable,
            'trialing',
            trialEndsAt: $this->now()->addDays(3),
            endsAt: $this->now()->addDays(3),
        );

        $this->assertRefused('already_cancelled', fn () => $this->administer()->extendTrial(
            $this->operator(),
            $billable,
            $this->now()->addDays(10),
        ));

        $this->assertSame([], $this->stripe->requests);
    }

    public function test_end_trial_is_refused_while_the_trial_is_cancelled(): void
    {
        $billable = $this->makeBillable();
        $this->makeSubscription(
            $billable,
            'trialing',
            trialEndsAt: $this->now()->addDays(3),
            endsAt: $this->now()->addDays(3),
        );

        $this->assertRefused('already_cancelled', fn () => $this->administer()->endTrial($this->operator(), $billable));

        $this->assertSame([], $this->stripe->requests);
    }

    public function test_end_trial_ends_it_now_on_stripe_and_records_it(): void
    {
        $billable = $this->makeBillable();
        $subscription = $this->makeSubscription($billable, 'trialing', trialEndsAt: $this->now()->addDays(3));

        $this->stripe->answer($this->stripeSubscription($subscription));

        $this->administer()->endTrial($this->operator(), $billable);

        $updates = $this->stripe->requestsTo('post', '/v1/subscriptions/' . $subscription->stripe_id);
        $this->assertCount(1, $updates);
        $this->assertSame('now', $updates[0]['params']['trial_end']);
        $this->assertNull($subscription->refresh()->trial_ends_at);

        $ended = $this->eventsOf(BillingEventType::TRIAL_ENDED);
        $this->assertCount(1, $ended);
        $this->assertSame(BillingSource::ADMIN, $ended[0]->source);
        $this->assertSame($subscription->stripe_id, $ended[0]->external_id);
        $this->assertSame('stripe_bills_now', $ended[0]->properties['note']);
    }

    public function test_end_trial_is_refused_off_trial(): void
    {
        $billable = $this->makeBillable();
        $this->makeSubscription($billable, trialEndsAt: $this->now()->subDay());

        $this->assertRefused('not_trialing', fn () => $this->administer()->endTrial($this->operator(), $billable));

        $this->assertSame([], $this->stripe->requests);
    }

    // -------------------------------------------------------------------------
    // Cancel and resume
    // -------------------------------------------------------------------------

    public function test_cancel_cancels_at_period_end_and_records_the_end(): void
    {
        $billable = $this->makeBillable();
        $subscription = $this->makeSubscription($billable);
        $periodEnd = $this->now()->addDays(20);

        SubscriptionItem::query()->forceCreate([
            'subscription_id' => $subscription->getKey(),
            'stripe_id' => 'si_cancel',
            'stripe_product' => 'prod_pro',
            'stripe_price' => 'price_pro',
            'quantity' => 1,
        ]);

        $this->stripe
            ->answer($this->stripeSubscription($subscription, ['cancel_at_period_end' => true]))
            ->answer([
                'id' => 'si_cancel',
                'object' => 'subscription_item',
                'current_period_end' => $periodEnd->getTimestamp(),
            ]);

        $this->administer()->cancel($this->operator(), $billable);

        $updates = $this->stripe->requestsTo('post', '/v1/subscriptions/' . $subscription->stripe_id);
        $this->assertCount(1, $updates);
        // The SDK puts a boolean on the wire as its word.
        $this->assertSame('true', $updates[0]['params']['cancel_at_period_end']);
        $this->assertSame($periodEnd->getTimestamp(), $subscription->refresh()->ends_at?->getTimestamp());

        $cancelled = $this->eventsOf(BillingEventType::SUBSCRIPTION_CANCELLED);
        $this->assertCount(1, $cancelled);
        $this->assertSame(BillingSource::ADMIN, $cancelled[0]->source);
        $this->assertSame($subscription->stripe_id, $cancelled[0]->external_id);
        $this->assertSame($periodEnd->toIso8601String(), $cancelled[0]->properties['ends_at']);
    }

    public function test_cancel_is_refused_on_grace_period_and_once_ended(): void
    {
        $graced = $this->makeBillable();
        $this->makeSubscription($graced, endsAt: $this->now()->addDays(5));

        $ended = $this->makeBillable();
        $this->makeSubscription($ended, 'canceled', endsAt: $this->now()->subDay());

        $this->assertRefused('already_cancelled', fn () => $this->administer()->cancel($this->operator(), $graced));
        $this->assertRefused('already_cancelled', fn () => $this->administer()->cancel($this->operator(), $ended));

        $this->assertSame([], $this->stripe->requests);
    }

    /**
     * Cashier's own `resume()` sends `trial_end: now` for a subscription off
     * trial, which can bill the customer and reset their billing date; the
     * operator's resume only lifts the cancellation.
     */
    public function test_resume_lifts_the_cancellation_without_a_trial_end(): void
    {
        $billable = $this->makeBillable();
        $subscription = $this->makeSubscription($billable, endsAt: $this->now()->addDays(5));

        $this->stripe
            ->answer($this->stripeSubscription($subscription, [
                'status' => 'past_due',
                'cancel_at_period_end' => true,
                'cancel_at' => $this->now()->addDays(5)->getTimestamp(),
            ]))
            ->answer($this->stripeSubscription($subscription, ['status' => 'past_due']));

        $this->administer()->resume($this->operator(), $billable);

        $this->assertCount(1, $this->stripe->requestsTo('get', '/v1/subscriptions/' . $subscription->stripe_id));

        $updates = $this->stripe->requestsTo('post', '/v1/subscriptions/' . $subscription->stripe_id);
        $this->assertCount(1, $updates);
        $this->assertSame(['cancel_at_period_end' => 'false'], $updates[0]['params']);
        $this->assertArrayNotHasKey('trial_end', $updates[0]['params']);

        $subscription->refresh();
        $this->assertNull($subscription->ends_at);
        $this->assertSame('past_due', $subscription->stripe_status);

        $resumed = $this->eventsOf(BillingEventType::SUBSCRIPTION_RESUMED);
        $this->assertCount(1, $resumed);
        $this->assertSame(BillingSource::ADMIN, $resumed[0]->source);
        $this->assertSame($subscription->stripe_id, $resumed[0]->external_id);
    }

    /**
     * A cancellation on a fixed date is not lifted by `cancel_at_period_end`,
     * so claiming a resume would leave the subscription ending all the same.
     */
    public function test_resume_is_refused_for_a_cancellation_on_a_fixed_date(): void
    {
        $billable = $this->makeBillable();
        $endsAt = $this->now()->addDays(5);
        $subscription = $this->makeSubscription($billable, endsAt: $endsAt);

        $this->stripe->answer($this->stripeSubscription($subscription, [
            'cancel_at_period_end' => false,
            'cancel_at' => $endsAt->getTimestamp(),
        ]));

        $this->assertRefused('scheduled_cancel', fn () => $this->administer()->resume($this->operator(), $billable));

        $this->assertSame([], $this->stripe->requestsTo('post', '/v1/subscriptions/' . $subscription->stripe_id));
        $this->assertSame($endsAt->getTimestamp(), $subscription->refresh()->ends_at?->getTimestamp());
        $this->assertSame([], $this->eventsOf(BillingEventType::SUBSCRIPTION_RESUMED));
    }

    public function test_resume_is_refused_off_grace_period(): void
    {
        $billable = $this->makeBillable();
        $this->makeSubscription($billable);

        $this->assertRefused('not_on_grace_period', fn () => $this->administer()->resume($this->operator(), $billable));

        $this->assertSame([], $this->stripe->requests);
    }

    // -------------------------------------------------------------------------
    // Refund
    // -------------------------------------------------------------------------

    /**
     * The newest paid invoice of a trial customer is the $0 trial invoice; the
     * refund goes to the newest one that moved money, under a key that makes a
     * double click one refund.
     */
    public function test_refund_targets_the_newest_non_zero_invoice_with_an_idempotency_key(): void
    {
        $operator = $this->operator();
        $billable = $this->makeBillable();
        $subscription = $this->makeSubscription($billable);

        $this->stripe
            ->answer($this->invoiceList([
                $this->invoice('in_trial', 0, $subscription->stripe_id),
                $this->invoice('in_paid', 2900, $subscription->stripe_id),
                $this->invoice('in_older', 2900, $subscription->stripe_id),
            ]))
            ->answer($this->paymentList([$this->invoicePayment('pi_paid')]))
            ->answer([
                'id' => 're_admin',
                'object' => 'refund',
                'amount' => 2900,
                'currency' => 'usd',
                'payment_intent' => 'pi_paid',
                'status' => 'succeeded',
            ]);

        $refundId = $this->administer()->refundLastInvoice($operator, $billable, 'requested_by_customer');

        $this->assertSame('re_admin', $refundId);

        // 1. Only the target's payments were read.
        $payments = $this->stripe->requestsTo('get', '/v1/invoice_payments');
        $this->assertCount(1, $payments);
        $this->assertSame('in_paid', $payments[0]['params']['invoice']);

        // 2. The refund itself, against the payment intent, with the key.
        $refunds = $this->stripe->requestsTo('post', '/v1/refunds');
        $this->assertCount(1, $refunds);
        $this->assertSame('pi_paid', $refunds[0]['params']['payment_intent']);
        $this->assertSame('requested_by_customer', $refunds[0]['params']['reason']);
        $this->assertSame(
            ['source' => 'magic-starter-admin', 'invoice' => 'in_paid'],
            $refunds[0]['params']['metadata'],
        );
        $this->assertContains('Idempotency-Key: admin-refund:in_paid', $refunds[0]['headers']);

        // 3. The subscription is not the refund's business.
        $this->assertSame([], $this->stripe->requestsTo('post', '/v1/subscriptions/' . $subscription->stripe_id));
        $this->assertSame('active', $subscription->refresh()->stripe_status);
        $this->assertNull($subscription->ends_at);

        $refunded = $this->eventsOf(BillingEventType::INVOICE_REFUNDED);
        $this->assertCount(1, $refunded);
        $this->assertSame(BillingSource::ADMIN, $refunded[0]->source);
        $this->assertSame(BillingProvider::STRIPE, $refunded[0]->provider);
        $this->assertSame('re_admin', $refunded[0]->external_id);
        $this->assertSame('in_paid', $refunded[0]->properties['invoice_id']);
        $this->assertSame(2900, $refunded[0]->properties['amount']);
        $this->assertSame('usd', $refunded[0]->properties['currency']);
        $this->assertSame('requested_by_customer', $refunded[0]->properties['reason']);
        $this->assertEquals($operator->getKey(), $refunded[0]->actor_user_id);
    }

    public function test_refund_with_a_reason_stripe_does_not_take_is_refused(): void
    {
        $billable = $this->makeBillable();
        $this->makeSubscription($billable);

        $this->assertRefused('invalid_reason', fn () => $this->administer()->refundLastInvoice(
            $this->operator(),
            $billable,
            'fraudulent',
        ));

        $this->assertSame([], $this->stripe->requests);
    }

    /**
     * A payment made outside a payment intent (an out-of-band payment record)
     * has nothing a refund can be created against, and an older invoice is not
     * "the last one", so nothing is refunded.
     */
    public function test_refund_is_refused_when_the_payment_is_not_a_payment_intent(): void
    {
        $billable = $this->makeBillable();
        $subscription = $this->makeSubscription($billable);

        $this->stripe
            ->answer($this->invoiceList([
                $this->invoice('in_paid', 2900, $subscription->stripe_id),
                $this->invoice('in_older', 2900, $subscription->stripe_id),
            ]))
            ->answer($this->paymentList([
                $this->invoicePayment(null, ['payment' => ['type' => 'payment_record', 'payment_record' => 'pr_1']]),
            ]));

        $this->assertRefused('nothing_refundable', fn () => $this->administer()->refundLastInvoice(
            $this->operator(),
            $billable,
            'duplicate',
        ));

        $this->assertSame([], $this->stripe->requestsTo('post', '/v1/refunds'));
        $this->assertCount(1, $this->stripe->requestsTo('get', '/v1/invoice_payments'));
    }

    public function test_refund_is_refused_when_no_invoice_moved_money(): void
    {
        $billable = $this->makeBillable();
        $subscription = $this->makeSubscription($billable);

        $this->stripe->answer($this->invoiceList([$this->invoice('in_trial', 0, $subscription->stripe_id)]));

        $this->assertRefused('nothing_refundable', fn () => $this->administer()->refundLastInvoice(
            $this->operator(),
            $billable,
            'requested_by_customer',
        ));

        $this->assertSame([], $this->stripe->requestsTo('post', '/v1/refunds'));
    }

    /**
     * The amount is the payment's own, not the invoice's: an invoice paid in
     * two payments refunds only the one payment intent it names.
     */
    public function test_the_refundable_payment_names_what_a_refund_would_take(): void
    {
        $billable = $this->makeBillable();
        $subscription = $this->makeSubscription($billable);

        $this->stripe
            ->answer($this->invoiceList([$this->invoice('in_paid', 2900, $subscription->stripe_id)]))
            ->answer($this->paymentList([
                $this->invoicePayment('pi_paid', [
                    'amount_paid' => 1900,
                    'currency' => 'eur',
                ]),
            ]));

        $this->assertSame([
            'invoice_id' => 'in_paid',
            'payment_intent' => 'pi_paid',
            'amount' => 1900,
            'currency' => 'eur',
        ], $this->administer()->refundablePayment($billable));

        // The payment intent's latest charge is expanded on the one read already made.
        $payments = $this->stripe->requestsTo('get', '/v1/invoice_payments');
        $this->assertCount(1, $payments);
        $this->assertSame(['data.payment.payment_intent.latest_charge'], $payments[0]['params']['expand']);
    }

    /**
     * A one-off invoice is not the subscription's and is never "the last
     * invoice" the operator refunds, however new it is.
     */
    public function test_refund_never_targets_an_invoice_outside_the_default_subscription(): void
    {
        $billable = $this->makeBillable();
        $subscription = $this->makeSubscription($billable);

        $this->stripe
            ->answer($this->invoiceList([
                $this->invoice('in_one_off', 9900),
                $this->invoice('in_other_subscription', 4900, 'sub_elsewhere'),
                $this->invoice('in_paid', 2900, $subscription->stripe_id),
            ]))
            ->answer($this->paymentList([$this->invoicePayment('pi_paid')]))
            ->answer($this->refund('re_admin', 2900));

        $this->administer()->refundLastInvoice($this->operator(), $billable, 'requested_by_customer');

        $payments = $this->stripe->requestsTo('get', '/v1/invoice_payments');
        $this->assertCount(1, $payments);
        $this->assertSame('in_paid', $payments[0]['params']['invoice']);
        $this->assertSame('pi_paid', $this->stripe->requestsTo('post', '/v1/refunds')[0]['params']['payment_intent']);
    }

    /**
     * The operator confirmed one invoice; a newer one that landed while the
     * modal was open is not what they confirmed, so nothing is refunded.
     */
    public function test_refund_is_refused_as_a_stale_target_when_a_newer_invoice_appeared(): void
    {
        $billable = $this->makeBillable();
        $subscription = $this->makeSubscription($billable);

        $this->stripe
            ->answer($this->invoiceList([
                $this->invoice('in_newer', 4900, $subscription->stripe_id),
                $this->invoice('in_shown', 2900, $subscription->stripe_id),
            ]))
            ->answer($this->paymentList([$this->invoicePayment('pi_newer')]));

        $this->assertRefused('stale_target', fn () => $this->administer()->refundLastInvoice(
            $this->operator(),
            $billable,
            'requested_by_customer',
            'in_shown',
        ));

        $this->assertSame([], $this->stripe->requestsTo('post', '/v1/refunds'));
        $this->assertSame([], $this->eventsOf(BillingEventType::INVOICE_REFUNDED));
        $this->assertSame('stale_target', $this->eventsOf(BillingEventType::REQUEST_REFUSED)[0]->reason);
    }

    public function test_refund_of_the_invoice_the_operator_confirmed_goes_through(): void
    {
        $billable = $this->makeBillable();
        $subscription = $this->makeSubscription($billable);

        $this->stripe
            ->answer($this->invoiceList([$this->invoice('in_shown', 2900, $subscription->stripe_id)]))
            ->answer($this->paymentList([$this->invoicePayment('pi_shown')]))
            ->answer($this->refund('re_shown', 2900));

        $this->assertSame('re_shown', $this->administer()->refundLastInvoice(
            $this->operator(),
            $billable,
            'duplicate',
            'in_shown',
        ));
    }

    /**
     * The row records what Stripe refunded, which is the refund's own amount
     * and currency rather than what the invoice once said.
     */
    public function test_the_refunded_row_records_the_refunds_own_amount_and_currency(): void
    {
        $billable = $this->makeBillable();
        $subscription = $this->makeSubscription($billable);

        $this->stripe
            ->answer($this->invoiceList([$this->invoice('in_paid', 2900, $subscription->stripe_id)]))
            ->answer($this->paymentList([$this->invoicePayment('pi_paid')]))
            ->answer($this->refund('re_partial', 1200, 'eur'));

        $this->administer()->refundLastInvoice($this->operator(), $billable, 'requested_by_customer');

        $refunded = $this->eventsOf(BillingEventType::INVOICE_REFUNDED);
        $this->assertCount(1, $refunded);
        $this->assertSame(1200, $refunded[0]->properties['amount']);
        $this->assertSame('eur', $refunded[0]->properties['currency']);
    }

    /**
     * A payment whose charge Stripe already refunded in full is skipped; the
     * invoice's next payment is the target, and with none the invoice has
     * nothing to refund.
     */
    public function test_a_payment_whose_charge_is_fully_refunded_is_skipped(): void
    {
        $billable = $this->makeBillable();
        $subscription = $this->makeSubscription($billable);

        $this->stripe
            ->answer($this->invoiceList([$this->invoice('in_paid', 2900, $subscription->stripe_id)]))
            ->answer($this->paymentList([
                $this->invoicePayment($this->expandedIntent('pi_refunded', refunded: true)),
                $this->invoicePayment($this->expandedIntent('pi_open', refunded: false)),
            ]));

        $this->assertSame('pi_open', $this->administer()->refundablePayment($billable)['payment_intent'] ?? null);

        $this->stripe
            ->answer($this->invoiceList([$this->invoice('in_paid', 2900, $subscription->stripe_id)]))
            ->answer($this->paymentList([
                $this->invoicePayment($this->expandedIntent('pi_refunded', refunded: true)),
            ]));

        $this->assertRefused('nothing_refundable', fn () => $this->administer()->refundLastInvoice(
            $this->operator(),
            $billable,
            'requested_by_customer',
        ));

        $this->assertSame([], $this->stripe->requestsTo('post', '/v1/refunds'));
    }

    public function test_a_charge_refunded_to_its_full_amount_is_skipped_even_when_not_flagged(): void
    {
        $billable = $this->makeBillable();
        $subscription = $this->makeSubscription($billable);

        $this->stripe
            ->answer($this->invoiceList([$this->invoice('in_paid', 2900, $subscription->stripe_id)]))
            ->answer($this->paymentList([
                $this->invoicePayment($this->expandedIntent('pi_refunded', refunded: false, amountRefunded: 2900)),
            ]));

        $this->assertNull($this->administer()->refundablePayment($billable));
    }

    public function test_there_is_no_refundable_payment_without_a_stripe_customer(): void
    {
        $billable = $this->makeBillable(['stripe_id' => null]);

        $this->assertNull($this->administer()->refundablePayment($billable));
        $this->assertSame([], $this->stripe->requests);
    }

    // -------------------------------------------------------------------------
    // Sync now
    // -------------------------------------------------------------------------

    /**
     * The local Cashier row missed a swap and the record is a comp: the live
     * read heals the row and, being Stripe speaking now, takes the record over
     * from the comp, which a projection could not.
     */
    public function test_sync_heals_the_local_row_and_takes_over_a_manual_record(): void
    {
        $billable = $this->makeBillable([
            'plan' => 'pro',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::MANUAL->value,
            'plan_product_id' => 'grant:1',
            'plan_source_event_at' => $this->now()->subDay(),
        ]);
        $subscription = $this->makeSubscription($billable, priceId: 'price_pro');
        $periodEnd = $this->now()->addDays(25);

        $this->stripe->answer($this->stripeSubscription($subscription, [
            'items' => $this->stripeItems('price_business', $periodEnd, quantity: 3),
        ]));

        $this->administer()->sync($this->operator(), $billable);

        $this->assertCount(1, $this->stripe->requestsTo('get', '/v1/subscriptions/' . $subscription->stripe_id));

        // 1. The local Cashier row, as Cashier's own update handler leaves it.
        $subscription->refresh();
        $this->assertSame('price_business', $subscription->stripe_price);
        $this->assertSame(3, $subscription->quantity);
        $this->assertSame('active', $subscription->stripe_status);
        $this->assertNull($subscription->ends_at);

        // 2. The record, now Stripe's.
        $billable->refresh();
        $this->assertSame('business', $billable->getAttribute('plan'));
        $this->assertSame(PlanStatus::ACTIVE->value, $billable->getAttribute('plan_status'));
        $this->assertSame(BillingProvider::STRIPE->value, $billable->getAttribute('plan_provider'));
        $this->assertSame('price_business', $billable->getAttribute('plan_product_id'));
        $this->assertTrue((bool) $billable->getAttribute('plan_renews'));

        $applied = $this->eventsOf(BillingEventType::ENTITLEMENT_APPLIED);
        $this->assertCount(1, $applied);
        $this->assertSame(BillingSource::ADMIN, $applied[0]->source);

        $synced = $this->eventsOf(BillingEventType::ENTITLEMENT_SYNCED);
        $this->assertCount(1, $synced);
        $this->assertSame(BillingSource::ADMIN, $synced[0]->source);
        $this->assertSame(BillingProvider::STRIPE, $synced[0]->provider);
        $this->assertSame('stripe', $synced[0]->properties['rail']);
        $this->assertTrue($synced[0]->properties['changed']);
    }

    /**
     * The local items missed a swap as the row did: the live read replaces
     * the stale item, as Cashier's update handler would have.
     */
    public function test_sync_replaces_a_subscription_item_the_local_row_missed_a_swap_for(): void
    {
        $billable = $this->makeBillable();
        $subscription = $this->makeSubscription($billable, priceId: 'price_pro');

        SubscriptionItem::query()->forceCreate([
            'subscription_id' => $subscription->getKey(),
            'stripe_id' => 'si_stale',
            'stripe_product' => 'prod_pro',
            'stripe_price' => 'price_pro',
            'quantity' => 1,
        ]);

        $this->stripe->answer($this->stripeSubscription($subscription, [
            'items' => $this->stripeItems('price_business', $this->now()->addMonth(), quantity: 3),
        ]));

        $this->administer()->sync($this->operator(), $billable);

        $items = SubscriptionItem::query()->where('subscription_id', $subscription->getKey())->get();
        $this->assertCount(1, $items);
        $this->assertSame('si_price_business', $items[0]->stripe_id);
        $this->assertSame('prod_price_business', $items[0]->stripe_product);
        $this->assertSame('price_business', $items[0]->stripe_price);
        $this->assertSame(3, $items[0]->quantity);
    }

    /**
     * The claim is stamped when the read starts: a webhook that lands while
     * the read is in flight carries a newer word than the object read, and
     * has to win over it.
     */
    public function test_a_webhook_landing_during_the_live_read_wins_over_the_sync(): void
    {
        $billable = $this->makeBillable([
            'plan' => 'pro',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::STRIPE->value,
            'plan_product_id' => 'price_pro',
            'plan_source_event_at' => $this->now()->subDay(),
        ]);
        $subscription = $this->makeSubscription($billable, priceId: 'price_pro');
        $webhookAt = $this->now()->addSeconds(4);

        $this->stripe->answer(
            $this->stripeSubscription($subscription, [
                'items' => $this->stripeItems('price_business', $this->now()->addMonth()),
            ]),
            during: function () use ($billable, $webhookAt): void {
                $this->travel(5)->seconds();

                StripePayer::query()->whereKey($billable->getKey())->update([
                    'plan_source_event_at' => $webhookAt,
                ]);
            },
        );

        $this->administer()->sync($this->operator(), $billable);

        $billable->refresh();
        $this->assertSame('pro', $billable->getAttribute('plan'));
        $this->assertSame(
            $webhookAt->getTimestamp(),
            CarbonImmutable::parse((string) $billable->getAttribute('plan_source_event_at'))->getTimestamp(),
        );
    }

    /**
     * A database fault is a defect to surface, not the store rail failing:
     * it propagates rather than becoming a recorded `rail_error`.
     */
    public function test_a_database_fault_during_a_store_sync_propagates(): void
    {
        config(['magic-starter.billing.revenuecat.secret_api_key' => 'sk_test_revenuecat_secret']);

        $billable = $this->makeBillable([
            'stripe_id' => null,
            'plan' => 'pro',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::APP_STORE->value,
            'plan_source_event_at' => $this->now()->subDay(),
        ]);

        Http::fake([
            '*' => Http::response($this->subscriber([
                'starter_business_monthly' => $this->storeSubscription(),
            ])),
        ]);

        $this->app->bind(WritesEntitlement::class, fn (): WritesEntitlement => new class implements WritesEntitlement
        {
            public function write(EntitlementWrite $write): bool
            {
                throw new QueryException('testing', 'update "users"', [], new PDOException('database is locked'));
            }
        });

        try {
            $this->administer()->sync($this->operator(), $billable);
            $this->fail('The database fault was swallowed.');
        } catch (QueryException $fault) {
            $this->assertSame('database is locked', $fault->getPrevious()?->getMessage());
        }

        $this->assertSame([], $this->eventsOf(BillingEventType::REQUEST_REFUSED));
    }

    public function test_sync_mirrors_a_cancellation_scheduled_on_stripe(): void
    {
        $billable = $this->makeBillable();
        $subscription = $this->makeSubscription($billable, priceId: 'price_pro');
        $periodEnd = $this->now()->addDays(12);

        $this->stripe->answer($this->stripeSubscription($subscription, [
            'cancel_at_period_end' => true,
            'items' => $this->stripeItems('price_pro', $periodEnd),
        ]));

        $this->administer()->sync($this->operator(), $billable);

        $this->assertSame($periodEnd->getTimestamp(), $subscription->refresh()->ends_at?->getTimestamp());

        $billable->refresh();
        $this->assertSame('pro', $billable->getAttribute('plan'));
        $this->assertFalse((bool) $billable->getAttribute('plan_renews'));
    }

    /**
     * A granting subscription on a price the catalogue does not map is a
     * config gap, never a downgrade: nothing is written, the row included.
     */
    public function test_sync_of_an_unmapped_price_is_refused_and_rolls_the_row_back(): void
    {
        $billable = $this->makeBillable([
            'plan' => 'pro',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::STRIPE->value,
            'plan_source_event_at' => $this->now()->subDay(),
        ]);
        $subscription = $this->makeSubscription($billable, priceId: 'price_pro');

        $this->stripe->answer($this->stripeSubscription($subscription, [
            'items' => $this->stripeItems('price_unlisted', $this->now()->addMonth()),
        ]));

        $this->assertRefused('unmapped_price', fn () => $this->administer()->sync($this->operator(), $billable));

        $this->assertSame('price_pro', $subscription->refresh()->stripe_price);
        $this->assertSame('pro', $billable->refresh()->getAttribute('plan'));
        $this->assertSame([], $this->eventsOf(BillingEventType::ENTITLEMENT_SYNCED));
        $this->assertCount(1, $this->eventsOf(BillingEventType::REQUEST_REFUSED));
    }

    public function test_a_failed_live_read_is_a_recorded_rail_error_and_writes_nothing(): void
    {
        $billable = $this->makeBillable();
        $subscription = $this->makeSubscription($billable, priceId: 'price_pro');

        $this->stripe->answer([
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'No such subscription.',
            ],
        ], 404);

        $this->assertRefused('rail_error', fn () => $this->administer()->sync($this->operator(), $billable));

        $this->assertSame('price_pro', $subscription->refresh()->stripe_price);
        $this->assertNull($billable->refresh()->getAttribute('plan_provider'));
        $this->assertSame([], $this->eventsOf(BillingEventType::ENTITLEMENT_SYNCED));
        $this->assertSame('sync', $this->eventsOf(BillingEventType::REQUEST_REFUSED)[0]->properties['operation']);
    }

    public function test_sync_rereads_a_store_record_when_the_store_rail_is_configured(): void
    {
        config(['magic-starter.billing.revenuecat.secret_api_key' => 'sk_test_revenuecat_secret']);

        $billable = $this->makeBillable([
            'stripe_id' => null,
            'plan' => 'pro',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::APP_STORE->value,
            'plan_source_event_at' => $this->now()->subDay(),
        ]);

        Http::fake([
            '*' => Http::response($this->subscriber([
                'starter_business_monthly' => $this->storeSubscription(),
            ])),
        ]);

        $this->administer()->sync($this->operator(), $billable);

        $this->assertSame([], $this->stripe->requests);
        $this->assertSame('business', $billable->refresh()->getAttribute('plan'));

        $synced = $this->eventsOf(BillingEventType::ENTITLEMENT_SYNCED);
        $this->assertCount(1, $synced);
        $this->assertSame('store', $synced[0]->properties['rail']);
        $this->assertSame(BillingProvider::APP_STORE, $synced[0]->provider);
        $this->assertTrue($synced[0]->properties['changed']);
    }

    public function test_a_failed_store_reread_is_a_recorded_rail_error(): void
    {
        config(['magic-starter.billing.revenuecat.secret_api_key' => 'sk_test_revenuecat_secret']);

        $billable = $this->makeBillable([
            'stripe_id' => null,
            'plan' => 'pro',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::APP_STORE->value,
        ]);

        Http::fake(['*' => Http::response(['message' => 'unavailable'], 503)]);

        $this->assertRefused('rail_error', fn () => $this->administer()->sync($this->operator(), $billable));

        $this->assertSame('pro', $billable->refresh()->getAttribute('plan'));
        $this->assertSame(BillingProvider::APP_STORE, $this->eventsOf(BillingEventType::REQUEST_REFUSED)[0]->provider);
    }

    public function test_sync_with_no_rail_to_read_is_refused(): void
    {
        $billable = $this->makeBillable([
            'plan' => 'pro',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::APP_STORE->value,
        ]);

        $this->assertRefused('nothing_to_sync', fn () => $this->administer()->sync($this->operator(), $billable));

        $this->assertSame([], $this->stripe->requests);
    }

    // -------------------------------------------------------------------------
    // Revoke of a sandbox-only store record
    // -------------------------------------------------------------------------

    /**
     * A Stripe payer behind a delisted sandbox store record: once the store
     * record is revoked, the card that is still billing has to be back on
     * record rather than leave the payer on nothing.
     */
    public function test_revoking_a_sandbox_only_store_record_reprojects_a_stripe_payer(): void
    {
        config(['magic-starter.billing.revenuecat.secret_api_key' => 'sk_test_revenuecat_secret']);

        $billable = $this->makeBillable([
            'plan' => 'business',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::APP_STORE->value,
            'plan_source_event_at' => $this->now()->subDay(),
        ]);
        $this->makeSubscription($billable, priceId: 'price_pro');

        Http::fake([
            '*' => Http::response($this->subscriber([
                'starter_business_monthly' => $this->storeSubscription(['is_sandbox' => true]),
            ])),
        ]);

        $this->administer()->revoke($this->operator(), $billable, 'Sandbox tester');

        $billable->refresh();
        $this->assertSame('pro', $billable->getAttribute('plan'));
        $this->assertSame(PlanStatus::ACTIVE->value, $billable->getAttribute('plan_status'));
        $this->assertSame(BillingProvider::STRIPE->value, $billable->getAttribute('plan_provider'));
        $this->assertCount(1, $this->eventsOf(BillingEventType::GRANT_REVOKED));
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

    private function operator(): StripePayer
    {
        return $this->makeBillable(['name' => 'Operator', 'stripe_id' => null]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeBillable(array $attributes = []): StripePayer
    {
        $billable = new StripePayer;

        $billable->forceFill([
            'name' => 'Payer',
            'email' => 'payer-' . Str::random(10) . '@example.test',
            'password' => 'secret',
            'stripe_id' => 'cus_payer',
            ...$attributes,
        ])->save();

        return $billable;
    }

    private function makeSubscription(
        StripePayer $billable,
        string $status = 'active',
        string $priceId = 'price_pro',
        ?CarbonImmutable $trialEndsAt = null,
        ?CarbonImmutable $endsAt = null,
    ): Subscription {
        $subscription = new Subscription;

        $subscription->forceFill([
            'user_id' => $billable->getKey(),
            'type' => 'default',
            'stripe_id' => 'sub_' . Str::random(10),
            'stripe_status' => $status,
            'stripe_price' => $priceId,
            'quantity' => 1,
            'trial_ends_at' => $trialEndsAt,
            'ends_at' => $endsAt,
        ])->save();

        return $subscription;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function stripeSubscription(Subscription $subscription, array $overrides = []): array
    {
        return [
            'id' => $subscription->stripe_id,
            'object' => 'subscription',
            'customer' => 'cus_payer',
            'status' => 'active',
            'cancel_at_period_end' => false,
            'cancel_at' => null,
            'canceled_at' => null,
            'trial_end' => null,
            'metadata' => ['type' => 'default'],
            'items' => $this->stripeItems('price_pro', $this->now()->addMonth()),
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stripeItems(string $priceId, CarbonImmutable $periodEnd, int $quantity = 1): array
    {
        return [
            'object' => 'list',
            'data' => [
                [
                    'id' => 'si_' . $priceId,
                    'object' => 'subscription_item',
                    'quantity' => $quantity,
                    'current_period_end' => $periodEnd->getTimestamp(),
                    'price' => [
                        'id' => $priceId,
                        'object' => 'price',
                        'product' => 'prod_' . $priceId,
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $invoices
     * @return array<string, mixed>
     */
    private function invoiceList(array $invoices): array
    {
        return [
            'object' => 'list',
            'url' => '/v1/invoices',
            'has_more' => false,
            'data' => $invoices,
        ];
    }

    /**
     * An invoice as API 2026-08-26.dahlia shapes it: the subscription that
     * billed it sits under `parent.subscription_details`, and a one-off invoice
     * has no parent.
     *
     * @return array<string, mixed>
     */
    private function invoice(string $id, int $amountPaid, ?string $subscriptionId = null): array
    {
        return [
            'id' => $id,
            'object' => 'invoice',
            'customer' => 'cus_payer',
            'status' => 'paid',
            'amount_paid' => $amountPaid,
            'currency' => 'usd',
            'created' => $this->now()->getTimestamp(),
            'parent' => $subscriptionId === null ? null : [
                'type' => 'subscription_details',
                'quote_details' => null,
                'subscription_details' => [
                    'metadata' => null,
                    'subscription' => $subscriptionId,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function refund(string $id, int $amount, string $currency = 'usd'): array
    {
        return [
            'id' => $id,
            'object' => 'refund',
            'amount' => $amount,
            'currency' => $currency,
            'status' => 'succeeded',
        ];
    }

    /**
     * A payment intent as the expanded invoice payment list carries it, with
     * its latest charge.
     *
     * @return array<string, mixed>
     */
    private function expandedIntent(string $id, bool $refunded, int $amountRefunded = 0): array
    {
        return [
            'id' => $id,
            'object' => 'payment_intent',
            'amount' => 2900,
            'latest_charge' => [
                'id' => 'ch_' . $id,
                'object' => 'charge',
                'amount' => 2900,
                'amount_refunded' => $refunded ? 2900 : $amountRefunded,
                'refunded' => $refunded,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $payments
     * @return array<string, mixed>
     */
    private function paymentList(array $payments): array
    {
        return [
            'object' => 'list',
            'url' => '/v1/invoice_payments',
            'has_more' => false,
            'data' => $payments,
        ];
    }

    /**
     * @param  string|array<string, mixed>|null  $paymentIntent  an id, or the expanded object
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function invoicePayment(string|array|null $paymentIntent, array $overrides = []): array
    {
        return [
            'id' => 'inpay_' . Str::random(6),
            'object' => 'invoice_payment',
            'status' => 'paid',
            'amount_paid' => 2900,
            'currency' => 'usd',
            'payment' => [
                'type' => 'payment_intent',
                'payment_intent' => $paymentIntent,
            ],
            ...$overrides,
        ];
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
 * The billable, carrying Cashier's trait. Its foreign key is pinned to
 * `user_id`, the column the package's subscriptions migration creates, since
 * the class basename would otherwise derive another.
 */
class StripePayer extends ConcreteUser
{
    use Billable;

    protected $table = 'users';

    public function getForeignKey(): string
    {
        return 'user_id';
    }
}
