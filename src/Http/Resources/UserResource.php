<?php

namespace FlutterSdk\MagicStarter\Http\Resources;

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the user into an array.
     *
     * Consumer fields registered through `MagicStarter::serializeUserUsing`
     * are merged over the package's own. A consumer that adds a column to
     * `users` has no other way to publish it: this resource is not resolved
     * through the container, so it cannot be swapped, and every endpoint that
     * serialises a user comes through here.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [...$this->packageFields(), ...MagicStarter::extraUserFields($this->resource, $request)];
    }

    /**
     * The fields this package publishes itself.
     *
     * Takes no request, unlike `toArray`: every value here comes from the
     * resource or from a feature flag.
     *
     * @return array<string, mixed>
     */
    private function packageFields(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'is_guest' => (bool) $this->is_guest,
            'email_verified_at' => $this->email_verified_at,
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'profile_photo_url' => $this->profile_photo_url,
            'two_factor_enabled' => method_exists($this->resource, 'hasEnabledTwoFactorAuthentication') &&
                $this->resource->hasEnabledTwoFactorAuthentication(),
            'current_team' => $this->when(
                Features::hasTeamFeatures()
                    && method_exists($this->resource, 'getCurrentTeamOrPersonal')
                    && $this->getCurrentTeamOrPersonal() !== null,
                fn () => new TeamResource($this->getCurrentTeamOrPersonal()),
            ),
            'all_teams' => $this->when(
                Features::hasTeamFeatures()
                    && method_exists($this->resource, 'allTeams'),
                fn () => TeamResource::collection($this->allTeams()),
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
