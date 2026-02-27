<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\VerifiesOtpCodes;
use Illuminate\Support\Facades\Cache;

/**
 * OTP verifier implementation using the system cache.
 */
class CacheOtpVerifier implements VerifiesOtpCodes
{
    /**
     * Verify an OTP code for a phone number.
     *
     * @param  string  $phone  The phone number in E.164 format.
     * @param  string  $code  The OTP code to verify.
     * @return bool True if verification succeeds, false otherwise.
     */
    public function verify(string $phone, string $code): bool
    {
        // 1. Pull the code from cache to ensure it's consumed after one use.
        $storedCode = Cache::pull('otp_' . $phone);

        // 2. Return comparison result.
        return $storedCode !== null && $storedCode === $code;
    }
}
