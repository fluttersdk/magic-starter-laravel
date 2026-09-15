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
     * `app` carries the NATIVE application's name when the agent is one of
     * ours (`<App Name> (Flutter; iOS)`, which `magic` sends by default) and is
     * empty for a browser. It exists because `browser` cannot answer for a
     * native client and a session list that shows only the platform reads as
     * half a fact: "iOS" says nothing about which of a team's apps is holding
     * the token.
     *
     * @return array{browser: string, platform: string, app: string, is_desktop: bool, is_mobile: bool}
     */
    public static function parse(string $userAgent): array
    {
        if (empty($userAgent)) {
            return [
                'browser' => '',
                'platform' => '',
                'app' => '',
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
            'app' => self::nativeAgent($userAgent)['app'] ?? '',
            'is_desktop' => ! $isMobile,
            'is_mobile' => $isMobile,
        ];
    }

    /**
     * The whole native agent, or null when this is not one.
     *
     * ONE read, and the platform, the mobile flag and the app name all go
     * through it. They were three separate `preg_match` calls deciding on
     * different substrings, and an agent that satisfied one and not the others
     * was read inconsistently: a browser string with a trailing
     * `(Flutter; iOS)` reported `platform=iOS` and `browser=Safari` at the same
     * time, and handed the whole `Mozilla/5.0 (...)` prefix back as the app
     * name. Only a hand-rolled client reaches that, but three patterns drifting
     * apart is precisely what these readers were rewritten to stop.
     *
     * Anchored, so the app name is what the agent OPENS with:
     * `<App Name> (Flutter; <platform>)`, the shape `magic`'s network provider
     * sends. The name is whatever precedes the parenthesis, trimmed, so an app
     * called "Uptizm Field Ops" survives intact.
     *
     * The name may hold no parenthesis, which is what keeps a BROWSER agent out
     * of this branch entirely. A user agent carrying a trailing `(Flutter; iOS)`
     * after a `Mozilla/5.0 (Macintosh; ...)` prefix is not a native client, and
     * without that exclusion it would have been read as one whose name is the
     * whole prefix.
     *
     * @return array{app: string, platform: string}|null
     */
    private static function nativeAgent(string $userAgent): ?array
    {
        if (preg_match('/^([^()]*?)\s*\(Flutter;\s*([A-Za-z]+)\)/', $userAgent, $matches) !== 1) {
            return null;
        }

        return ['app' => trim($matches[1]), 'platform' => $matches[2]];
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
        // A native app first, because its agent has to win before the browser
        // patterns run: `magic` sends `<App> (Flutter; iOS)`, and `/Linux/`
        // would otherwise claim an Android build whose agent names both.
        $native = self::nativeAgent($userAgent);

        if ($native !== null) {
            return self::canonicalNativePlatform($native['platform']);
        }

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
     * Normalise a platform token from a native agent to this class's spelling.
     *
     * `magic` already sends the casing Apple and Google use, so this is a guard
     * against a hand-rolled client rather than a translation of the common
     * case: an agent saying `ios` should not read differently in the session
     * list from one saying `iOS`. An unrecognised token is returned as written
     * rather than emptied, because a client naming a platform this class has
     * not heard of still knows more than nothing does.
     */
    private static function canonicalNativePlatform(string $token): string
    {
        return match (strtolower($token)) {
            'ios' => 'iOS',
            'android' => 'Android',
            'macos' => 'macOS',
            'windows' => 'Windows',
            'linux' => 'Linux',
            'fuchsia' => 'Fuchsia',
            default => $token,
        };
    }

    /**
     * Check if user agent is mobile.
     */
    private static function isMobile(string $userAgent): bool
    {
        // A native agent decides on its own token. `(Flutter; macOS)` contains
        // none of the substrings below and would read as a desktop correctly by
        // accident, while `(Flutter; Android)` would match on 'Android' and be
        // right for the wrong reason; naming both here is what keeps a future
        // platform from being judged by a coincidence.
        $native = self::nativeAgent($userAgent);

        if ($native !== null) {
            return in_array(strtolower($native['platform']), ['ios', 'android'], true);
        }

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
