<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Http\Requests\StorePushDeviceStateRequest;
use FlutterSdk\MagicStarter\Models\PushDevice;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives what a client's device knows about its own push delivery state.
 *
 * The permission, the opt-in flag and the subscription id live on the device
 * and nowhere else, and OneSignal accepts a push for an unreachable
 * subscription without complaint, so this report is the only evidence the
 * server has. {@see PushDevice::canReachByPush()} reads it.
 *
 * Both routes carry no user segment and both write under the session's user,
 * which is the authorisation: there is no address in the request for a caller
 * to point at somebody else's device.
 */
class PushDeviceController
{
    /**
     * Record one device's push delivery state for the signed-in user.
     *
     * Upserted per (user, subscription id), because one person may carry
     * several devices and a laptop that cannot be paged must not overwrite the
     * phone that can. Answers 204: the client posts this as a side effect of a
     * lifecycle event and reads nothing back.
     */
    public function store(StorePushDeviceStateRequest $request): Response
    {
        $attributes = $request->validated();

        PushDevice::query()->updateOrCreate(
            [
                'user_id' => $request->user()->getKey(),
                // A blank string and a null are the same fact (no address), and
                // keying on both would give one device two rows.
                'subscription_id' => self::blankToNull($attributes['subscription_id']),
            ],
            [
                'external_id' => self::blankToNull($attributes['external_id']),
                'reachability' => $attributes['reachability'],
                'captured_at' => $attributes['captured_at'],
                // Freshness is measured on the server's clock; `captured_at` is
                // the device's claim and a wrong device clock must not decide
                // how long the row stays trusted.
                'reported_at' => now(),
            ],
        );

        return response()->noContent();
    }

    /**
     * Stop the caller's device vouching for them, because the person on it is
     * signing out.
     *
     * A sign-out is an event, not a reading, so it is a verb of its own rather
     * than a report describing a state the SDK has not reached yet. The body
     * names the device and nothing else. `subscription_id` is required: a body
     * without one names no device, and reading it as "release all of mine"
     * would strand the caller's other handsets.
     *
     * Answers 204 when a row of the caller's was removed, and 404 otherwise.
     * Another user's subscription id is a 404 exactly like an unknown one, so
     * the answer confirms nothing about rows the caller does not own.
     */
    public function release(Request $request): Response
    {
        $validated = $request->validate([
            'subscription_id' => [
                'required',
                'string',
                'max:255',
            ],
        ]);

        abort_unless(PushDevice::release($request->user(), $validated['subscription_id']), 404);

        return response()->noContent();
    }

    /**
     * An empty string is the same absence a null is, and is stored as one.
     */
    private static function blankToNull(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
