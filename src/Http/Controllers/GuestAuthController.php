<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Handle guest authentication.
 */
class GuestAuthController extends Controller
{
    /**
     * Authenticate a guest user.
     */
    public function login(): JsonResponse
    {
        return response()->json([
            'message' => 'Not implemented',
        ], 501);
    }
}
