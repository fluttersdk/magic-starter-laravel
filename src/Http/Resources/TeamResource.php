<?php

namespace FlutterSdk\MagicStarter\Http\Resources;

use FlutterSdk\MagicStarter\Enums\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamResource extends JsonResource
{
    /**
     * Transform the team into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'personal_team' => $this->resource->personal_team,
            'owner_id' => $this->resource->user_id,
            'user_role' => $this->resolveUserRole($request->user()),
            'profile_photo_url' => $this->resource->profile_photo_url,
            'created_at' => $this->resource->created_at,
            'updated_at' => $this->resource->updated_at,
        ];
    }

    /**
     * Resolve the authenticated user's role within this team.
     *
     * @param  mixed  $user  The authenticated user (nullable).
     * @return string|null  The role value (owner, admin, editor, member) or null.
     */
    protected function resolveUserRole(mixed $user): ?string
    {
        if (! $user) {
            return null;
        }

        // 1. Team owner is always 'owner' — regardless of pivot data.
        if ((string) $this->resource->user_id === (string) $user->id) {
            return Role::OWNER->value;
        }

        // 2. Pivot available (team loaded via $user->teams BelongsToMany).
        if (isset($this->resource->pivot->role)) {
            return $this->resource->pivot->role;
        }

        // 3. Fallback — look up the user's membership from their teams relation.
        $membership = $user->teams->firstWhere('id', $this->resource->id);

        return $membership?->pivot?->role;
    }
}
