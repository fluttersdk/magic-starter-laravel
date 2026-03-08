<?php

namespace FlutterSdk\MagicStarter\Traits;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use FlutterSdk\MagicStarter\Support\TwoFactorAuthenticationProvider;
use Illuminate\Support\Str;

/**
 * Trait TwoFactorAuthenticatable
 *
 * Adds two-factor authentication capabilities to a User model.
 *
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property \Illuminate\Support\Carbon|null $two_factor_confirmed_at
 * @property string $email
 */
trait TwoFactorAuthenticatable
{
    /**
     * Determine if two-factor authentication has been enabled.
     */
    public function hasEnabledTwoFactorAuthentication(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    /**
     * Get the user's two factor authentication secret.
     */
    public function twoFactorSecret(): ?string
    {
        if (! $this->two_factor_secret) {
            return null;
        }

        return decrypt($this->two_factor_secret);
    }

    /**
     * Get the user's two factor recovery codes.
     *
     * @return array<int, string>
     */
    public function recoveryCodes(): array
    {
        if (! $this->two_factor_recovery_codes) {
            return [];
        }

        return json_decode(decrypt($this->two_factor_recovery_codes), true) ?: [];
    }

    /**
     * Get the number of remaining recovery codes.
     */
    public function twoFactorRecoveryCodesCount(): int
    {
        return count($this->recoveryCodes());
    }

    /**
     * Replace the given recovery code with a new one in the user's stored codes.
     */
    public function replaceRecoveryCode(string $code): void
    {
        $codes = $this->recoveryCodes();

        if (($key = array_search($code, $codes, true)) !== false) {
            $codes[$key] = Str::random(10) . '-' . Str::random(10);
        }

        $this->forceFill([
            'two_factor_recovery_codes' => encrypt(json_encode(array_values($codes))),
        ])->save();
    }

    /**
     * Get the two factor authentication QR code URL.
     */
    public function twoFactorQrCodeUrl(): string
    {
        return app(TwoFactorAuthenticationProvider::class)->qrCodeUrl(
            config('magic-starter.two_factor.company_name', config('app.name')),
            $this->email,
            $this->twoFactorSecret() ?? '',
        );
    }

    /**
     * Get the two factor authentication QR code SVG.
     */
    public function twoFactorQrCodeSvg(): string
    {
        $svg = (new Writer(
            new ImageRenderer(
                new RendererStyle(192),
                new SvgImageBackEnd,
            ),
        ))->writeString($this->twoFactorQrCodeUrl());

        return trim(substr($svg, strpos($svg, "\n") + 1));
    }
}
