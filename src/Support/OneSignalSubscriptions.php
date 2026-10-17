<?php

namespace FlutterSdk\MagicStarter\Support;

use Closure;
use FlutterSdk\MagicStarter\MagicStarter;
use Illuminate\Database\Eloquent\Model;
use onesignal\client\api\DefaultApi;
use onesignal\client\ApiException;
use onesignal\client\model\PropertiesObject;
use onesignal\client\model\Subscription;
use onesignal\client\model\User as OneSignalUser;
use RuntimeException;
use Throwable;

/**
 * Keeps a user's OneSignal SMS subscription in step with the phone on the account.
 *
 * OneSignal only delivers SMS to users that already carry a registered SMS
 * subscription, and it fans a send out to EVERY SMS subscription the user has.
 * A number left behind after a phone change would therefore keep receiving
 * incident texts, so registration is a reconcile and not a blind create.
 * `ensureSmsSubscription()` runs it lazily, at send time, in this order:
 *
 *  1. `getUser` reads what OneSignal holds. Reading before writing avoids
 *     reading back a user the same call just created; a 404 means OneSignal has
 *     never seen the user and counts as "no subscriptions".
 *  2. Every SMS subscription whose token is not the current phone is deleted,
 *     however many there are, so the outcome never depends on how OneSignal
 *     fans out to several of them.
 *  3. `createUser` (an upsert) registers the current phone, with the user's
 *     language, only when no SMS subscription carries it yet.
 *
 * A subscription that is already registered but DISABLED is an opt-out and is
 * never re-enabled or re-created: doing so without fresh consent breaches
 * carrier rules.
 *
 * The persisted `users.sms_registered_at` guard is set only after all three
 * steps succeeded, so a failure is retried by the next send. The
 * `HasNotifications` trait (`src/Traits/HasNotifications.php`) resets the guard
 * when the phone changes, which is what sends the next SMS back through this
 * reconcile.
 *
 * The user's phone number is PII: it travels only inside the request body. The
 * SDK's `ApiException` message embeds the HTTP response body, which can echo
 * it, so no SDK exception is ever reported, logged or chained as `previous`;
 * a failure is rethrown or reported as a package-composed `RuntimeException`
 * that names only the operation and the HTTP status.
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
     * Whether OneSignal is configured well enough to call its REST API.
     *
     * Callers that would otherwise resolve `DefaultApi`, which throws without a
     * REST key, check this first. The key is read exactly as the service
     * provider reads it when it builds the client.
     */
    public static function provisioned(): bool
    {
        $restApiKey = config('magic-starter.onesignal.rest_api_key')
            ?? config('services.onesignal.rest_api_key');

        return MagicStarter::onesignalAppId() !== null
            && is_string($restApiKey)
            && $restApiKey !== '';
    }

    /**
     * The OneSignal external id (alias value) the user is registered under.
     *
     * Null for a model that does not route notifications to OneSignal.
     */
    public static function externalIdFor(Model $user): ?string
    {
        if (! method_exists($user, 'routeNotificationForOneSignal')) {
            return null;
        }

        $aliases = $user->routeNotificationForOneSignal();

        return $aliases['external_id'][0] ?? null;
    }

    /**
     * Reconcile the user's OneSignal SMS subscription with the current phone.
     *
     * Idempotent: returns immediately when the user was already registered
     * (persisted `sms_registered_at`). Returns true when the current phone is
     * registered and every stale SMS subscription is gone; false when it was
     * skipped (guard set, no phone, OneSignal not configured, no external id)
     * or failed. A failure is reported without throwing, so one send is not
     * poisoned by a transient hiccup, and leaves the guard unset for the next
     * send to retry.
     *
     * @param  Model  $user  A user model using the HasNotifications trait, carrying a phone.
     */
    public function ensureSmsSubscription(Model $user): bool
    {
        // 1. Idempotency guard: never reconcile twice (no GET-per-send).
        if (! empty($user->getAttribute('sms_registered_at'))) {
            return false;
        }

        // 2. Require a phone, a configured app id, and a resolvable external id.
        $rawPhone = $user->getAttribute('phone');
        $phone = is_string($rawPhone) ? trim($rawPhone) : '';
        $appId = MagicStarter::onesignalAppId();
        $externalId = self::externalIdFor($user);

        if ($phone === '' || $appId === null || $externalId === null) {
            return false;
        }

        // 3. Reconcile. Only a package-composed exception can come out of here.
        try {
            $this->reconcile($user, $appId, $externalId, $phone);
        } catch (RuntimeException $exception) {
            report($exception);

            return false;
        }

        // 4. Persist the guard so subsequent sends skip the reconcile.
        $user->forceFill(['sms_registered_at' => now()])->save();

        return true;
    }

    /**
     * Delete the user's SMS subscriptions, except the one carrying `$keepPhone`.
     *
     * Passing null deletes every SMS subscription, which is how a removed phone
     * number stops receiving texts. A no-op when OneSignal has no app id or has
     * never seen the user.
     *
     * @throws RuntimeException On any other failure, composed without the phone so the queue can retry.
     */
    public function pruneSmsSubscriptions(string $externalId, ?string $keepPhone): void
    {
        $appId = MagicStarter::onesignalAppId();

        if ($appId === null) {
            return;
        }

        foreach ($this->smsSubscriptions($appId, $externalId, 'OneSignal SMS prune') as $subscription) {
            if ($subscription->getToken() === $keepPhone) {
                continue;
            }

            $this->deleteSubscription($appId, $subscription, 'OneSignal SMS prune');
        }
    }

    /**
     * Delete the user from OneSignal, which stops every channel at once.
     *
     * A no-op when OneSignal has no app id or the user is already gone.
     *
     * @throws RuntimeException On any other failure, so the queue can retry.
     */
    public function deleteUser(string $externalId): void
    {
        $appId = MagicStarter::onesignalAppId();

        if ($appId === null) {
            return;
        }

        $this->call(
            'OneSignal user delete',
            'deleteUser',
            fn () => $this->client->deleteUser($appId, 'external_id', $externalId),
            notFoundIsFine: true,
        );
    }

    /**
     * Run the three reconcile steps: read, delete stale numbers, register the current one.
     *
     * @throws RuntimeException Composed from the failing operation, never the SDK exception.
     */
    private function reconcile(Model $user, string $appId, string $externalId, string $phone): void
    {
        $registered = false;

        foreach ($this->smsSubscriptions($appId, $externalId, 'OneSignal SMS registration') as $subscription) {
            if ($subscription->getToken() === $phone) {
                // Enabled or not: a disabled one is an opt-out and stays that way.
                $registered = true;

                continue;
            }

            $this->deleteSubscription($appId, $subscription, 'OneSignal SMS registration');
        }

        if ($registered) {
            return;
        }

        $this->createSmsUser($user, $appId, $externalId, $phone);
    }

    /**
     * The user's SMS subscriptions as OneSignal holds them; empty for a user it never saw.
     *
     * @return array<int, Subscription<int|null, mixed>>
     */
    private function smsSubscriptions(string $appId, string $externalId, string $context): array
    {
        $oneSignalUser = $this->call(
            $context,
            'getUser',
            fn () => $this->client->getUser($appId, 'external_id', $externalId),
            notFoundIsFine: true,
        );

        if (! $oneSignalUser instanceof OneSignalUser) {
            return [];
        }

        return array_values(array_filter(
            $oneSignalUser->getSubscriptions() ?? [],
            fn (Subscription $subscription): bool => $subscription->getType() === Subscription::TYPE_SMS,
        ));
    }

    /**
     * @param  Subscription<int|null, mixed>  $subscription
     */
    private function deleteSubscription(string $appId, Subscription $subscription, string $context): void
    {
        $this->call(
            $context,
            'deleteSubscription',
            fn () => $this->client->deleteSubscription($appId, (string) $subscription->getId()),
            notFoundIsFine: true,
        );
    }

    /**
     * Upsert the OneSignal user with the phone as its only SMS subscription.
     */
    private function createSmsUser(Model $user, string $appId, string $externalId, string $phone): void
    {
        $subscription = new Subscription;
        $subscription->setType(Subscription::TYPE_SMS);
        $subscription->setToken($phone);
        $subscription->setEnabled(true);

        $properties = new PropertiesObject;
        $properties->setLanguage(MailLocale::for($user));

        $body = new OneSignalUser;
        $body->setIdentity(['external_id' => $externalId]);
        $body->setSubscriptions([$subscription]);
        $body->setProperties($properties);

        $this->call(
            'OneSignal SMS registration',
            'createUser',
            fn () => $this->client->createUser($appId, $body),
        );
    }

    /**
     * Run one SDK call, translating every failure into a message-safe exception.
     *
     * An HTTP 404 returns null when `$notFoundIsFine` (the user or subscription
     * is already gone, which is the outcome the caller wanted).
     *
     * @throws RuntimeException Names the operation and the HTTP status, with no `previous`.
     */
    private function call(string $context, string $operation, Closure $request, bool $notFoundIsFine = false): mixed
    {
        try {
            return $request();
        } catch (Throwable $exception) {
            if ($notFoundIsFine && $exception instanceof ApiException && $exception->getCode() === 404) {
                return null;
            }

            $detail = $exception instanceof ApiException
                ? "{$operation} returned HTTP {$exception->getCode()}"
                : "{$operation} threw " . $exception::class;

            throw new RuntimeException("{$context} failed: {$detail}");
        }
    }
}
