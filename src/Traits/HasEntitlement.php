<?php

namespace FlutterSdk\MagicStarter\Traits;

use Carbon\Exceptions\InvalidFormatException;
use FlutterSdk\MagicStarter\Enums\BillingProvider;
use FlutterSdk\MagicStarter\Enums\PlanStatus;
use FlutterSdk\MagicStarter\Support\BillingCatalogue;
use FlutterSdk\MagicStarter\Support\ReadsBillableAttributes;

/**
 * Cashier-style reads on the billable model that answer for every rail.
 *
 * Cashier's `subscribed()` answers for Stripe alone: a customer who bought on
 * the App Store or the Play Store, or was granted a plan by an operator, has no
 * Cashier row and reads as not subscribed. These methods read the provenance
 * columns this package writes for every rail instead, so a store buyer is
 * entitled exactly like a card buyer and the application never has to ask which
 * rail sold the plan before it decides what to show.
 *
 * Opt-in, and read only: nothing here writes a column. A tier is "held" only
 * while the plan is entitled; a finished or paused plan reads as the free floor
 * even if the tier is still stored, so a gate on {@see self::tierAtLeast()}
 * cannot be passed by a lapsed customer.
 *
 * Every read goes through {@see ReadsBillableAttributes}, so a model that casts
 * `plan` to an enum of its own answers the same as one that casts nothing.
 */
trait HasEntitlement
{
    use ReadsBillableAttributes;

    /**
     * Whether somebody is paying for a tier above the free floor right now.
     *
     * Dunning statuses count, since the plan is still owed to the customer while
     * the rail retries the charge. The rail is not consulted: an operator grant
     * entitles like a card.
     */
    public function entitled(): bool
    {
        return $this->holdsPaidTier(
            $this->stringAttribute($this, 'plan'),
            PlanStatus::fromWire($this->stringAttribute($this, 'plan_status')),
        );
    }

    /**
     * Whether the tier the customer currently holds is exactly [$tier].
     *
     * A lapsed customer holds the floor, so `onTier('pro')` is false for a
     * cancelled pro plan and `onTier(<floor>)` is true.
     */
    public function onTier(string $tier): bool
    {
        return $this->entitlementTier() === $tier;
    }

    /**
     * Whether the held tier ranks at or above [$tier] in `billing.tier_order`.
     *
     * A tier outside the ranking, on either side, answers false: an unranked
     * word cannot be compared, and guessing would let a gate open by accident.
     */
    public function tierAtLeast(string $tier): bool
    {
        $order = BillingCatalogue::tierOrder();

        $wanted = array_search($tier, $order, true);
        $held = array_search($this->entitlementTier(), $order, true);

        return $wanted !== false && $held !== false && $held >= $wanted;
    }

    /**
     * Whether the plan is inside a grace window that has not closed yet.
     *
     * @throws InvalidFormatException When the stored end date is not a date.
     */
    public function onGracePeriod(): bool
    {
        return $this->dateAttribute($this, 'plan_grace_period_ends_at')?->isFuture() ?? false;
    }

    /**
     * The rail behind the stored plan; `NONE` when none ever charged it.
     */
    public function entitlementProvider(): BillingProvider
    {
        return BillingProvider::fromWire($this->stringAttribute($this, 'plan_provider'));
    }

    /**
     * The catalogue key of the product the entitlement was bought as.
     *
     * Null when the plan is not entitled, when no product id was recorded, or
     * when the recorded id matches no catalogue product (a config gap, never a
     * guess). The id is a Stripe price id or a store product id, resolved in
     * the order the `billing` wire uses, so both name the same key.
     */
    public function entitledProduct(): ?string
    {
        if (! $this->entitled()) {
            return null;
        }

        return BillingCatalogue::productForRailId($this->stringAttribute($this, 'plan_product_id'))['key'] ?? null;
    }

    /**
     * The tier the customer holds: the stored one while entitled, otherwise the
     * floor.
     *
     * Named for the concern because a model using this trait may already have
     * methods of its own, and a clash here would silently replace the rule.
     */
    protected function entitlementTier(): ?string
    {
        return $this->entitled() ? $this->stringAttribute($this, 'plan') : BillingCatalogue::floor();
    }
}
