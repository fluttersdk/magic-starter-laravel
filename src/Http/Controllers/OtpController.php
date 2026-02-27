<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Contracts\SendsOtpCodes;
use FlutterSdk\MagicStarter\Contracts\VerifiesOtpCodes;
use FlutterSdk\MagicStarter\Http\Controllers\Concerns\AuthenticatesUsers;
use FlutterSdk\MagicStarter\Http\Requests\SendOtpRequest;
use FlutterSdk\MagicStarter\Http\Requests\VerifyOtpRequest;
use FlutterSdk\MagicStarter\MagicStarter;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;

/**
 * Handle OTP send and verify operations.
 */
class OtpController extends Controller
{
    use AuthenticatesUsers;

    /**
     * Send an OTP code to the provided phone number.
     */
    public function send(SendOtpRequest $request): JsonResponse
    {
        $phone = $request->validated('phone');
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Cache::put('otp_' . $phone, $code, 300); // 5 minutes TTL

        app(SendsOtpCodes::class)->send($phone, $code);

        return response()->json([
            'message' => 'OTP sent successfully',
        ]);
    }

    /**
     * Verify the provided OTP code.
     */
    public function verify(VerifyOtpRequest $request): JsonResponse
    {
        $phone = $request->validated('phone');
        $code = $request->validated('code');

        $isValid = app(VerifiesOtpCodes::class)->verify($phone, $code);

        if (! $isValid) {
            return response()->json([
                'message' => 'Invalid or expired OTP',
            ], 401);
        }

        $userModel = MagicStarter::userModel();
        $user = $userModel::query()->where('phone', $phone)->first();

        if (! $user) {
            return response()->json([
                'message' => 'User not found',
            ], 404);
        }

        return $this->authenticatedResponse($user, $request, $this->createAuthToken($user, $request));
    }
}
