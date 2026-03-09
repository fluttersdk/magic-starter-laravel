<?php

namespace FlutterSdk\MagicStarter\Support;

class SessionAgent
{
    /**
     * Parse the given user agent string and return device info.
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

        return 'Unknown';
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

        return 'Unknown';
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
