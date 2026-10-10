<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use FlutterSdk\MagicStarter\Support\TrialCardGateway;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Stripe\Exception\InvalidRequestException;

/**
 * Drives the REAL {@see TrialCardGateway} through Cashier's real Stripe client,
 * with only the HTTP transport stubbed ({@see StripeHttpStub}).
 *
 * The card job's tests replace the gateway, so they can only certify what the
 * job asked it. What Stripe actually receives is pinned here: the expansions
 * that make one round trip carry the card, the customer fallback a Checkout
 * subscription without its own default needs, and a cancel that neither
 * prorates nor invoices, which is the difference between refusing a free trial
 * and sending its holder a bill for it.
 */
class TrialCardGatewayTest extends TestCase
{
    private StripeHttpStub $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cashier.secret' => 'sk_test_trial_card_gateway',
        ]);

        $this->stripe = StripeHttpStub::install();
    }

    protected function tearDown(): void
    {
        StripeHttpStub::uninstall();

        parent::tearDown();
    }

    /**
     * The subscription's own default payment method answers in one request,
     * expanded so the card rides along instead of costing a second retrieve.
     */
    public function test_the_subscription_default_card_answers_its_fingerprint(): void
    {
        $this->stripe->answer($this->subscription(paymentMethod: $this->card('fp_subscription_card')));

        $answer = $this->gateway()->fingerprintFor('sub_trial');

        $this->assertSame('fp_subscription_card', $answer);

        $retrieves = $this->stripe->requestsTo('get', '/v1/subscriptions/sub_trial');
        $this->assertCount(1, $retrieves);
        $this->assertSame(['default_payment_method'], $retrieves[0]['params']['expand']);

        // No fallback was needed, so none was paid for.
        $this->assertSame([], $this->stripe->requestsTo('get', '/v1/customers/cus_trial'));
    }

    /**
     * A subscription without a default of its own falls back to the customer's
     * invoice default, expanded in the same request.
     */
    public function test_the_customer_invoice_default_is_the_fallback(): void
    {
        $this->stripe
            ->answer($this->subscription(paymentMethod: null))
            ->answer($this->customer(paymentMethod: $this->card('fp_customer_card')));

        $this->assertSame('fp_customer_card', $this->gateway()->fingerprintFor('sub_trial'));

        $lookups = $this->stripe->requestsTo('get', '/v1/customers/cus_trial');
        $this->assertCount(1, $lookups);
        $this->assertSame(['invoice_settings.default_payment_method'], $lookups[0]['params']['expand']);
    }

    /**
     * Neither place holds a payment method yet: null, the "ask again later"
     * answer, which is distinct from a method that will never have a card.
     */
    public function test_no_payment_method_anywhere_answers_null(): void
    {
        $this->stripe
            ->answer($this->subscription(paymentMethod: null))
            ->answer($this->customer(paymentMethod: null));

        $this->assertNull($this->gateway()->fingerprintFor('sub_trial'));
    }

    /**
     * A payment method that is not a card has no card fingerprint and never
     * will: the distinct "no card" answer, so the job keeps the trial instead
     * of waiting for a fingerprint that cannot arrive.
     */
    public function test_a_payment_method_that_is_not_a_card_answers_no_card(): void
    {
        $this->stripe->answer($this->subscription(paymentMethod: [
            'id' => 'pm_sepa',
            'object' => 'payment_method',
            'type' => 'sepa_debit',
        ]));

        $this->assertSame(TrialCardGateway::NO_CARD, $this->gateway()->fingerprintFor('sub_trial'));
    }

    /**
     * A card Stripe reports without a fingerprint is answered the same way: there
     * is nothing to compare, now or later.
     */
    public function test_a_card_without_a_fingerprint_answers_no_card(): void
    {
        $this->stripe->answer($this->subscription(paymentMethod: [
            'id' => 'pm_card_no_fingerprint',
            'object' => 'payment_method',
            'type' => 'card',
            'card' => [
                'brand' => 'visa',
            ],
        ]));

        $this->assertSame(TrialCardGateway::NO_CARD, $this->gateway()->fingerprintFor('sub_trial'));
    }

    /**
     * The cancel neither prorates nor invoices. Cashier's `cancelNow()` prorates
     * by default, which on a trial would put an invoice in front of somebody who
     * was told nothing was charged.
     */
    public function test_cancel_sends_a_delete_that_neither_prorates_nor_invoices(): void
    {
        $this->stripe->answer($this->subscription(paymentMethod: null, status: 'canceled'));

        $this->gateway()->cancel('sub_trial');

        $deletes = $this->stripe->requestsTo('delete', '/v1/subscriptions/sub_trial');
        $this->assertCount(1, $deletes);

        // The SDK encodes a boolean as the string Stripe reads off the wire.
        $this->assertSame('false', $deletes[0]['params']['prorate']);
        $this->assertSame('false', $deletes[0]['params']['invoice_now']);
    }

    /**
     * A subscription Stripe already canceled is a cancel that is done, so a job
     * retried after a cancel that landed does not fail on its own success.
     */
    public function test_an_already_canceled_subscription_is_treated_as_done(): void
    {
        $this->stripe
            ->answer($this->stripeError('This subscription has already been canceled.'), 400)
            ->answer($this->subscription(paymentMethod: null, status: 'canceled'));

        $this->gateway()->cancel('sub_trial');

        $this->assertCount(1, $this->stripe->requestsTo('delete', '/v1/subscriptions/sub_trial'));
        $this->assertCount(1, $this->stripe->requestsTo('get', '/v1/subscriptions/sub_trial'));
    }

    /**
     * The control on the test above: a refusal for a subscription that is still
     * live is NOT swallowed, or a failed cancel would read as a done one and the
     * trial would run on.
     */
    public function test_a_refused_cancel_of_a_live_subscription_still_raises(): void
    {
        $this->stripe
            ->answer($this->stripeError('Something else went wrong.'), 400)
            ->answer($this->subscription(paymentMethod: null, status: 'trialing'));

        $this->expectException(InvalidRequestException::class);

        $this->gateway()->cancel('sub_trial');
    }

    /**
     * The live status the job gates every cancel on is one plain retrieve,
     * with nothing expanded: the status is all it needs, and an expansion
     * would cost a heavier answer inside the lock.
     */
    public function test_status_reads_the_live_subscription_status_in_one_retrieve(): void
    {
        $this->stripe->answer($this->subscription(paymentMethod: null, status: 'past_due'));

        $this->assertSame('past_due', $this->gateway()->status('sub_trial'));

        $retrieves = $this->stripe->requestsTo('get', '/v1/subscriptions/sub_trial');
        $this->assertCount(1, $retrieves);
        $this->assertArrayNotHasKey('expand', $retrieves[0]['params']);
        $this->assertCount(1, $this->stripe->requests);
    }

    private function gateway(): TrialCardGateway
    {
        return $this->app->make(TrialCardGateway::class);
    }

    /**
     * @param  array<string, mixed>|null  $paymentMethod
     * @return array<string, mixed>
     */
    private function subscription(?array $paymentMethod, string $status = 'trialing'): array
    {
        return [
            'id' => 'sub_trial',
            'object' => 'subscription',
            'customer' => 'cus_trial',
            'status' => $status,
            'default_payment_method' => $paymentMethod,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $paymentMethod
     * @return array<string, mixed>
     */
    private function customer(?array $paymentMethod): array
    {
        return [
            'id' => 'cus_trial',
            'object' => 'customer',
            'invoice_settings' => [
                'default_payment_method' => $paymentMethod,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function card(string $fingerprint): array
    {
        return [
            'id' => 'pm_' . $fingerprint,
            'object' => 'payment_method',
            'type' => 'card',
            'card' => [
                'brand' => 'visa',
                'fingerprint' => $fingerprint,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stripeError(string $message): array
    {
        return [
            'error' => [
                'type' => 'invalid_request_error',
                'message' => $message,
            ],
        ];
    }
}
