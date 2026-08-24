<?php

namespace FlutterSdk\MagicStarter\Support;

/**
 * The one place that decides whether a frontend URL is usable.
 *
 * Three features build links for an email out of `magic-starter.frontend_url`
 * (verification, team invitation, password reset) and each one used to test the
 * value differently. Two of them did not test it at all, and the result was a
 * RELATIVE URL in a delivered email: `/invitations/<token>/accept` and
 * `/auth/reset-password?token=...`. A mail client cannot resolve those, so an
 * invited teammate could not join and a locked-out customer could not get back in.
 * Nothing errored; the mails looked fine until someone clicked one.
 *
 * The trap underneath is worth naming, because it is not obvious: the key is
 * PRESENT and empty rather than missing, so
 * `config('magic-starter.frontend_url', config('app.frontend_url'))` never reaches
 * its fallback. Laravel's default argument fires for an ABSENT key, not an empty
 * one, and `MAGIC_STARTER_FRONTEND_URL=` in an env file produces an empty string.
 */
final class FrontendUrl
{
    /**
     * The configured frontend base URL, trimmed, or null when there is none.
     *
     * Whitespace-only counts as none: `MAGIC_STARTER_FRONTEND_URL= ` is the same
     * operator mistake as leaving it blank, and it used to produce URLs with
     * leading spaces. Slash-only counts as none too, for the reason below.
     * Trailing slashes are stripped so callers can concatenate.
     *
     * Falls back to `app.frontend_url` explicitly, because the config() default
     * argument cannot: see the class docblock.
     */
    public static function baseOrNull(): ?string
    {
        foreach (['magic-starter.frontend_url', 'app.frontend_url'] as $key) {
            $value = config($key);

            if (! is_string($value)) {
                continue;
            }

            // Emptiness is tested AFTER the rtrim, not only before it. This
            // method's whole job is to answer null when there is no usable base,
            // and `rtrim(trim('/'), '/')` is the EMPTY STRING, which is not null,
            // so `MAGIC_STARTER_FRONTEND_URL=/` used to walk straight past every
            // caller's `?? rtrim(url('/'), '/')` and rebuild the exact relative
            // URL this class exists to prevent. Worse in VerifyEmailNotification,
            // whose null check is explicit: an empty base reached
            // `str_replace(url('/'), '', $signedUrl)` and stripped the host off a
            // URL that was already absolute.
            $base = rtrim(trim($value), '/');

            if ($base !== '') {
                return $base;
            }
        }

        return null;
    }
}
