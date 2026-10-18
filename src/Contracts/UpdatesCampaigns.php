<?php

namespace FlutterSdk\MagicStarter\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Contract for updating a campaign.
 */
interface UpdatesCampaigns
{
    /**
     * Validate and update the given campaign.
     *
     * The promotion code is checked against Stripe only when the campaign ends
     * up enabled and its code, end or products changed, or it was not enabled
     * before; disabling it never reaches Stripe.
     *
     * @param  Authenticatable  $user  The staff user updating the campaign.
     * @param  Model  $campaign  The campaign to update.
     * @param  array<string, mixed>  $input  The campaign data: name, window, enabled, products, copy and code id.
     */
    public function update(Authenticatable $user, Model $campaign, array $input): void;
}
