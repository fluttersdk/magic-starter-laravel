<?php

namespace FlutterSdk\MagicStarter\Contracts;

use Filament\Panel;
use FlutterSdk\MagicStarter\Models\SupportProposal;
use FlutterSdk\MagicStarter\Support\BillingAdministrationRefused;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

/**
 * Contract for a staff member's decision on a support proposal: approve it,
 * reject it, or close an approval that never finished.
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
     * @throws BillingAdministrationRefused when billing refused the change; the row is left `failed`
     */
    public function approve(Authenticatable $staff, SupportProposal $proposal, Panel $panel): void;

    /**
     * Decline a pending proposal and tell the customer.
     *
     * @param  string  $reason  The staff member's own words, kept on the row and never shown to the customer.
     *
     * @throws ValidationException when `$staff` may not decide or the row is no longer pending
     */
    public function reject(Authenticatable $staff, SupportProposal $proposal, Panel $panel, string $reason): void;

    /**
     * Close an approval left `executing` by a crash, after staff confirmed by hand that it did not apply.
     *
     * The customer is told nothing: the staff member follows up in the thread.
     *
     * @param  string  $reason  The staff member's own words, kept on the row.
     *
     * @throws ValidationException when `$staff` may not decide or the row is not `executing`
     */
    public function markFailed(Authenticatable $staff, SupportProposal $proposal, Panel $panel, string $reason): void;
}
