<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Handle OTP send and verify operations.
 */
class OtpController extends Controller
{
    /**
     * Send an OTP code to the provided phone number.
     */
    public function send(): JsonResponse
    {
        return response()->json([
            'message' => 'Not implemented',
        ], 501);
    }

    /**
     * Verify the provided OTP code.
     */
    public function verify(): JsonResponse
    {
        return response()->json([
            'message' => 'Not implemented',
        ], 501);
    }
}
