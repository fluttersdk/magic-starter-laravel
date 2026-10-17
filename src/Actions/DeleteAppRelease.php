<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\DeletesAppReleases;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Default app release deletion action.
 */
class DeleteAppRelease implements DeletesAppReleases
{
    /**
     * Delete the given app release.
     *
     * @param  Authenticatable  $user  The staff user deleting the release.
     * @param  Model  $release  The release to delete.
     */
    public function delete(Authenticatable $user, Model $release): void
    {
        $release->delete();
    }
}
