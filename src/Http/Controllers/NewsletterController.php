<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Models\NewsletterSubscriber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manages newsletter subscription status for the authenticated user.
 *
 * Provides a boolean subscribe/unsubscribe toggle on the user's own subscription,
 * which records the consent behind it. Does not expose email in the response:
 * the user already knows their own address.
 */
class NewsletterController
{
    /**
     * Show the current newsletter subscription status for the authenticated user.
     */
    public function show(Request $request): JsonResponse
    {
        return $this->status(NewsletterSubscriber::forUser($request->user()));
    }

    /**
     * Update the newsletter subscription status for the authenticated user.
     *
     * Subscribing records the consent with source='profile'.
     * Requires the user to have an email address; returns 400 otherwise.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subscribe' => [
                'required',
                'boolean',
            ],
        ]);

        $user = $request->user();

        // 1. Guard: newsletter subscription requires an email address.
        if ($user->email === null) {
            return response()->json(
                ['message' => __('magic-starter::profile.newsletter.email_required')],
                400,
            );
        }

        // 2. Subscribe records the consent; unsubscribe keeps the row and its token. The `boolean`
        //    rule also passes 1, '1', 0 and '0', so the branch reads the value as a boolean too.
        if (filter_var($validated['subscribe'], FILTER_VALIDATE_BOOLEAN)) {
            $subscriber = NewsletterSubscriber::subscribeUser($user, 'profile');
        } else {
            $subscriber = NewsletterSubscriber::forUser($user);
            $subscriber?->unsubscribe('profile');
        }

        // 3. Return the updated status in the same shape as show().
        return $this->status($subscriber);
    }

    private function status(?NewsletterSubscriber $subscriber): JsonResponse
    {
        if ($subscriber === null) {
            return response()->json(['subscribed' => false]);
        }

        return response()->json([
            'subscribed' => (bool) $subscriber->is_active,
            'source' => $subscriber->source,
            'subscribed_at' => $subscriber->consented_at ?? $subscriber->created_at,
        ]);
    }
}
