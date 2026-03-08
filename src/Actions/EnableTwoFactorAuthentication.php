<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\EnablesTwoFactorAuthentication;
use FlutterSdk\MagicStarter\Support\TwoFactorAuthenticationProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class EnableTwoFactorAuthentication implements EnablesTwoFactorAuthentication
{
    /**
     * Create a new action instance.
     */
    public function __construct(
        protected TwoFactorAuthenticationProvider $provider,
    ) {}

    /**
     * Enable two factor authentication for the user.
     *
     * @return array<string, mixed>
     */
    public function enable(mixed $user): array
    {
        // 1. Generate the TOTP secret key.
        $secret = $this->provider->generateSecretKey();

        // 2. Generate recovery codes.
        $codes = Collection::times(
            (int) config('magic-starter.two_factor.recovery_codes_count', 8),
            fn () => Str::random(10) . '-' . Str::random(10),
        )->toArray();

        // 3. Store encrypted secret and codes, clearing any existing confirmation.
        $user->forceFill([
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => encrypt(json_encode($codes)),
            'two_factor_confirmed_at' => null,
        ])->save();

        // 4. Return data needed for enrollment UI.
        return [
            'secret' => $secret,
            'qr_url' => $user->twoFactorQrCodeUrl(),
            'recovery_codes' => $codes,
        ];
    }
}
