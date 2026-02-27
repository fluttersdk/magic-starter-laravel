<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\SendsOtpCodes;
use Illuminate\Support\Facades\Log;

/**
 * OTP provider implementation that logs the code to the system logger.
 */
class LogOtpProvider implements SendsOtpCodes
{
    /**
     * Send an OTP code to a phone number.
     *
     * @param  string  $phone  The phone number in E.164 format.
     * @param  string  $code  The OTP code to send.
     */
    public function send(string $phone, string $code): void
    {
        Log::info("OTP for {$phone}: {$code}");
    }
}
