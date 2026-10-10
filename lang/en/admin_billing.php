<?php

return [

    'title' => 'Billing',

    'summary' => [
        'heading' => 'Summary',
        'plan' => 'Plan',
        'status' => 'Status',
        'provider' => 'Provider',
        'period_end' => 'Period ends',
        'grant' => 'Manual grant',
        'grant_expires' => 'Grant expires',
        'no_expiry' => 'Never',
        'trial_ends' => 'Stripe trial ends',
        'none' => '-',
    ],

    'fields' => [
        'plan' => 'Plan',
        'reason' => 'Reason',
        'expires_at' => 'Expires on',
        'expires_at_help' => 'Leave empty to keep the grant until it is revoked.',
        'until' => 'Trial ends on',
        'refund_reason' => 'Refund reason',
    ],

    'refund_reasons' => [
        'requested_by_customer' => 'Requested by the customer',
        'duplicate' => 'Duplicate payment',
    ],

    'actions' => [
        'grant' => [
            'label' => 'Grant plan',
            'heading' => 'Grant a plan',
            'description' => 'The customer gets the plan with no payment behind it, until the expiry or a revoke.',
            'success' => 'Plan granted.',
        ],
        'revoke' => [
            'label' => 'Revoke grant',
            'heading' => 'Revoke the manual plan',
            'description' => 'The customer loses the granted plan; any paid subscription is put back on record.',
            'success' => 'Grant revoked.',
        ],
        'extend_trial' => [
            'label' => 'Extend trial',
            'heading' => 'Extend the Stripe trial',
            'description' => 'Stripe moves the end of the trial; the customer is not charged until then.',
            'success' => 'Trial extended.',
        ],
        'end_trial' => [
            'label' => 'End trial',
            'heading' => 'End the Stripe trial now',
            'description' => 'Stripe ends the trial and invoices the customer immediately.',
            'success' => 'Trial ended.',
        ],
        'cancel_subscription' => [
            'label' => 'Cancel subscription',
            'heading' => 'Cancel at the end of the period',
            'description' => 'The subscription keeps its plan until the paid period ends, then stops renewing.',
            'success' => 'Subscription cancelled at the end of the period.',
        ],
        'resume_subscription' => [
            'label' => 'Resume subscription',
            'heading' => 'Resume the subscription',
            'description' => 'The cancellation is lifted and the subscription renews as before.',
            'success' => 'Subscription resumed.',
        ],
        'refund' => [
            'label' => 'Refund last invoice',
            'heading' => 'Refund the last paid invoice',
            'description' => 'Stripe refunds :amount, in full, for invoice :invoice. The subscription is left as it is.',
            'success' => 'Invoice refunded.',
        ],
        'sync' => [
            'label' => 'Sync now',
            'heading' => 'Sync with the billing provider',
            'description' => 'The provider is read now and the customer\'s plan is updated to what it says.',
            'success' => 'Billing synced.',
        ],
    ],

    /*
     * Why an operator's billing action was refused. The key is the stable
     * reason `BillingAdministrationRefused::reason()` carries and the
     * `request_refused` row records; the sentence is what the panel shows.
     */
    'refusals' => [
        'paid_rail_active' => 'A paid subscription is active for this customer; cancel it before granting a plan.',
        'unknown_plan' => 'This plan is not in the billing catalogue.',
        'expiry_in_past' => 'The expiry date must be in the future.',
        'entitlement_refused' => 'The billing rules refused this change; the customer\'s plan was left as it was.',
        'not_manual' => 'This plan was not granted by an operator; cancel the subscription instead.',
        'rail_error' => 'The billing provider could not be reached; nothing was changed. Try again shortly.',
        'no_subscription' => 'This customer has no Stripe subscription.',
        'not_trialing' => 'This subscription is not on a trial.',
        'date_in_past' => 'The new trial end must be in the future.',
        'already_cancelled' => 'This subscription is already cancelled.',
        'not_on_grace_period' => 'Only a cancelled subscription that has not ended yet can be resumed.',
        'invalid_reason' => 'Choose why the invoice is refunded: requested by the customer or a duplicate.',
        'nothing_refundable' => 'This customer has no paid invoice that can be refunded.',
        'nothing_to_sync' => 'This customer has no subscription with a billing provider to sync.',
        'unmapped_price' => 'Stripe bills this customer on a price the billing catalogue does not map to a plan; nothing was changed.',
    ],

];
