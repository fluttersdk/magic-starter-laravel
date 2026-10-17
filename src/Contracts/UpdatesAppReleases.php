<?php

namespace FlutterSdk\MagicStarter\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Contract for updating an app release.
 */
interface UpdatesAppReleases
{
    /**
     * Validate and update the given app release.
     *
     * @param  Authenticatable  $user  The staff user updating the release.
     * @param  Model  $release  The release to update.
     * @param  array<string, mixed>  $input  The release data: version, status, published_at, builds and items.
     */
    public function update(Authenticatable $user, Model $release, array $input): void;
}
