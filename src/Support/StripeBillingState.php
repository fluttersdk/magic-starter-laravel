<?php

namespace FlutterSdk\MagicStarter\Support;

use Carbon\CarbonInterface;
use FlutterSdk\MagicStarter\Contracts\AdministersBilling;
use FlutterSdk\MagicStarter\Http\Controllers\BillingController;
use Illuminate\Database\Eloquent\Model;
use Laravel\Cashier\Invoice;
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
     * The target is the NEWEST paid invoice that moved money, because a trial
     * customer's newest paid invoice is the $0 one the trial opened with. It is
     * that invoice or nothing: when its payment is not a paid payment intent (a
     * payment recorded out of band has nothing a refund can be created against),
     * an older invoice is not "the last one" and is never refunded instead.
     * Only that one invoice's payments are read, since every read is a request.
     * `amount` is the invoice's `amount_paid`, in the currency's minor unit.
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

        foreach ($billable->invoices() as $invoice) {
            if (! $invoice instanceof Invoice || $invoice->rawAmountPaid() <= 0) {
                continue;
            }

            $paymentIntent = self::paidPaymentIntent($invoice);

            if ($paymentIntent === null) {
                return null;
            }

            $stripeInvoice = $invoice->asStripeInvoice();

            return [
                'invoice_id' => $stripeInvoice->id,
                'payment_intent' => $paymentIntent,
                'amount' => $stripeInvoice->amount_paid,
                'currency' => $stripeInvoice->currency,
            ];
        }

        return null;
    }

    /**
     * The id of the paid payment intent behind an invoice, or null.
     *
     * @throws ApiErrorException
     */
    private static function paidPaymentIntent(Invoice $invoice): ?string
    {
        foreach ($invoice->payments() as $payment) {
            if (! $payment->isCompleted()) {
                continue;
            }

            $method = $payment->asStripeInvoicePayment()->payment;

            if ($method->type !== 'payment_intent') {
                continue;
            }

            // Expanded to a PaymentIntent object when the caller asked for it.
            $intent = $method->payment_intent ?? null;
            $id = $intent instanceof PaymentIntent ? $intent->id : $intent;

            if (is_string($id) && $id !== '') {
                return $id;
            }
        }

        return null;
    }
}
