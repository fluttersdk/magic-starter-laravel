<?php

namespace FlutterSdk\MagicStarter\Traits;

trait HasProfilePhoto
{
    /**
     * Get the URL to the user's profile photo.
     *
     * Null when the account has uploaded none AND the host has switched the
     * generated fallback off; see defaultProfilePhotoUrl().
     */
    public function getProfilePhotoUrlAttribute(): ?string
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
     * Get the default profile photo URL, or null when the host wants none.
     *
     * The default is a ui-avatars.com image, which is Jetstream's answer and
     * stays the default here so no adopter changes behaviour by upgrading. It
     * is the right answer for a server-rendered app, which has nowhere else to
     * put a set of initials.
     *
     * It is the wrong answer for a JSON API, whose client usually draws its own
     * initials already: the generated image costs a third-party round trip per
     * avatar on every screen, sends the person's NAME to that third party each
     * time, fails offline, and arrives in colours the consumer's design system
     * did not choose. A client also cannot tell it apart from a real upload, so
     * "has this person set a photo" becomes unanswerable.
     *
     * Setting `magic-starter.ui_avatars_url` to an empty string (env
     * `MAGIC_STARTER_UI_AVATARS_URL=`) returns null instead, and the client
     * renders whatever it renders for an account with no photo.
     */
    protected function defaultProfilePhotoUrl(): ?string
    {
        $baseUrl = rtrim((string) config('magic-starter.ui_avatars_url', 'https://ui-avatars.com/api/'), '/');

        if ($baseUrl === '') {
            return null;
        }

        $segments = preg_split('/\s+/', trim((string) $this->name)) ?: [];
        $initials = array_map(
            static fn (string $segment): string => mb_substr($segment, 0, 1),
            array_filter($segments, static fn (string $segment): bool => $segment !== ''),
        );
        $name = implode(' ', $initials);

        return $baseUrl . '/?name=' . urlencode($name) . '&color=FFFFFF&background=009E60';
    }
}
