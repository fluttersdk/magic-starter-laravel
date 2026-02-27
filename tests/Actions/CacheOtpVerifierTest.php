<?php

namespace FlutterSdk\MagicStarter\Tests\Actions;

use FlutterSdk\MagicStarter\Actions\CacheOtpVerifier;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Support\Facades\Cache;

class CacheOtpVerifierTest extends TestCase
{
    /**
     * Test that OTP can be verified and is consumed after use.
     */
    public function test_otp_verification_consumes_value(): void
    {
        $phone = '+905554443322';
        $code = '123456';
        Cache::put('otp_' . $phone, $code);

        $verifier = new CacheOtpVerifier;

        // 1. Correct code returns true.
        $this->assertTrue($verifier->verify($phone, $code));

        // 2. Code is consumed (pulled), second call returns false.
        $this->assertFalse($verifier->verify($phone, $code));
    }

    /**
     * Test that wrong OTP returns false.
     */
    public function test_wrong_otp_returns_false(): void
    {
        $phone = '+905554443322';
        $code = '123456';
        Cache::put('otp_' . $phone, $code);

        $verifier = new CacheOtpVerifier;

        $this->assertFalse($verifier->verify($phone, '654321'));

        // Even if wrong, it should be consumed to prevent brute force?
        // Instructions said Cache::pull consumes the value.
        $this->assertFalse(Cache::has('otp_' . $phone));
    }
}
