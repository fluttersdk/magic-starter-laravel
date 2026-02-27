<?php

namespace FlutterSdk\MagicStarter\Contracts;

/**
 * Contract for verifying OTP codes.
 */
interface VerifiesOtpCodes
{
    /**
     * Verify an OTP code for a phone number.
     *
     * @param  string  $phone  The phone number in E.164 format.
     * @param  string  $code  The OTP code to verify.
     * @return bool True if verification succeeds, false otherwise.
     */
    public function verify(string $phone, string $code): bool;
}
