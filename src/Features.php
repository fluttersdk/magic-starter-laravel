<?php

namespace FlutterSdk\MagicStarter;

use Illuminate\Support\Facades\Config;

/**
 * Feature toggle registry for Magic Starter capabilities.
 */
class Features
{
    /**
     * Enable the teams feature.
     */
    public static function teams(): string
    {
        return 'teams';
    }

    /**
     * Enable the profile photos feature.
     */
    public static function profilePhotos(): string
    {
        return 'profile-photos';
    }

    /**
     * Enable the session management feature.
     */
    public static function sessions(): string
    {
        return 'sessions';
    }

    /**
     * Enable the social login feature.
     */
    public static function socialLogin(): string
    {
        return 'social-login';
    }

    /**
     * Enable the newsletter subscription feature.
     */
    public static function newsletterSubscription(): string
    {
        return 'newsletter-subscription';
    }

    /**
     * Enable the extended profile feature (phone, timezone, language, locale).
     */
    public static function extendedProfile(): string
    {
        return 'extended-profile';
    }

    /**
     * Enable the notification preferences feature.
     */
    public static function notifications(): string
    {
        return 'notifications';
    }

    /**
     * Enable the OneSignal push notification feature.
     */
    public static function onesignal(): string
    {
        return 'onesignal';
    }

    /**
     * Enable the two-factor authentication feature.
     */
    public static function twoFactorAuthentication(): string
    {
        return 'two-factor-authentication';
    }

    /**
     * Enable the email verification feature.
     */
    public static function emailVerification(): string
    {
        return 'email-verification';
    }

    /**
     * Enable the guest authentication feature.
     */
    public static function guestAuth(): string
    {
        return 'guest-auth';
    }

    /**
     * Enable the phone OTP feature.
     */
    public static function phoneOtp(): string
    {
        return 'phone-otp';
    }

    /**
     * Enable the timezones feature.
     */
    public static function timezones(): string
    {
        return 'timezones';
    }

    /**
     * Determine whether the given feature is enabled.
     */
    public static function enabled(string $feature): bool
    {
        return in_array($feature, Config::get('magic-starter.features', []), true);
    }

    /**
     * Determine whether the teams feature is enabled.
     */
    public static function hasTeamFeatures(): bool
    {
        return static::enabled(static::teams());
    }

    /**
     * Determine whether the profile photos feature is enabled.
     */
    public static function hasProfilePhotoFeatures(): bool
    {
        return static::enabled(static::profilePhotos());
    }

    /**
     * Determine whether the session management feature is enabled.
     */
    public static function hasSessionFeatures(): bool
    {
        return static::enabled(static::sessions());
    }

    /**
     * Determine whether the social login feature is enabled.
     */
    public static function hasSocialLoginFeatures(): bool
    {
        return static::enabled(static::socialLogin());
    }

    /**
     * Determine whether the newsletter subscription feature is enabled.
     */
    public static function hasNewsletterSubscriptionFeatures(): bool
    {
        return static::enabled(static::newsletterSubscription());
    }

    /**
     * Determine whether the extended profile feature is enabled.
     */
    public static function hasExtendedProfileFeatures(): bool
    {
        return static::enabled(static::extendedProfile());
    }

    /**
     * Determine whether the notification preferences feature is enabled.
     */
    public static function hasNotificationFeatures(): bool
    {
        return static::enabled(static::notifications());
    }

    /**
     * Determine whether the OneSignal push notification feature is enabled.
     */
    public static function hasOnesignalFeatures(): bool
    {
        return static::enabled(static::onesignal());
    }

    /**
     * Determine whether the two-factor authentication feature is enabled.
     */
    public static function hasTwoFactorAuthenticationFeatures(): bool
    {
        return static::enabled(static::twoFactorAuthentication());
    }

    /**
     * Determine whether the email verification feature is enabled.
     */
    public static function hasEmailVerificationFeatures(): bool
    {
        return static::enabled(static::emailVerification());
    }

    /**
     * Determine whether the guest authentication feature is enabled.
     */
    public static function hasGuestAuthFeatures(): bool
    {
        return static::enabled(static::guestAuth());
    }

    /**
     * Determine whether the phone OTP feature is enabled.
     */
    public static function hasPhoneOtpFeatures(): bool
    {
        return static::enabled(static::phoneOtp());
    }

    /**
     * Determine whether the timezones feature is enabled.
     */
    public static function hasTimezoneFeatures(): bool
    {
        return static::enabled(static::timezones());
    }

    /**
     * Determine whether email identity is enabled in config.
     */
    public static function emailIdentity(): bool
    {
        return Config::get('magic-starter.auth.email', true);
    }

    /**
     * Determine whether phone identity is enabled in config.
     */
    public static function phoneIdentity(): bool
    {
        return Config::get('magic-starter.auth.phone', false);
    }

    /**
     * Determine whether timezones or extended profile feature is enabled.
     */
    public static function hasTimezoneOrExtendedProfileFeatures(): bool
    {
        return static::hasTimezoneFeatures() || static::hasExtendedProfileFeatures();
    }
}
