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
     * a paying subscription means somebody added a price in Stripe and not in
     * `magic-starter.billing.prices`.
     *
     * The tier travels as a plain string because the package ships no tier
     * vocabulary; the consuming application owns those words, and this map is
     * where it says which Stripe price sells which of them.
     *
     * The empty check is explicit rather than `! $priceId`, which is the form
     * one of the two copies used: they differ on `'0'`.
     */
    public static function planForPrice(?string $priceId): ?string
    {
        if ($priceId === null || $priceId === '') {
            return null;
        }

        $tier = self::prices()[$priceId] ?? null;

        return is_string($tier) && $tier !== '' ? $tier : null;
    }

    /**
     * The consumer's price to tier map, with unusable entries stripped.
     *
     * The one place this key is read, in either direction: a Stripe event asks
     * which tier a price sells, and a checkout asks which price sells a tier.
     * Two readers of one config key would each have to decide what an empty
     * entry means, and only one of them has to get it wrong for an unset price
     * id to sell a paid tier.
     *
     * An EMPTY KEY is the entry that matters, and the filter is carried over
     * from the application this map was extracted from, whose own comment gives
     * the reason: "Null keys are stripped so an unset price id can never map an
     * absent price to a paid tier." An adopter assembling this map from the
     * environment (`env('CASHIER_PRICE_PRO')` and friends) writes `'' => 'pro'`
     * the moment one of those variables is unset, and a reverse lookup then
     * hands back the empty string as the price id of a real tier: a checkout
     * against no price, or a paid tier granted to a price nobody sells. The
     * lookup above refuses an empty price id on its own account as well, so
     * these two guards overlap deliberately rather than accidentally; each one
     * closes the hole the other one leaves when it is the one that is changed.
     *
     * @return array<string, string>
     */
    public static function prices(): array
    {
        $configured = config('magic-starter.billing.prices', []);

        if (! is_array($configured)) {
            return [];
        }

        $prices = [];

        foreach ($configured as $priceId => $tier) {
            $priceId = (string) $priceId;

            if ($priceId === '' || ! is_string($tier) || $tier === '') {
                continue;
            }

            $prices[$priceId] = $tier;
        }

        return $prices;
    }
}
