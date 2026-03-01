<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\MagicStarter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handles email verification link delivery and email address verification.
 *
 * The `sendVerificationNotification` endpoint requires authentication (auth:sanctum)
 * and is rate-limited. The `verify` endpoint is public but protected by a signed URL.
 */
class EmailVerificationController
{
    /**
     * Send an email verification notification to the authenticated user.
     *
     * @param  Request  $request  The incoming HTTP request.
     * @return JsonResponse
     */
    public function sendVerificationNotification(Request $request): JsonResponse
    {
        // 1. Skip if already verified — no point re-sending.
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.'], 200);
        }

        // 2. Guard against users with no email address.
        if (empty($request->user()->email)) {
            return response()->json(['message' => 'No email address to verify.'], 400);
        }

        // 3. Send the signed verification URL via email.
        $request->user()->sendEmailVerificationNotification();

        return response()->json(['message' => 'Verification link sent.'], 202);
    }

    /**
     * Mark the authenticated user's email address as verified.
     *
     * The route is protected by a signed URL — no auth:sanctum required.
     * The hash parameter is validated against the user's current email address
     * to prevent link re-use after an email change.
     *
     * @param  Request  $request  The incoming HTTP request.
     * @param  string  $id  The user's primary key.
     * @param  string  $hash  The SHA-1 hash of the user's email address.
     * @return JsonResponse
     */
    public function verify(Request $request, string $id, string $hash): JsonResponse
    {
        // 1. Resolve the user by ID — 404 if not found.
        $user = app(MagicStarter::userModel())->findOrFail($id);

        // 2. Validate the hash to ensure the link matches the current email.
        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return response()->json(['message' => 'Invalid verification link.'], 403);
        }

        // 3. Skip if already verified — idempotent response.
        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.'], 200);
        }

        // 4. Mark as verified — the MustVerifyEmail trait fires the Verified event internally.
        $user->markEmailAsVerified();

        return response()->json(['message' => 'Email verified successfully.'], 200);
    }
}