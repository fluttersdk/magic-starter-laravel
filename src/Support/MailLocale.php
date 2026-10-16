<?php

namespace FlutterSdk\MagicStarter\Support;

use Illuminate\Contracts\Translation\HasLocalePreference;

/**
 * The language a mail is pinned to when it is built in somebody else's request.
 *
 * An invitation goes to an address with no account and a ticket confirmation is
 * queued from a request that may be a staff member's, so Laravel has no
 * recipient locale to render in. The notifiable's own `preferredLocale()` wins
 * when it declares one; otherwise the locale of the request that builds the
 * mail, so a notification never falls back to the queue worker's default.
 */
final class MailLocale
{
    public static function for(mixed $notifiable): string
    {
        if ($notifiable instanceof HasLocalePreference) {
            return $notifiable->preferredLocale() ?? app()->getLocale();
        }

        return app()->getLocale();
    }
}
