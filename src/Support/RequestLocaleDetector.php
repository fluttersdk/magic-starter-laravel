<?php

declare(strict_types=1);

namespace FlutterSdk\MagicStarter\Support;

use DateTimeZone;
use Illuminate\Http\Request;

class RequestLocaleDetector
{
    /**
     * Detect locale from the Accept-Language header.
     *
     * Parses quality-weighted language tags and matches against
     * the configured supported_locales list.
     *
     * @param  Request  $request  The incoming HTTP request.
     * @return string|null The detected locale, or null if no match.
     */
    public static function detectLocale(Request $request): ?string
    {
        // 1. Get Accept-Language header from request.
        $header = $request->header('Accept-Language');

        if (empty($header)) {
            return null;
        }

        // 2. Parse quality-weighted entries.
        $locales = [];

        foreach (explode(',', $header) as $entry) {
            $parts = explode(';', trim($entry));
            $tag = trim($parts[0]);
            $quality = 1.0;

            if (isset($parts[1]) && str_starts_with(trim($parts[1]), 'q=')) {
                $quality = (float) substr(trim($parts[1]), 2);
            }

            $locales[] = [
                'tag' => $tag,
                'quality' => $quality,
            ];
        }

        // 3. Sort by quality descending.
        usort($locales, function (array $a, array $b): int {
            return $b['quality'] <=> $a['quality'];
        });

        // 4. Get supported locales from config.
        $supported = config('magic-starter.supported_locales', ['en']);

        // 5. Match against supported list.
        foreach ($locales as $locale) {
            $lang = explode('-', $locale['tag'])[0];

            if (in_array($lang, (array) $supported, true)) {
                return $lang;
            }
        }

        return null;
    }

    /**
     * Detect timezone from the X-Timezone header.
     *
     * Validates the header value against the configured
     * supported_timezones list.
     *
     * @param  Request  $request  The incoming HTTP request.
     * @return string|null The detected timezone, or null if no match.
     */
    public static function detectTimezone(Request $request): ?string
    {
        // 1. Get X-Timezone header from request.
        $header = $request->header('X-Timezone');

        if (empty($header)) {
            return null;
        }

        // 2. Get supported list from config or system.
        $supported = config('magic-starter.supported_timezones');

        if (empty($supported)) {
            $supported = DateTimeZone::listIdentifiers();
        }

        // 3. Check if header value is in the supported list (exact match).
        if (in_array($header, (array) $supported, true)) {
            return $header;
        }

        return null;
    }
}
