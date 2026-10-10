<?php

return [

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
