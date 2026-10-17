<?php

namespace FlutterSdk\MagicStarter\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Contract for deleting an app release.
 */
interface DeletesAppReleases
{
    /**
     * Delete the given app release.
     *
     * @param  Authenticatable  $user  The staff user deleting the release.
     * @param  Model  $release  The release to delete.
     */
    public function delete(Authenticatable $user, Model $release): void;
}
