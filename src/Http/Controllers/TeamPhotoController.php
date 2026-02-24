<?php

declare(strict_types=1);

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Http\Requests\UpdateTeamPhotoRequest;
use FlutterSdk\MagicStarter\Http\Resources\TeamResource;
use FlutterSdk\MagicStarter\MagicStarter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Handles team profile photo upload and deletion.
 */
class TeamPhotoController
{
    /**
     * Update the specified team's profile photo.
     */
    public function update(UpdateTeamPhotoRequest $request, string $team): TeamResource
    {
        $teamModel = $this->findTeam($team);
        Gate::forUser($request->user())->authorize('update', $teamModel);

        $disk = (string) (config('magic-starter.profile_photo_disk')
            ?? config('filesystems.default', 'public'));
        $filesystem = app('filesystem')->disk($disk);

        if (! empty($teamModel->profile_photo_path)) {
            $filesystem->delete((string) $teamModel->profile_photo_path);
        }

        $path = $request->file('photo')->storePublicly(
            config('magic-starter.team_photo_path', 'team-photos'),
            ['disk' => $disk],
        );

        $teamModel->forceFill([
            'profile_photo_path' => $path,
        ])->save();

        return new TeamResource($teamModel->fresh());
    }

    /**
     * Delete the specified team's profile photo.
     */
    public function delete(Request $request, string $team): TeamResource
    {
        $teamModel = $this->findTeam($team);
        Gate::forUser($request->user())->authorize('update', $teamModel);

        $disk = (string) (config('magic-starter.profile_photo_disk')
            ?? config('filesystems.default', 'public'));
        $filesystem = app('filesystem')->disk($disk);

        if (! empty($teamModel->profile_photo_path)) {
            $filesystem->delete((string) $teamModel->profile_photo_path);

            $teamModel->forceFill([
                'profile_photo_path' => null,
            ])->save();
        }

        return new TeamResource($teamModel->fresh());
    }

    /**
     * Find a team by its ID.
     */
    private function findTeam(string $id): mixed
    {
        $modelClass = MagicStarter::teamModel();

        return $modelClass::query()->findOrFail($id);
    }
}
