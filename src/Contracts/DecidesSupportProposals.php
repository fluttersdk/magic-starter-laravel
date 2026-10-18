<?php

namespace FlutterSdk\MagicStarter\Contracts;

use Filament\Panel;
use FlutterSdk\MagicStarter\Models\SupportProposal;
use FlutterSdk\MagicStarter\Support\BillingAdministrationRefused;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

/**
 * Contract for a staff member's decision on a support proposal: approve it,
 * reject it, or close by hand an approval whose outcome is unknown.
 *
 * The account owner asked the AI, the AI recorded the request, and approval
 * here is the only thing that runs {@see AdministersBilling}, with the staff
 * member as the actor. Each method first checks that `$staff` may enter
 * `$panel` and may run billing actions there.
 *
 * The panel is typed but this file loads without Filament: PHP resolves a
 * parameter's class only when an argument is checked. The provider binds an
 * implementation only where Filament is installed.
 *
 * A refusal is a ValidationException, which the panel's ContractAction shows as
 * a halt notification. A refusal that changed the row (expired, failed) has
 * committed that change before it is thrown.
 */
interface DecidesSupportProposals
{
    /**
     * Re-check a pending proposal against the account as it is now, then run it.
     *
     * Never call it inside a database transaction: the billing call must commit
     * on its own, so an open transaction is refused before anything is read.
     *
     * @throws ValidationException when `$staff` may not decide, the row is no longer pending, it expired, or
     *                             the account, its owner or the requested trial day no longer match the request
     * @throws BillingAdministrationRefused when billing refused the change; the row is left `failed` and the
     *                                      customer told, except a `rail_error` caused by a Stripe connection
     *                                      failure, whose outcome is unknown: the row stays `executing` and
     *                                      the customer is told nothing until staff mark it applied or failed
     */
    public function approve(Authenticatable $staff, SupportProposal $proposal, Panel $panel): void;

    /**
     * Decline a pending proposal and tell the customer. A pending row past its
     * deadline is marked `expired` instead, committed before the refusal.
     *
     * @param  string  $reason  The staff member's own words, kept on the row and never shown to the customer.
     *
     * @throws ValidationException when `$staff` may not decide, the row is no longer pending, or it expired
     */
    public function reject(Authenticatable $staff, SupportProposal $proposal, Panel $panel, string $reason): void;

    /**
     * Close an approval left `executing`, after staff confirmed by hand that it did not apply.
     *
     * The row records `$staff` as its final decider. The customer is told
     * nothing: the staff member follows up in the thread.
     *
     * @param  string  $reason  The staff member's own words, kept on the row.
     *
     * @throws ValidationException when `$staff` may not decide or the row is not `executing`
     */
    public function markFailed(Authenticatable $staff, SupportProposal $proposal, Panel $panel, string $reason): void;

    /**
     * Close an approval left `executing` as approved, after staff confirmed in
     * the billing events that the change did apply.
     *
     * The row records `$staff` as its final decider, and the customer gets the
     * approved line in the conversation's language. Billing is not called again.
     *
     * @param  string  $reason  The staff member's own words, kept on the row and never shown to the customer.
     *
     * @throws ValidationException when `$staff` may not decide or the row is not `executing`
     */
    public function markApplied(Authenticatable $staff, SupportProposal $proposal, Panel $panel, string $reason): void;
}
