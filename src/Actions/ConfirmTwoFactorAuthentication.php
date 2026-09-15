<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\ConfirmsTwoFactorAuthentication;
use FlutterSdk\MagicStarter\Support\TwoFactorAuthenticationProvider;
use Illuminate\Validation\ValidationException;

class ConfirmTwoFactorAuthentication implements ConfirmsTwoFactorAuthentication
{
    /**
     * Create a new action instance.
     */
    public function __construct(
        protected TwoFactorAuthenticationProvider $provider,
    ) {}

    /**
     * Confirm two factor authentication for the user.
     *
     *
     * @throws ValidationException
     */
    public function confirm(mixed $user, string $code): void
    {
        $secret = $user->twoFactorSecret();

        // 1. Validate the secret exists.
        if ($secret === null) {
            throw ValidationException::withMessages([
                'code' => [__('magic-starter::auth.two_factor.not_yet_enabled')],
            ]);
        }

        // 2. Verify the code using the provider.
        if (! $this->provider->verify($secret, $code)) {
            throw ValidationException::withMessages([
                'code' => [__('magic-starter::auth.two_factor.invalid_code')],
            ]);
        }

        // 3. Mark the 2FA as confirmed.
        $user->forceFill([
            'two_factor_confirmed_at' => now(),
        ])->save();
    }
}
