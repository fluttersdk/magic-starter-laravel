<?php

namespace FlutterSdk\MagicStarter\Support;

use Jenssegers\Agent\Agent;

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

        $agent = app(Agent::class);
        $agent->setUserAgent($userAgent);

        return [
            'browser' => $agent->browser() ?: '',
            'platform' => $agent->platform() ?: '',
            'is_desktop' => $agent->isDesktop(),
            'is_mobile' => $agent->isMobile() || $agent->isTablet(),
        ];
    }
}
