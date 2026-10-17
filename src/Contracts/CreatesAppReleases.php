<?php

namespace FlutterSdk\MagicStarter\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Contract for creating an app release.
 */
interface CreatesAppReleases
{
    /**
     * Validate and create an app release.
     *
     * @param  Authenticatable  $user  The staff user creating the release.
     * @param  array<string, mixed>  $input  The release data: version, status, published_at, builds and items.
     * @return Model The created release.
     */
    public function create(Authenticatable $user, array $input): Model;
}
