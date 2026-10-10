<?php

namespace FlutterSdk\MagicStarter\Support;

use Carbon\CarbonInterface;
use FlutterSdk\MagicStarter\Contracts\AdministersBilling;
use FlutterSdk\MagicStarter\Http\Controllers\BillingController;
use Illuminate\Database\Eloquent\Model;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Invoice;
use Stripe\Charge;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;

/**
 * What a billable's card rail holds, read the same way by the customer's
 * billing endpoints ({@see BillingController}) and the operator's
 * ({@see AdministersBilling}).
 *
 * Every reader is guarded on the Cashier method it calls, because applying
 * Cashier's `Billable` trait and choosing the subscription model are the
 * consuming application's decisions: an absent method answers null, the same
 * "there is no card rail here" a store-only application really has.
 */
final class StripeBillingState
{
    /**
     * The billable's `default` Cashier subscription, or null when it has none
     * or carries no Cashier trait.
     */
    public static function defaultSubscription(Model $billable): ?Model
    {
        if (! method_exists($billable, 'subscription')) {
            return null;
        }

        $subscription = $billable->subscription(StripeSubscriptionState::SUBSCRIPTION_TYPE);

        return $subscription instanceof Model ? $subscription : null;
    }

    /**
     * When the subscription's current paid period ends, read live from the rail.
     *
     * A rail retrieval, not a column read: Cashier resolves the period per
     * subscription ITEM, so there is no local column to read it from.
     *
     * @throws ApiErrorException
     */
    public static function periodEnd(Model $subscription): ?CarbonInterface
    {
        if (! method_exists($subscription, 'currentPeriodEnd')) {
            return null;
        }

        $end = $subscription->currentPeriodEnd();

        return $end instanceof CarbonInterface ? $end : null;
    }

    /**
     * The payment a refund of the billable's last invoice would return, or null
     * when there is none.
     *
     * The target is the NEWEST paid invoice of the local `default` subscription
     * that moved money: a one-off invoice, or one of another subscription, is
     * never "the last invoice" of the plan the operator is looking at, and a
     * trial customer's newest paid invoice is the $0 one the trial opened with.
     * It is that invoice or nothing: when it holds no paid payment intent a
     * refund can still be created against (a payment recorded out of band, or
     * one whose charge is already refunded in full), an older invoice is not
     * "the last one" and is never refunded instead. Only that one invoice's
     * payments are read, since every read is a request.
     *
     * `amount` and `currency` are the chosen payment's own, in the currency's
     * minor unit: an invoice paid in two payments refunds only the one named.
     *
     * The invoice's subscription is read from `parent.subscription_details`
     * (Cashier's `Invoice::subscriptionId()`), where API 2026-08-26.dahlia
     * puts it; the invoice object no longer carries a top-level `subscription`.
     *
     * @return array{invoice_id: string, payment_intent: string, amount: int, currency: string}|null
     *
     * @throws ApiErrorException
     */
    public static function latestRefundablePayment(Model $billable): ?array
    {
        if (! method_exists($billable, 'invoices')
            || ! method_exists($billable, 'hasStripeId')
            || ! $billable->hasStripeId()
        ) {
            return null;
        }

        $subscriptionId = self::defaultSubscription($billable)?->getAttribute('stripe_id');

        if (! is_string($subscriptionId) || $subscriptionId === '') {
            return null;
        }

        foreach ($billable->invoices() as $invoice) {
            if (! $invoice instanceof Invoice
                || $invoice->subscriptionId() !== $subscriptionId
                || $invoice->rawAmountPaid() <= 0
            ) {
                continue;
            }

            $invoiceId = $invoice->asStripeInvoice()->id;
            $payment = self::refundablePaymentOf($invoiceId);

            return $payment === null ? null : [
                'invoice_id' => $invoiceId,
                ...$payment,
            ];
        }

        return null;
    }

    /**
     * The first paid payment intent behind an invoice that a refund can still
     * return money from, with that payment's own amount, or null.
     *
     * The payment intent's latest charge is expanded on this one list read, so
     * a charge already refunded in full is skipped without another request.
     *
     * @return array{payment_intent: string, amount: int, currency: string}|null
     *
     * @throws ApiErrorException
     */
    private static function refundablePaymentOf(string $invoiceId): ?array
    {
        $payments = Cashier::stripe()->invoicePayments->all([
            'invoice' => $invoiceId,
            'expand' => [
                'data.payment.payment_intent.latest_charge',
            ],
        ]);

        foreach ($payments->data as $payment) {
            if ($payment->status !== 'paid' || $payment->payment->type !== 'payment_intent') {
                continue;
            }

            // An id, or the PaymentIntent object the expansion asked for.
            $intent = $payment->payment->payment_intent ?? null;
            $id = $intent instanceof PaymentIntent ? $intent->id : $intent;

            if (! is_string($id) || $id === '' || self::isFullyRefunded($intent)) {
                continue;
            }

            return [
                'payment_intent' => $id,
                'amount' => (int) $payment->amount_paid,
                'currency' => $payment->currency,
            ];
        }

        return null;
    }

    /**
     * Whether the payment intent's latest charge is refunded in full, as far as
     * the expanded object says. An unexpanded intent says nothing, and Stripe
     * refuses a refund of a refunded charge on its own.
     */
    private static function isFullyRefunded(mixed $intent): bool
    {
        $charge = $intent instanceof PaymentIntent ? ($intent->latest_charge ?? null) : null;

        if (! $charge instanceof Charge) {
            return false;
        }

        return ($charge->refunded ?? false) === true
            || (int) ($charge->amount_refunded ?? 0) >= (int) ($charge->amount ?? PHP_INT_MAX);
    }
}
