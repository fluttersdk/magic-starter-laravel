<?php

namespace FlutterSdk\MagicStarter\Traits;

trait HasProfilePhoto
{
    /**
     * Get the URL to the user's profile photo.
     */
    public function getProfilePhotoUrlAttribute(): string
    {
        if (! empty($this->profile_photo_path)) {
            $disk = config('magic-starter.profile_photo_disk')
                ?? config('filesystems.default', 'public');

            $filesystem = app('filesystem')->disk((string) $disk);

            if (method_exists($filesystem, 'url')) {
                return (string) $filesystem->url($this->profile_photo_path);
            }

            return (string) $this->profile_photo_path;
        }

        return $this->defaultProfilePhotoUrl();
    }

    /**
     * Get the default profile photo URL.
     */
    protected function defaultProfilePhotoUrl(): string
    {
        $segments = preg_split('/\s+/', trim((string) $this->name)) ?: [];
        $initials = array_map(
            static fn (string $segment): string => mb_substr($segment, 0, 1),
            array_filter($segments, static fn (string $segment): bool => $segment !== ''),
        );
        $name = implode(' ', $initials);

        $baseUrl = rtrim((string) config('magic-starter.ui_avatars_url', 'https://ui-avatars.com/api/'), '/');

        return $baseUrl . '/?name=' . urlencode($name) . '&color=FFFFFF&background=009E60';
    }
}
