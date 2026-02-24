<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Http\Resources\SessionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Manages Sanctum personal access tokens as user sessions.
 */
class SessionController
{
    public function index(): AnonymousResourceCollection
    {
        return SessionResource::collection(request()->user()->tokens);
    }

    public function destroy(string $tokenId): JsonResponse
    {
        $token = request()->user()
            ->tokens()
            ->where('id', $tokenId)
            ->first();

        if (! $token) {
            abort(404, 'Session not found.');
        }

        $token->delete();

        return response()->json(['message' => 'Session revoked successfully.']);
    }

    public function destroyOther(): JsonResponse
    {
        $user = request()->user();
        $currentId = $user->currentAccessToken()->id;

        $user->tokens()
            ->where('id', '!=', $currentId)
            ->delete();

        return response()->json(['message' => 'Other sessions revoked successfully.']);
    }
}
