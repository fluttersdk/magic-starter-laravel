<?php

namespace FlutterSdk\MagicStarter\Traits;

use FlutterSdk\MagicStarter\MagicStarter;

trait HasProfilePhoto
{
    /**
     * Get the URL to the user's profile photo, or null when none is stored.
     *
     * Null rather than a generated image, because this package answers JSON
     * clients and every one of them draws its own initials in its own theme.
     * The ui-avatars.com image this used to answer cost a third-party round
     * trip per avatar on every screen, sent the person's name to that third
     * party, failed offline, arrived in colours the client's design system did
     * not choose, and could not be told apart from a real upload, so "has this
     * person set a photo" had no answer on the wire.
     */
    public function getProfilePhotoUrlAttribute(): ?string
    {
        if (empty($this->profile_photo_path)) {
            return null;
        }

        $filesystem = app('filesystem')->disk(MagicStarter::profilePhotoDisk());

        if (method_exists($filesystem, 'url')) {
            return (string) $filesystem->url($this->profile_photo_path);
        }

        return (string) $this->profile_photo_path;
    }
}
