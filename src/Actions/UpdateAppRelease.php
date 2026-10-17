<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\UpdatesAppReleases;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Default app release update action; validates and normalises like {@see CreateAppRelease}.
 */
class UpdateAppRelease implements UpdatesAppReleases
{
    public function __construct(
        protected CreateAppRelease $creator,
    ) {}

    /**
     * Validate and update the given app release.
     *
     * @param  Authenticatable  $user  The staff user updating the release.
     * @param  Model  $release  The release to update.
     * @param  array<string, mixed>  $input  The release data.
     *
     * @throws ValidationException
     */
    public function update(Authenticatable $user, Model $release, array $input): void
    {
        $release->update($this->creator->attributes($input, $release));
    }
}
