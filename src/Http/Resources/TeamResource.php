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
        $userRole = null;
        $user = $request->user();
        $resource = $this->resource;

        $ownerId = \data_get($resource, 'user_id');

        if ($user) {
            if ((string) $ownerId === (string) $user->id) {
                $userRole = Role::OWNER->value;
            } else {
                $users = \data_get($resource, 'users');
                $membership = \is_object($users) && method_exists($users, 'find')
                    ? $users->find($user->id)
                    : null;
                $userRole = $membership?->pivot?->role;
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
