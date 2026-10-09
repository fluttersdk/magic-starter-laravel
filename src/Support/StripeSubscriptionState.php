<?php

namespace FlutterSdk\MagicStarter\Support;

use FlutterSdk\MagicStarter\Enums\PlanStatus;
use FlutterSdk\MagicStarter\Enums\ProductType;

/**
 * Stripe's own subscription vocabulary, read the same way by every feeder.
 *
 * Two classes used to carry all of this privately: the webhook controller, which
 * reacts to an event, and the hourly reconciler, which re-reads the same
 * subscription when an event was dropped. Their `$grantingStatuses` arrays and
 * their `mapStatus()` matches were byte-identical, and the reconciler's docblock
 * even CITED the controller's array as the list it was kept in step with, which
 * is a claim no code could enforce. Adding `paused` to one of them would have
 * left the other revoking every paused subscription on its next hourly run.
 *
 * The price lookups had already drifted, which is the concrete evidence that the
 * comment was not enough: the controller guarded with `! $priceId` and the
 * reconciler with an explicit null-or-empty check. Those differ on the string
 * `'0'`, which PHP treats as falsy. No Stripe price id looks like that today, so
 * nothing was broken; two copies of one rule disagreeing about an edge case is
 * how they always start.
 *
 * Kept in STRIPE's vocabulary rather than re-derived from
 * {@see PlanStatus::grants()}. That method answers a neutral question for every
 * rail; this class answers what Stripe means, and collapsing the two would make
 * a change for one rail silently change the other.
 */
final class StripeSubscriptionState
{
    /**
     * The Cashier subscription TYPE this package's Stripe rail acts on.
     *
     * Cashier's named types are an adopter-facing feature and a subject may
     * legitimately hold several, so every feeder here has to agree on which one
     * it means: the checkout guard refuses on it, the revocation guard holds a
     * tier open for it, the reconciler resolves it, and `swap` and `cancel` both
     * reach it through `subscription()`. They agreed by having the same literal
     * written out in three files, with comments in each arguing that the three
     * must match, which is the arrangement this class exists to end (see the
     * class docblock: the same thing happened to the granting-status list).
     *
     * Untyped like every other constant here, because the floor is PHP 8.2.
     */
    public const SUBSCRIPTION_TYPE = 'default';

    /**
     * The Stripe statuses under which a subscription still entitles the billable.
     *
     * `past_due` grants on purpose: Stripe is still retrying the card, the
     * customer has not cancelled, and taking their tier away mid-dunning is a
     * support ticket from somebody who is about to pay.
     *
     * @var array<int, string>
     */
    public const GRANTING_STATUSES = [
        'active',
        'trialing',
        'past_due',
    ];

    /**
     * Whether [$status] is one Stripe grants an entitlement under.
     */
    public static function grants(string $status): bool
    {
        return in_array($status, self::GRANTING_STATUSES, true);
    }

    /**
     * Map Stripe's subscription status onto the rail-neutral vocabulary.
     *
     * An explicit table rather than {@see PlanStatus::fromWire()} alone, because
     * three of Stripe's words have no neutral twin: `unpaid` and both
     * `incomplete*` states are lifecycles that ran out without ever being paid,
     * so they land on Expired rather than on a status of their own.
     *
     * Everything unlisted falls THROUGH to `fromWire()`, which lands an
     * unrecognised word on the non-granting default: a status Stripe adds next
     * year must never be able to entitle by accident. Nothing maps onto `active`
     * except the word `active` itself.
     */
    public static function planStatusFor(string $status): PlanStatus
    {
        return match ($status) {
            'active' => PlanStatus::ACTIVE,
            'trialing' => PlanStatus::TRIALING,
            'past_due' => PlanStatus::PAST_DUE,
            'canceled' => PlanStatus::CANCELED,
            'unpaid', 'incomplete', 'incomplete_expired' => PlanStatus::EXPIRED,
            default => PlanStatus::fromWire($status),
        };
    }

    /**
     * The catalogue tier a Stripe price id maps to, or null when none does.
     *
     * Null is a config gap and never a downgrade: a caller that cannot name the
     * tier leaves the entitlement alone and warns, because an unmapped price on
     * a paying subscription means somebody added a price in Stripe and not as a
     * `refs.stripe_price` in `magic-starter.billing.products`.
     *
     * The tier travels as a plain string because the package ships no tier
     * vocabulary; the consuming application owns those words, and its catalogue
     * is where it says which Stripe price sells which of them.
     *
     * The empty check is explicit rather than `! $priceId`, which is the form
     * one of the two copies used: they differ on `'0'`.
     *
     * Only a subscription names a tier here. Nothing refuses a `tier` written on
     * a one-off product, and reading it would let a credit pack's price move a
     * subscriber onto a paid tier.
     */
    public static function planForPrice(?string $priceId): ?string
    {
        if ($priceId === null || $priceId === '') {
            return null;
        }

        $product = BillingCatalogue::productForStripePrice($priceId);

        return $product !== null && $product['type'] === ProductType::SUBSCRIPTION
            ? $product['tier']
            : null;
    }
}
