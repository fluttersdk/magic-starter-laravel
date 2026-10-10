<?php

namespace FlutterSdk\MagicStarter\Support;

use FlutterSdk\MagicStarter\Jobs\CheckTrialCard;
use Laravel\Cashier\Cashier;
use Stripe\Exception\InvalidRequestException;
use Stripe\PaymentMethod;
use Stripe\StripeClient;
use Stripe\Subscription as StripeSubscription;

/**
 * The two Stripe calls the trial card check makes, and nothing else.
 *
 * A seam rather than inline calls in {@see CheckTrialCard}: the job's rules
 * (earliest wins, refuse before cancel, keep a converted subscription) are what
 * its tests are about, and they replace this class through the container to
 * reach them. The real calls are pinned by driving this class against a stubbed
 * transport, so what Stripe receives is certified in one place.
 *
 * Every call goes through `Cashier::stripe()`, so the key, the pinned API
 * version and the base URL are the ones the rest of the Stripe rail uses.
 *
 * Resolved through the container (bound in the service provider), so an
 * application with its own Stripe wrapper binds a subclass over it.
 */
class TrialCardGateway
{
    /**
     * The answer for a payment method that exists and will never yield a card
     * fingerprint: a method that is not a card (SEPA, Link, a bank debit), or a
     * card Stripe reports without one.
     *
     * Distinct from null, which means "no payment method yet". The two lead to
     * opposite decisions: null is worth asking again once Checkout has finished
     * attaching the card, while this answer is final, so the job keeps the trial
     * at once rather than retrying for a fingerprint that cannot arrive.
     */
    public const NO_CARD = false;

    /**
     * The card fingerprint behind a subscription.
     *
     * Three answers, and the caller acts on each differently:
     *
     * - a string: the card's `fingerprint`, the same for every copy of one
     *   physical card across customers, which is what makes it the anti-abuse
     *   key;
     * - null: no payment method is attached yet, on the subscription or as the
     *   customer's invoice default, which a check racing Checkout can see;
     * - {@see self::NO_CARD}: a payment method is attached and has no card
     *   fingerprint to compare, now or later.
     *
     * The subscription's own `default_payment_method` is read first, expanded
     * so the card arrives in the same round trip; Checkout in subscription mode
     * sets it. The customer's `invoice_settings.default_payment_method` is the
     * fallback, expanded the same way, because that is what Stripe charges when
     * the subscription names none.
     *
     * @param  string  $subscriptionId  Stripe's `sub_...` id.
     *
     * @throws \Stripe\Exception\ApiErrorException When Stripe cannot be read; the
     *                                             job's retry is what answers it.
     */
    public function fingerprintFor(string $subscriptionId): string|false|null
    {
        $stripe = $this->stripe();

        // 1. The subscription's own default, with the payment method expanded.
        $subscription = $stripe->subscriptions->retrieve($subscriptionId, [
            'expand' => [
                'default_payment_method',
            ],
        ]);

        $paymentMethod = $subscription->default_payment_method;

        // 2. The customer's invoice default, which Stripe charges when the
        //    subscription names no method of its own.
        if ($paymentMethod === null) {
            $paymentMethod = $this->customerDefaultPaymentMethod($stripe, $subscription);
        }

        if (! $paymentMethod instanceof PaymentMethod) {
            return null;
        }

        // 3. Only a card carries a card fingerprint.
        if ($paymentMethod->type !== 'card') {
            return self::NO_CARD;
        }

        $fingerprint = $paymentMethod->card->fingerprint ?? null;

        return is_string($fingerprint) && $fingerprint !== '' ? $fingerprint : self::NO_CARD;
    }

    /**
     * Cancel a subscription now, with no proration and no final invoice.
     *
     * Never Cashier's `cancelNow()`: it prorates by default, which on a trial
     * credits or invoices a customer who was told nothing was charged.
     *
     * A subscription Stripe has already canceled is a cancel that is done. A
     * refused DELETE is therefore followed by one retrieve, and a `canceled` (or
     * `incomplete_expired`) status ends the call quietly; any other status means
     * the refusal was about something else and it is raised, because a failed
     * cancel read as a done one would let the refused trial run on.
     *
     * @param  string  $subscriptionId  Stripe's `sub_...` id.
     *
     * @throws InvalidRequestException When Stripe refuses a subscription that is still live.
     * @throws \Stripe\Exception\ApiErrorException When Stripe cannot be reached.
     */
    public function cancel(string $subscriptionId): void
    {
        $stripe = $this->stripe();

        try {
            $stripe->subscriptions->cancel($subscriptionId, [
                'prorate' => false,
                'invoice_now' => false,
            ]);
        } catch (InvalidRequestException $refusal) {
            $status = $stripe->subscriptions->retrieve($subscriptionId)->status;

            $ended = [
                StripeSubscription::STATUS_CANCELED,
                StripeSubscription::STATUS_INCOMPLETE_EXPIRED,
            ];

            if (in_array($status, $ended, true)) {
                return;
            }

            throw $refusal;
        }
    }

    /**
     * The customer's invoice default payment method, expanded, or null.
     */
    protected function customerDefaultPaymentMethod(StripeClient $stripe, StripeSubscription $subscription): mixed
    {
        $customer = $subscription->customer;
        $customerId = is_string($customer) ? $customer : $customer->id;

        $customer = $stripe->customers->retrieve($customerId, [
            'expand' => [
                'invoice_settings.default_payment_method',
            ],
        ]);

        return $customer->invoice_settings->default_payment_method ?? null;
    }

    /**
     * The Stripe client the rest of the rail uses.
     */
    protected function stripe(): StripeClient
    {
        return Cashier::stripe();
    }
}
