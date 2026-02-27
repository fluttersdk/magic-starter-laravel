<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Handle phone-based authentication.
 */
class PhoneAuthController extends Controller
{
    /**
     * Register a new user via phone.
     */
    public function register(): JsonResponse
    {
        return response()->json([
            'message' => 'Not implemented',
        ], 501);
    }

    /**
     * Authenticate a user via phone.
     */
    public function login(): JsonResponse
    {
        return response()->json([
            'message' => 'Not implemented',
        ], 501);
    }
}
