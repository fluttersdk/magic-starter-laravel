<?php

namespace FlutterSdk\MagicStarter\Support;

use FlutterSdk\MagicStarter\Enums\PlanStatus;

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
     * The two billing cycles a price can be charged on.
     *
     * The words match `magic_payments`' `BillingCycle` on the Dart side, which
     * is what lets the client send one and read one back without a translation
     * table in between. Two members and no more: an interval this package
     * cannot name is refused rather than defaulted, because every default here
     * is a statement about what somebody is being charged.
     */
    public const CYCLE_MONTHLY = 'monthly';

    public const CYCLE_ANNUAL = 'annual';

    /**
     * Untyped, like every other constant here: this package's floor is PHP 8.2
     * and typed class constants are 8.3, so a type annotation would be a syntax
     * error on the oldest version CI builds against.
     *
     * @var array<int, string>
     */
    public const CYCLES = [self::CYCLE_MONTHLY, self::CYCLE_ANNUAL];

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
     */
    public static function planForPrice(?string $priceId): ?string
    {
        if ($priceId === null || $priceId === '') {
            return null;
        }

        return BillingCatalogue::productForStripePrice($priceId)['tier'] ?? null;
    }

    /**
     * The Stripe price to tier map, derived from the catalogue.
     *
     * @return array<string, string>
     */
    public static function prices(): array
    {
        return array_map(
            static fn (array $entry): string => $entry['tier'],
            self::catalogue(),
        );
    }

    /**
     * The billing cycle a Stripe price is charged on, or null when the
     * catalogue does not map the price.
     *
     * Null is reported rather than guessed, and it reaches the client as an
     * absent `cycle` that decodes to null there too. A tier is not a price: the
     * same tier sold monthly and annually is two prices, and a screen that
     * assumed one would tell a customer what they are paying on no evidence.
     * That is the defect this pair of methods was added to close, where a
     * billing screen rendered "billed annually" over a monthly charge.
     */
    public static function cycleForPrice(?string $priceId): ?string
    {
        if ($priceId === null || $priceId === '') {
            return null;
        }

        return BillingCatalogue::productForStripePrice($priceId)['cycle'] ?? null;
    }

    /**
     * The Stripe price that sells [$tier] on [$cycle], or null when none does.
     *
     * An exact pair match, never a nearest one. A checkout asks for the price
     * behind the figure it just showed the customer, so answering with the
     * tier's other price would charge an amount the screen did not display,
     * which is precisely the mismatch this lookup exists to prevent. An adopter
     * who sells a tier one way only therefore refuses the other way with a 422
     * rather than quietly billing the wrong figure.
     *
     * The pair names ONE product, the first in config order, and its Stripe ref
     * is the answer. A product for the pair with no Stripe ref sells it on the
     * stores only, so the card rail refuses it rather than reaching past it for
     * another product: write one product per (tier, cycle) carrying every
     * rail's ref, and keep a grandfathered price (still mapped so its webhooks
     * grant) on a product listed below the one you want SOLD.
     */
    public static function priceFor(string $tier, string $cycle): ?string
    {
        return BillingCatalogue::productForTierAndCycle($tier, $cycle)['refs']['stripe_price'] ?? null;
    }

    /**
     * Every Stripe price a subscription product carries, with the tier and
     * cycle it sells.
     *
     * The shape the plans endpoint derives each tier's sellable `cycles` from.
     * Only subscriptions appear: a one-off product grants no tier, so it has no
     * place in a map from price to tier. A price carried by two products keeps
     * the first, the same answer {@see BillingCatalogue::productForStripePrice()}
     * gives.
     *
     * @return array<string, array{tier: string, cycle: string}>
     */
    public static function catalogue(): array
    {
        $catalogue = [];

        foreach (BillingCatalogue::products() as $product) {
            $priceId = $product['refs']['stripe_price'];

            if ($priceId === null
                || isset($catalogue[$priceId])
                || $product['type'] !== BillingCatalogue::TYPE_SUBSCRIPTION
                || $product['tier'] === null
                || ! in_array($product['cycle'], self::CYCLES, true)
            ) {
                continue;
            }

            $catalogue[$priceId] = ['tier' => $product['tier'], 'cycle' => $product['cycle']];
        }

        return $catalogue;
    }
}
