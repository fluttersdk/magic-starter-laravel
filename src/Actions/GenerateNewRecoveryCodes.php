<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\GeneratesNewRecoveryCodes;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class GenerateNewRecoveryCodes implements GeneratesNewRecoveryCodes
{
    /**
     * Generate new recovery codes for the user.
     *
     * @return array<int, string>
     */
    public function generate(mixed $user): array
    {
        $codes = Collection::times(
            (int) config('magic-starter.two_factor.recovery_codes_count', 8),
            fn () => Str::random(10) . '-' . Str::random(10),
        )->toArray();

        $user->forceFill([
            'two_factor_recovery_codes' => encrypt(json_encode($codes)),
        ])->save();

        return $codes;
    }
}
