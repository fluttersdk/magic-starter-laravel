<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Http\Requests\UpdateNotificationPreferenceRequest;
use FlutterSdk\MagicStarter\Models\NotificationSetting;
use Illuminate\Http\JsonResponse;

/**
 * Manages notification preference settings for the authenticated user.
 *
 * Returns a type × channel matrix and supports single or bulk preference updates.
 */
class NotificationPreferenceController
{
    /**
     * Show the full notification preference matrix for the authenticated user.
     */
    public function show(): JsonResponse
    {
        $user = request()->user();
        $user->load('notificationSettings');

        return response()->json([
            'data' => $user->notificationPreferenceMatrix(),
        ]);
    }

    /**
     * Update notification preferences (single or bulk).
     *
     * Accepts either a single `{type, channel, is_enabled}` payload or
     * a bulk `{preferences: [{type, channel, is_enabled}, ...]}` payload.
     */
    public function update(UpdateNotificationPreferenceRequest $request): JsonResponse
    {
        $user = $request->user();

        // 1. Normalize input into an array of preference items.
        $items = $request->has('preferences')
            ? $request->input('preferences')
            : [
                [
                    'type' => $request->input('type'),
                    'channel' => $request->input('channel'),
                    'is_enabled' => $request->input('is_enabled'),
                ],
            ];

        // 2. Upsert each preference override.
        foreach ($items as $item) {
            NotificationSetting::updateOrCreate(
                [
                    'notifiable_id' => $user->getKey(),
                    'notifiable_type' => $user->getMorphClass(),
                    'type' => $item['type'],
                    'channel' => $item['channel'],
                ],
                [
                    'is_enabled' => $item['is_enabled'],
                ],
            );
        }

        // 3. Reload settings and return updated matrix.
        $user->load('notificationSettings');

        return response()->json([
            'data' => $user->notificationPreferenceMatrix(),
        ]);
    }
}
