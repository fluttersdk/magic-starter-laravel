<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\ReleasesUserBilling;
use FlutterSdk\MagicStarter\Enums\BillingProvider;
use FlutterSdk\MagicStarter\Support\BillingLog;
use FlutterSdk\MagicStarter\Support\BillingReleaseFailed;
use FlutterSdk\MagicStarter\Support\ReadsBillableAttributes;
use FlutterSdk\MagicStarter\Support\TrialCardGateway;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Laravel\Cashier\Subscription;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Subscription as StripeSubscription;

/**
 * Default release of a user's billing: end the card rail's subscriptions now,
 * and name the store's, which this application cannot touch.
 *
 * The card cancel is {@see TrialCardGateway::cancel()}, so it neither prorates
 * nor sends a final invoice, and never goes through Cashier's `cancelNow()`,
 * which prorates by default. The remaining period is not refunded: the person
 * asked to leave, and a credit note would be a refund nobody offered.
 *
 * Everything Stripe could still bill or activate is cancelled, which is wider
 * than what holds a deletion in refuse mode: a subscription cancelled at the end
 * of its period, a payment in dunning and a first payment still pending would
 * each outlive a deleted account. The local Cashier row is ended in the same
 * breath, as Cashier's own `cancelNow()` does, instead of left valid until the
 * `customer.subscription.deleted` webhook lands: the purge and the deleter hold
 * an account while a card subscription is valid, and an immediate deletion's
 * purge would otherwise be held by the very subscription its request cancelled.
 * The webhook then finds the row ended and revokes the entitlement as usual.
 *
 * Only the user's own subscriptions are released, and only when the user is the
 * configured billable subject. A team a subscription bills is the team owner's
 * to settle, as it is without this action.
 */
class ReleaseUserBilling implements ReleasesUserBilling
{
    use ReadsBillableAttributes;

    /**
     * The code Stripe answers a retrieve or cancel of an id it has no record of.
     */
    private const STRIPE_MISSING = 'resource_missing';

    public function __construct(
        protected TrialCardGateway $stripe,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function release(Authenticatable $user): array
    {
        $release = [
            'cancelled' => [],
            'store' => [],
        ];

        // 1. Billing rows hang off a model. Whether this user is the configured
        //    billable (under team billing the money is on the teams, never on
        //    the user) is answered by each reader below.
        if (! $user instanceof Model) {
            return $release;
        }

        // 2. The card rail: this application is the merchant, so it ends them itself.
        foreach ($this->stripeSubscriptions($user)->filter($this->outlivesAccount(...)) as $subscription) {
            $release['cancelled'][] = $this->cancel($user, $subscription);
        }

        // 3. A store: only its customer can cancel there, so say which one.
        if (SubscriptionGuardedDeleteTeam::storeIsBilling($user)) {
            $release['store'][] = [
                'provider' => BillingProvider::fromWire($this->stringAttribute($user, 'plan_provider'))->value,
                'manage_url' => $this->stringAttribute($user, 'plan_manage_url'),
            ];
        }

        return $release;
    }

    /**
     * Whether Stripe could still bill or activate this subscription.
     *
     * Not ended, which is wider than Cashier's `valid()` (the predicate that
     * holds a deletion in refuse mode): a subscription in dunning or still
     * awaiting its first payment is not `valid()` and is still a charge or an
     * activation waiting to happen. `valid()` is kept beside it so no
     * subscription that holds a deletion in refuse mode escapes the release.
     */
    protected function outlivesAccount(Subscription $subscription): bool
    {
        return $subscription->valid() || ! $subscription->ended();
    }

    /**
     * End one subscription in Stripe and name it.
     *
     * A subscription Stripe has no record of is a cancel that is done: a stale
     * local row (deleted in the dashboard, a wiped test account) would otherwise
     * keep a person from ever leaving.
     *
     * @return array{provider: string, id: string}
     *
     * @throws BillingReleaseFailed When Stripe refuses or cannot be reached.
     */
    protected function cancel(Model $user, Subscription $subscription): array
    {
        $id = (string) $subscription->stripe_id;

        try {
            $this->stripe->cancel($id);
        } catch (ApiErrorException $failure) {
            if (! $this->isMissing($failure)) {
                BillingLog::error('A subscription could not be cancelled for an account deletion.', [
                    'reason' => 'account_deletion_cancel_failed',
                    'user_id' => $user->getKey(),
                    'subscription' => $id,
                    'exception' => $failure::class,
                    'message' => $failure->getMessage(),
                ]);

                throw new BillingReleaseFailed(
                    sprintf('Could not cancel subscription [%s] for user [%s].', $id, $user->getKey()),
                    previous: $failure,
                );
            }

            BillingLog::warning('Stripe has no record of a subscription released for an account deletion.', [
                'reason' => 'account_deletion_subscription_missing',
                'user_id' => $user->getKey(),
                'subscription' => $id,
            ]);
        }

        // Ended here too, once Stripe has agreed (or has no record to disagree).
        $subscription->forceFill([
            'stripe_status' => StripeSubscription::STATUS_CANCELED,
            'ends_at' => now(),
        ])->save();

        return [
            'provider' => BillingProvider::STRIPE->value,
            'id' => $id,
        ];
    }

    private function isMissing(ApiErrorException $failure): bool
    {
        return $failure instanceof InvalidRequestException && $failure->getStripeCode() === self::STRIPE_MISSING;
    }
}
