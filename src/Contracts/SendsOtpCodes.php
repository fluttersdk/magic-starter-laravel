<?php

namespace FlutterSdk\MagicStarter\Contracts;

/**
 * Contract for sending OTP codes via a provider.
 */
interface SendsOtpCodes
{
    /**
     * Send an OTP code to a phone number.
     *
     * @param  string  $phone  The phone number in E.164 format.
     * @param  string  $code  The OTP code to send.
     */
    public function send(string $phone, string $code): void;
}
