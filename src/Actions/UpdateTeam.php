<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\UpdatesTeams;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Default team update action with optional photo upload.
 */
class UpdateTeam implements UpdatesTeams
{
    /**
     * Validate and update the given team.
     *
     * @param  Authenticatable  $user  The user performing the update.
     * @param  Model  $team  The team to update.
     * @param  array<string, mixed>  $input  The update data.
     *
     * @throws ValidationException
     */
    public function update(Authenticatable $user, Model $team, array $input): void
    {
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'photo' => ['nullable', 'file', 'image', 'max:1024'],
        ])->validate();

        $team->update([
            'name' => $validated['name'],
        ]);

        $photo = Arr::get($validated, 'photo');

        if ($photo !== null) {
            // 1. Delete existing photo if present.
            if (! empty($team->profile_photo_path)) {
                Storage::disk(config('magic-starter.profile_photo_disk', 'public'))
                    ->delete((string) $team->profile_photo_path);
            }

            // 2. Store new photo and update model.
            $path = $photo->storePublicly(
                config('magic-starter.team_photo_path', 'team-photos'),
                ['disk' => config('magic-starter.profile_photo_disk', 'public')],
            );

            $team->forceFill([
                'profile_photo_path' => $path,
            ])->save();
        }
    }
}
