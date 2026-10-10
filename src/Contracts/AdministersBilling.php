<?php

namespace FlutterSdk\MagicStarter\Contracts;

use Carbon\CarbonInterface;
use FlutterSdk\MagicStarter\Models\BillingGrant;
use FlutterSdk\MagicStarter\Support\BillingAdministrationRefused;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * The operator side of billing: what an admin may do to a billable's plan and
 * subscription from the panel.
 *
 * Every write lands through {@see WritesEntitlement} and leaves a
 * `billing_events` row with source `admin`. A refusal is a
 * {@see BillingAdministrationRefused}, thrown after its `request_refused` row is
 * recorded outside any transaction the implementation opened; anything else
 * that escapes is a defect.
 *
 * `$actor` is the operator, `$billable` the subject the application bills (a
 * user or a team, per `magic-starter.billing.billable`).
 */
interface AdministersBilling
{
    /**
     * Give the billable a plan with no payment behind it, until `$expiresAt` or
     * until revoked. Supersedes any grant still open for the billable.
     *
     * @param  string  $plan  a tier id of `magic-starter.billing.tier_order`
     * @param  string  $reason  the operator's own words, kept on the grant row
     * @param  CarbonInterface|null  $expiresAt  strictly in the future; null for no expiry
     *
     * @throws BillingAdministrationRefused `paid_rail_active`, `unknown_plan`, `expiry_in_past`
     *                                      or `entitlement_refused`
     */
    public function grant(
        Authenticatable $actor,
        Model $billable,
        string $plan,
        string $reason,
        ?CarbonInterface $expiresAt,
    ): BillingGrant;

    /**
     * End the billable's open manual grant, or a store record no production
     * subscription stands behind, and put any paid rail back on record.
     *
     * @param  string  $reason  the operator's own words, kept on the event row
     *
     * @throws BillingAdministrationRefused `not_manual`, `rail_error` or `entitlement_refused`
     */
    public function revoke(Authenticatable $actor, Model $billable, string $reason): void;

    /**
     * Move the end of the billable's Stripe trial to `$until`.
     *
     * @throws BillingAdministrationRefused
     */
    public function extendTrial(Authenticatable $actor, Model $billable, CarbonInterface $until): void;

    /**
     * End the billable's Stripe trial now.
     *
     * @throws BillingAdministrationRefused
     */
    public function endTrial(Authenticatable $actor, Model $billable): void;

    /**
     * Cancel the billable's Stripe subscription at the end of its period.
     *
     * @throws BillingAdministrationRefused
     */
    public function cancel(Authenticatable $actor, Model $billable): void;

    /**
     * Resume a Stripe subscription that is cancelled and still on its grace period.
     *
     * @throws BillingAdministrationRefused
     */
    public function resume(Authenticatable $actor, Model $billable): void;

    /**
     * Refund the billable's newest paid invoice.
     *
     * @param  string  $reason  the operator's own words, kept on the event row
     * @return string the Stripe refund id
     *
     * @throws BillingAdministrationRefused
     */
    public function refundLastInvoice(Authenticatable $actor, Model $billable, string $reason): string;

    /**
     * Re-read the billable's rail now and write what it says.
     *
     * @throws BillingAdministrationRefused
     */
    public function sync(Authenticatable $actor, Model $billable): void;

    /**
     * Whether a paid rail currently grants the billable a plan: the record names
     * Stripe or a store on a granting status, or the local Cashier `default`
     * subscription grants and has not ended. A manual record is not proof, and
     * neither is a record of nobody: a checkout over a comp can be dropped by the
     * write rules and leave a paying customer recorded as `none`.
     */
    public function paidRailGrants(Model $billable): bool;

    /**
     * The payment {@see self::refundLastInvoice()} would refund, or null when
     * there is none.
     *
     * @return array<string, mixed>|null
     */
    public function refundablePayment(Model $billable): ?array;
}
