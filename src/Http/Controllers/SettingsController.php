<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Features;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

/**
 * Exposes a strictly allowlisted set of public application settings.
 *
 * This endpoint is intentionally unauthenticated — it provides the Flutter
 * client with enough context (supported locales, timezones, enabled features)
 * to bootstrap itself before a user session exists. It MUST never expose
 * internal configuration values such as frontend_url, token TTLs, model class
 * names, or server file paths.
 */
class SettingsController
{
    /**
     * Return the allowlisted public settings payload.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'supported_timezones' => Config::get('magic-starter.supported_timezones', []),
            'supported_locales' => Config::get('magic-starter.supported_locales', []),
            'features' => [
                'registration' => true,
                'teams' => Features::hasTeamFeatures(),
                'social_login' => Features::hasSocialLoginFeatures(),
                'email_verification' => Features::hasEmailVerificationFeatures(),
                'guest_auth' => Features::hasGuestAuthFeatures(),
                'phone_otp' => Features::hasPhoneOtpFeatures(),
                'newsletter' => Features::hasNewsletterSubscriptionFeatures(),
                'extended_profile' => Features::hasExtendedProfileFeatures(),
                'two_factor_authentication' => Features::hasTwoFactorAuthenticationFeatures(),
                'sessions' => Features::hasSessionFeatures(),
                'profile_photos' => Features::hasProfilePhotoFeatures(),
                'notifications' => Features::hasNotificationFeatures(),
            ],
            'auth' => [
                'email' => Features::emailIdentity(),
                'phone' => Features::phoneIdentity(),
            ],
            'defaults' => [
                'locale' => Config::get('magic-starter.defaults.locale', 'en'),
                'timezone' => Config::get('magic-starter.defaults.timezone', 'UTC'),
            ],
        ]);
    }
}
