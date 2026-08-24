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
        return array_map(
            static fn (array $entry): string => $entry['tier'],
            self::catalogue(),
        );
    }

    /**
     * The billing cycle a Stripe price is charged on, or null when the config
     * does not say.
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

        return self::catalogue()[$priceId]['cycle'] ?? null;
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
     * Two entries declaring the SAME pair resolve to whichever is written first
     * in the config, silently. That was true of the old flat map as well, but it
     * had one entry per tier by construction and this shape invites several: a
     * grandfathered price kept mapped so its webhooks still grant the tier is
     * the ordinary case. The config comment tells the adopter to list the price
     * they want SOLD first and keep retired ones below it.
     */
    public static function priceFor(string $tier, string $cycle): ?string
    {
        foreach (self::catalogue() as $priceId => $entry) {
            if ($entry['tier'] === $tier && $entry['cycle'] === $cycle) {
                return $priceId;
            }
        }

        return null;
    }

    /**
     * The consumer's price map, normalised and with unusable entries stripped.
     *
     * Two forms are accepted, because a vendor selling one price per tier should
     * not have to write a map to say so:
     *
     *     'price_pro'        => 'pro',
     *     'price_pro_annual' => ['tier' => 'pro', 'cycle' => 'annual'],
     *
     * **A bare string is read as MONTHLY**, and that default is the one thing to
     * get right in this config. It is a guess the package cannot verify: Stripe
     * knows the interval and this array does not, and reading it would mean an
     * API call per price on every request. So an adopter whose single mapped
     * price is an ANNUAL one has to say so, or every screen will report a
     * monthly cycle over an annual charge. Declaring the cycle is cheap and
     * saying nothing is only safe when the price really is monthly.
     *
     * An unrecognised cycle word is dropped rather than defaulted, so a typo in
     * `'cycle' => 'anual'` costs a 422 on that tier instead of a charge on the
     * wrong price.
     *
     * @return array<string, array{tier: string, cycle: string}>
     */
    public static function catalogue(): array
    {
        $configured = config('magic-starter.billing.prices', []);

        if (! is_array($configured)) {
            return [];
        }

        $catalogue = [];

        foreach ($configured as $priceId => $entry) {
            $priceId = (string) $priceId;

            if ($priceId === '') {
                continue;
            }

            $tier = is_array($entry) ? ($entry['tier'] ?? null) : $entry;
            $cycle = is_array($entry) ? ($entry['cycle'] ?? self::CYCLE_MONTHLY) : self::CYCLE_MONTHLY;

            if (! is_string($tier) || $tier === '') {
                continue;
            }

            if (! in_array($cycle, self::CYCLES, true)) {
                continue;
            }

            $catalogue[$priceId] = ['tier' => $tier, 'cycle' => $cycle];
        }

        return $catalogue;
    }
}
