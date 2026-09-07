<?php

namespace FlutterSdk\MagicStarter\Support;

class SessionAgent
{
    /**
     * Parse the given user agent string and return device info.
     *
     * `browser` and `platform` are EMPTY when this class cannot name them,
     * never a word. A published package cannot know the language its consumer
     * renders in, so a literal here is English frozen into every app that
     * installs it: a Turkish session list read "Unknown - Unknown" under an
     * otherwise fully Turkish page. Empty is already the answer this method
     * gives for an absent user agent, so it is one shape for one fact rather
     * than two, and the consumer supplies the wording.
     *
     * The client half of the contract is already written for it:
     * `magic_starter`'s session row joins the non-empty parts and falls back to
     * its own translated label when both are empty, so a partially readable
     * agent still shows what IS known ("Mac") instead of naming a browser we
     * failed to recognise.
     *
     * @return array{browser: string, platform: string, is_desktop: bool, is_mobile: bool}
     */
    public static function parse(string $userAgent): array
    {
        if (empty($userAgent)) {
            return [
                'browser' => '',
                'platform' => '',
                'is_desktop' => false,
                'is_mobile' => false,
            ];
        }

        $browser = self::detectBrowser($userAgent);
        $platform = self::detectPlatform($userAgent);
        $isMobile = self::isMobile($userAgent);

        return [
            'browser' => $browser,
            'platform' => $platform,
            'is_desktop' => ! $isMobile,
            'is_mobile' => $isMobile,
        ];
    }

    /**
     * Detect browser from user agent string.
     */
    private static function detectBrowser(string $userAgent): string
    {
        $patterns = [
            'Chrome' => '/Chrome\/([0-9.]+)/',
            'Firefox' => '/Firefox\/([0-9.]+)/',
            'Safari' => '/Safari\/([0-9.]+)/',
            'Edge' => '/Edge\/([0-9.]+)/',
            'Opera' => '/Opera|OPR\/([0-9.]+)/',
            'IE' => '/MSIE|Trident.*rv:([0-9.]+)/',
        ];

        foreach ($patterns as $browser => $pattern) {
            if (preg_match($pattern, $userAgent)) {
                return $browser;
            }
        }

        // Empty rather than a word: see the note on parse().
        return '';
    }

    /**
     * Detect platform from user agent string.
     */
    private static function detectPlatform(string $userAgent): string
    {
        $patterns = [
            'Windows' => '/Windows NT ([0-9.]+)/',
            'Mac' => '/Mac OS X ([0-9._]+)/',
            'Linux' => '/Linux/',
            'Android' => '/Android ([0-9.]+)/',
            'iOS' => '/iPhone|iPad|iPod/',
        ];

        foreach ($patterns as $platform => $pattern) {
            if (preg_match($pattern, $userAgent)) {
                return $platform;
            }
        }

        // Empty rather than a word: see the note on parse().
        return '';
    }

    /**
     * Check if user agent is mobile.
     */
    private static function isMobile(string $userAgent): bool
    {
        $mobilePatterns = [
            'Android',
            'iPhone',
            'iPad',
            'iPod',
            'Windows Phone',
            'Mobile',
        ];

        foreach ($mobilePatterns as $pattern) {
            if (stripos($userAgent, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }
}
