<?php

namespace FlutterSdk\MagicStarter\Http\Resources;

use FlutterSdk\MagicStarter\Enums\Role;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamResource extends JsonResource
{
    /**
     * Transform the team into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $user = $request->user();
        $resource = $this->resource;
        $ownerId = \data_get($resource, 'user_id');

        $userRole = null;

        if ($user) {
            if ((string) $ownerId === (string) $user->id) {
                $userRole = Role::OWNER->value;
            } else {
                if (isset($this->resource->pivot) && $this->resource->pivot->role !== null) {
                    // When team is loaded via $user->teams
                    $userRole = $this->resource->pivot->role;
                } elseif ($this->resource->relationLoaded('users')) {
                    // When team is loaded via Team::with('users')
                    $membership = $this->resource->users->find($user->id);
                    $userRole = $membership?->pivot?->role;
                } else {
                    // Fallback for currentTeam (loaded via BelongsTo without pivot)
                    // We check the user's teams relationship which should have the pivot loaded.
                    $teamFromUser = $user->teams->firstWhere('id', $this->resource->id);
                    $userRole = $teamFromUser?->pivot?->role;
                }
            }
        }

        return [
            'id' => \data_get($resource, 'id'),
            'name' => \data_get($resource, 'name'),
            'personal_team' => \data_get($resource, 'personal_team'),
            'owner_id' => $ownerId,
            'user_role' => $userRole,
            'profile_photo_url' => \data_get($resource, 'profile_photo_url'),
            'created_at' => \data_get($resource, 'created_at'),
            'updated_at' => \data_get($resource, 'updated_at'),
        ];
    }
}
