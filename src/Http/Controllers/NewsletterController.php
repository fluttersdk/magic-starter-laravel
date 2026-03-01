<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Models\NewsletterSubscriber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manages newsletter subscription status for the authenticated user.
 *
 * Provides a boolean subscribe/unsubscribe toggle keyed to the user's email.
 * Does not expose email in the response — the user already knows their own address.
 */
class NewsletterController
{
    /**
     * Show the current newsletter subscription status for the authenticated user.
     */
    public function show(Request $request): JsonResponse
    {
        $subscriber = NewsletterSubscriber::where('email', $request->user()->email)->first();

        if ($subscriber) {
            return response()->json([
                'subscribed' => (bool) $subscriber->is_active,
                'source' => $subscriber->source,
                'subscribed_at' => $subscriber->created_at,
            ]);
        }

        return response()->json(['subscribed' => false]);
    }

    /**
     * Update the newsletter subscription status for the authenticated user.
     *
     * Creates a subscriber record with source='profile' when subscribing for the first time.
     * Requires the user to have an email address — returns 400 otherwise.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subscribe' => [
                'required',
                'boolean',
            ],
        ]);

        // 1. Guard — newsletter subscription requires an email address.
        if ($request->user()->email === null) {
            return response()->json(
                ['message' => 'Email address required for newsletter subscription.'],
                400,
            );
        }

        $subscriber = null;

        // 2. Subscribe: upsert record and ensure is_active is true.
        if ($validated['subscribe'] === true) {
            $subscriber = NewsletterSubscriber::firstOrCreate(
                ['email' => $request->user()->email],
                [
                    'source' => 'profile',
                    'is_active' => true,
                ],
            );
            $subscriber->update(['is_active' => true]);
        }

        // 3. Unsubscribe: flip is_active to false without deleting the record.
        if ($validated['subscribe'] === false) {
            $subscriber = NewsletterSubscriber::where('email', $request->user()->email)->first();
            if ($subscriber) {
                $subscriber->update(['is_active' => false]);
            }
        }

        // 4. Return the updated status in the same shape as show().
        $subscriber = $subscriber ?? NewsletterSubscriber::where('email', $request->user()->email)->first();

        if ($subscriber) {
            return response()->json([
                'subscribed' => (bool) $subscriber->is_active,
                'source' => $subscriber->source,
                'subscribed_at' => $subscriber->created_at,
            ]);
        }

        return response()->json(['subscribed' => false]);
    }
}
