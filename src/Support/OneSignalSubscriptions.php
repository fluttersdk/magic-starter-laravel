<?php

namespace FlutterSdk\MagicStarter\Support;

use Illuminate\Database\Eloquent\Model;
use onesignal\client\api\DefaultApi;
use onesignal\client\model\Subscription;
use onesignal\client\model\SubscriptionBody;
use Throwable;

/**
 * On-demand OneSignal SMS subscription registration.
 *
 * OneSignal only delivers SMS to users that already carry a registered SMS
 * subscription. This helper registers that subscription the first time a user
 * is about to be SMSed, guarded by a persisted `users.sms_registered_at`
 * timestamp so it never issues a redundant call on every send.
 *
 * The user's phone number is PII: it travels only inside the request body and
 * is never written to a log or an exception message by this class.
 *
 * Registration is CONSUMER-INVOKED, not automatic: the package has no hook that
 * fires "a notification is about to SMS this user", so the notification that
 * builds the SMS payload calls this first. The `onesignal-sms` channel then
 * targets an already-registered subscription:
 *
 * ```php
 * public function toSms(mixed $notifiable): OneSignalNotification
 * {
 *     app(OneSignalSubscriptions::class)->ensureSmsSubscription($notifiable);
 *
 *     // ... build the sms-targeted payload
 * }
 * ```
 */
class OneSignalSubscriptions
{
    public function __construct(
        private DefaultApi $client,
    ) {}

    /**
     * Ensure the user has a registered OneSignal SMS subscription.
     *
     * Idempotent: returns immediately when the user was already registered
     * (persisted `sms_registered_at`). Returns true only when a subscription
     * was registered during this call; false when it was skipped or failed.
     *
     * @param  Model  $user  A user model using the HasNotifications trait, carrying a phone.
     */
    public function ensureSmsSubscription(Model $user): bool
    {
        // 1. Idempotency guard: never re-register (no GET-per-send).
        if (! empty($user->sms_registered_at)) {
            return false;
        }

        // 2. Require a phone, a configured app id, and a resolvable external id.
        $phone = is_string($user->phone ?? null) ? trim($user->phone) : '';
        $appId = config('magic-starter.onesignal.app_id');
        $externalId = $this->resolveExternalId($user);

        if ($phone === '' || ! is_string($appId) || trim($appId) === '' || $externalId === null) {
            return false;
        }

        // 3. Build the SMS subscription body: {subscription:{type:SMS,token:<phone>,enabled:true}}.
        $subscription = new Subscription;
        $subscription->setType(Subscription::TYPE_SMS);
        $subscription->setToken($phone);
        $subscription->setEnabled(true);

        $body = new SubscriptionBody;
        $body->setSubscription($subscription);

        // 4. Register. Both 200 (new) and 202 (transfer) return normally; the SDK
        //    throws on any non-2xx. Report the failure (the phone is never in the
        //    message) without throwing so a single send is not poisoned by a
        //    transient registration hiccup, and leave the guard unset for retry.
        try {
            $this->client->createSubscription((string) $appId, 'external_id', $externalId, $body);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }

        // 5. Persist the guard so subsequent sends skip registration.
        $user->forceFill(['sms_registered_at' => now()])->save();

        return true;
    }

    /**
     * Resolve the OneSignal external id (alias value) for the user.
     */
    private function resolveExternalId(Model $user): ?string
    {
        if (! method_exists($user, 'routeNotificationForOneSignal')) {
            return null;
        }

        $aliases = $user->routeNotificationForOneSignal();

        return $aliases['external_id'][0] ?? null;
    }
}
