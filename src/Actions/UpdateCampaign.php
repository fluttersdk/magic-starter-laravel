<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\UpdatesCampaigns;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Default campaign update action; validates and saves like {@see CreateCampaign}.
 */
class UpdateCampaign implements UpdatesCampaigns
{
    public function __construct(
        protected CreateCampaign $creator,
    ) {}

    /**
     * Validate and update the given campaign.
     *
     * @param  Authenticatable  $user  The staff user updating the campaign.
     * @param  Model  $campaign  The campaign to update.
     * @param  array<string, mixed>  $input  The campaign data.
     *
     * @throws ValidationException
     */
    public function update(Authenticatable $user, Model $campaign, array $input): void
    {
        $this->creator->save($campaign, $input);
    }
}
