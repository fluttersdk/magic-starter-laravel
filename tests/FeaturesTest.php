<?php

namespace FlutterSdk\MagicStarter\Tests;

use FlutterSdk\MagicStarter\Features;

class FeaturesTest extends TestCase
{
    public function test_enabled_returns_false_when_feature_not_in_config(): void
    {
        config(['magic-starter.features' => []]);

        $this->assertFalse(Features::enabled(Features::teams()));
        $this->assertFalse(Features::enabled(Features::profilePhotos()));
        $this->assertFalse(Features::enabled(Features::sessions()));
        $this->assertFalse(Features::enabled(Features::socialLogin()));
    }

    public function test_enabled_returns_true_when_feature_in_config(): void
    {
        config(['magic-starter.features' => [
            Features::teams(),
            Features::profilePhotos(),
            Features::sessions(),
            Features::socialLogin(),
        ]]);

        $this->assertTrue(Features::enabled(Features::teams()));
        $this->assertTrue(Features::enabled(Features::profilePhotos()));
        $this->assertTrue(Features::enabled(Features::sessions()));
        $this->assertTrue(Features::enabled(Features::socialLogin()));
    }

    public function test_has_team_features_returns_false_by_default(): void
    {
        config(['magic-starter.features' => []]);

        $this->assertFalse(Features::hasTeamFeatures());
    }

    public function test_has_team_features_returns_true_when_enabled(): void
    {
        config(['magic-starter.features' => [Features::teams()]]);

        $this->assertTrue(Features::hasTeamFeatures());
    }

    public function test_has_profile_photo_features_returns_false_by_default(): void
    {
        config(['magic-starter.features' => []]);

        $this->assertFalse(Features::hasProfilePhotoFeatures());
    }

    public function test_has_profile_photo_features_returns_true_when_enabled(): void
    {
        config(['magic-starter.features' => [Features::profilePhotos()]]);

        $this->assertTrue(Features::hasProfilePhotoFeatures());
    }

    public function test_has_session_features_returns_false_by_default(): void
    {
        config(['magic-starter.features' => []]);

        $this->assertFalse(Features::hasSessionFeatures());
    }

    public function test_has_session_features_returns_true_when_enabled(): void
    {
        config(['magic-starter.features' => [Features::sessions()]]);

        $this->assertTrue(Features::hasSessionFeatures());
    }

    public function test_has_social_login_features_returns_false_by_default(): void
    {
        config(['magic-starter.features' => []]);

        $this->assertFalse(Features::hasSocialLoginFeatures());
    }

    public function test_has_social_login_features_returns_true_when_enabled(): void
    {
        config(['magic-starter.features' => [Features::socialLogin()]]);

        $this->assertTrue(Features::hasSocialLoginFeatures());
    }

    public function test_only_enabled_features_are_active(): void
    {
        config(['magic-starter.features' => [Features::teams(), Features::sessions()]]);

        $this->assertTrue(Features::hasTeamFeatures());
        $this->assertFalse(Features::hasProfilePhotoFeatures());
        $this->assertTrue(Features::hasSessionFeatures());
        $this->assertFalse(Features::hasSocialLoginFeatures());
        $this->assertFalse(Features::hasTwoFactorAuthenticationFeatures());
    }

    public function test_feature_constants_return_expected_strings(): void
    {
        $this->assertSame('teams', Features::teams());
        $this->assertSame('profile-photos', Features::profilePhotos());
        $this->assertSame('sessions', Features::sessions());
        $this->assertSame('social-login', Features::socialLogin());
    }

    public function test_teams_accepts_options_array(): void
    {
        $this->assertSame('teams', Features::teams(['invitations' => true]));
    }

    public function test_newsletter_subscription_feature_constant(): void
    {
        $this->assertSame('newsletter-subscription', Features::newsletterSubscription());
    }

    public function test_extended_profile_feature_constant(): void
    {
        $this->assertSame('extended-profile', Features::extendedProfile());
    }

    public function test_has_newsletter_subscription_features_returns_false_by_default(): void
    {
        config(['magic-starter.features' => []]);

        $this->assertFalse(Features::hasNewsletterSubscriptionFeatures());
    }

    public function test_has_newsletter_subscription_features_returns_true_when_enabled(): void
    {
        config(['magic-starter.features' => [Features::newsletterSubscription()]]);

        $this->assertTrue(Features::hasNewsletterSubscriptionFeatures());
    }

    public function test_has_extended_profile_features_returns_false_by_default(): void
    {
        config(['magic-starter.features' => []]);

        $this->assertFalse(Features::hasExtendedProfileFeatures());
    }

    public function test_has_extended_profile_features_returns_true_when_enabled(): void
    {
        config(['magic-starter.features' => [Features::extendedProfile()]]);

        $this->assertTrue(Features::hasExtendedProfileFeatures());
    }

    public function test_notification_feature_constant(): void
    {
        $this->assertSame('notifications', Features::notifications());
    }

    public function test_has_notification_features_returns_false_by_default(): void
    {
        config(['magic-starter.features' => []]);

        $this->assertFalse(Features::hasNotificationFeatures());
    }

    public function test_has_notification_features_returns_true_when_enabled(): void
    {
        config(['magic-starter.features' => [Features::notifications()]]);

        $this->assertTrue(Features::hasNotificationFeatures());
    }

    public function test_two_factor_authentication_feature_constant(): void
    {
        $this->assertSame('two-factor-authentication', Features::twoFactorAuthentication());
    }

    public function test_has_two_factor_authentication_features_returns_false_by_default(): void
    {
        config(['magic-starter.features' => []]);

        $this->assertFalse(Features::hasTwoFactorAuthenticationFeatures());
    }

    public function test_has_two_factor_authentication_features_returns_true_when_enabled(): void
    {
        config(['magic-starter.features' => [Features::twoFactorAuthentication()]]);

        $this->assertTrue(Features::hasTwoFactorAuthenticationFeatures());
    }

    public function test_guest_auth_feature_constant(): void
    {
        $this->assertSame('guest-auth', Features::guestAuth());
    }

    public function test_has_guest_auth_features_returns_false_by_default(): void
    {
        config(['magic-starter.features' => []]);

        $this->assertFalse(Features::hasGuestAuthFeatures());
    }

    public function test_has_guest_auth_features_returns_true_when_enabled(): void
    {
        config(['magic-starter.features' => [Features::guestAuth()]]);

        $this->assertTrue(Features::hasGuestAuthFeatures());
    }

    public function test_phone_auth_feature_constant(): void
    {
        $this->assertSame('phone-auth', Features::phoneAuth());
    }

    public function test_has_phone_auth_features_returns_false_by_default(): void
    {
        config(['magic-starter.features' => []]);

        $this->assertFalse(Features::hasPhoneAuthFeatures());
    }

    public function test_has_phone_auth_features_returns_true_when_enabled(): void
    {
        config(['magic-starter.features' => [Features::phoneAuth()]]);

        $this->assertTrue(Features::hasPhoneAuthFeatures());
    }

    public function test_phone_otp_feature_constant(): void
    {
        $this->assertSame('phone-otp', Features::phoneOtp());
    }

    public function test_has_phone_otp_features_returns_false_by_default(): void
    {
        config(['magic-starter.features' => []]);

        $this->assertFalse(Features::hasPhoneOtpFeatures());
    }

    public function test_has_phone_otp_features_returns_true_when_enabled(): void
    {
        config(['magic-starter.features' => [Features::phoneOtp()]]);

        $this->assertTrue(Features::hasPhoneOtpFeatures());
    }
}
