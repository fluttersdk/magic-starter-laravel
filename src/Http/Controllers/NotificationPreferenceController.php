<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Http\Requests\UpdateNotificationPreferenceRequest;
use FlutterSdk\MagicStarter\Models\NotificationSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manages notification preference settings for the authenticated user.
 *
 * Returns a type × channel matrix and supports single or bulk preference updates.
 *
 * Both responses carry `meta.push_provisioned`, so a client can tell the user
 * that a push toggle cannot deliver yet (the app has no OneSignal `app_id`)
 * without a dedicated status route or a team-scoped lookup.
 */
class NotificationPreferenceController
{
    /**
     * Show the full notification preference matrix for the authenticated user.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load('notificationSettings');

        return response()->json([
            'data' => $user->notificationPreferenceMatrix(),
            'meta' => $this->meta(),
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
            'meta' => $this->meta(),
        ]);
    }

    /**
     * Build the response meta describing what the matrix can actually deliver.
     *
     * `push_provisioned` reports whether the app configured its OneSignal
     * `app_id`. A push preference is offered as soon as the onesignal feature
     * is enabled, but without an app id the channel is dropped from `via()` at
     * send time, so a client that shows the toggle needs this flag to say so
     * instead of promising a delivery that never happens.
     *
     * @return array<string, bool>
     */
    private function meta(): array
    {
        return [
            'push_provisioned' => filled(config('magic-starter.onesignal.app_id')),
        ];
    }
}
