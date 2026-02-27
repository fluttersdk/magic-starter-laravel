<?php

namespace FlutterSdk\MagicStarter\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the user into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'email_verified_at' => $this->email_verified_at,
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'language' => $this->language,
            'profile_photo_url' => $this->profile_photo_url,
            'two_factor_enabled' => method_exists($this->resource, 'hasEnabledTwoFactorAuthentication') &&
                $this->resource->hasEnabledTwoFactorAuthentication(),
            'current_team' => $this->when(
                $this->getCurrentTeamOrPersonal() !== null,
                fn () => new TeamResource($this->getCurrentTeamOrPersonal()),
            ),
            'all_teams' => TeamResource::collection($this->allTeams()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
