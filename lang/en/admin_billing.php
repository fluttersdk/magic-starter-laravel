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
    ],

];
