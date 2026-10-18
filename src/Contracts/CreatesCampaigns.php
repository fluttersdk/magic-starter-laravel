<?php

namespace FlutterSdk\MagicStarter\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Contract for creating a campaign.
 */
interface CreatesCampaigns
{
    /**
     * Validate and create a campaign; an enabled one has its promotion code checked against Stripe.
     *
     * @param  Authenticatable  $user  The staff user creating the campaign.
     * @param  array<string, mixed>  $input  The campaign data: name, window, enabled, products, copy and code id.
     * @return Model The created campaign.
     */
    public function create(Authenticatable $user, array $input): Model;
}
