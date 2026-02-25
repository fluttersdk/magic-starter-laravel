<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Manages database notifications for the authenticated user.
 */
class NotificationController
{
    /**
     * Retrieve a paginated list of user notifications.
     */
    public function index(): AnonymousResourceCollection
    {
        $perPage = request()->input('per_page', 15);

        return NotificationResource::collection(
            request()->user()->notifications()->paginate($perPage),
        );
    }

    /**
     * Get the count of unread notifications.
     */
    public function unreadCount(): JsonResponse
    {
        $count = request()->user()->unreadNotifications()->count();

        return response()->json([
            'data' => [
                'count' => $count,
            ],
        ]);
    }

    /**
     * Mark a specific notification as read.
     */
    public function markAsRead(string $id): JsonResponse
    {
        $notification = request()->user()->notifications()->findOrFail($id);

        $notification->markAsRead();

        return response()->json([
            'data' => null,
            'message' => 'Notification marked as read.',
        ]);
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllAsRead(): JsonResponse
    {
        request()->user()
            ->unreadNotifications()
            ->update(['read_at' => now()]);

        return response()->json([
            'data' => null,
            'message' => 'All notifications marked as read.',
        ]);
    }

    /**
     * Delete a specific notification.
     */
    public function destroy(string $id): JsonResponse
    {
        $notification = request()->user()->notifications()->findOrFail($id);

        $notification->delete();

        return response()->json([
            'data' => null,
            'message' => 'Notification deleted.',
        ]);
    }
}
