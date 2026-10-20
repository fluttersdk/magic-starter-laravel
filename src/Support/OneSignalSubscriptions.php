<?php

namespace FlutterSdk\MagicStarter\Support;

use Closure;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\PushDevice;
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
 *  2. Every ENABLED SMS subscription whose token is not the current phone is
 *     deleted, however many there are, so the outcome never depends on how
 *     OneSignal fans out to several of them.
 *  3. `createUser` (an upsert) registers the current phone, with the user's
 *     language, only when no SMS subscription carries it yet.
 *
 * A subscription that is DISABLED is a carrier opt-out (STOP). It is never
 * re-enabled, never re-created and never deleted: deleting it would wipe
 * OneSignal's record, so a user returning to that number would be registered as
 * enabled again by `createUser`, which breaches carrier rules. It delivers
 * nothing, so keeping it costs nothing. One that carries the CURRENT phone
 * still counts as registered.
 *
 * Phones are compared and sent in a normalised form (spaces, dashes, dots and
 * brackets stripped), because an admin form or consumer code stores the number
 * as typed (`+1 (555) 123-4567`) while OneSignal holds the bare `+15551234567`.
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
        return MagicStarter::onesignalAppId() !== null
            && MagicStarter::onesignalRestApiKey() !== null;
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
        $phone = is_string($rawPhone) ? $this->normalisePhone($rawPhone) : '';
        $appId = MagicStarter::onesignalAppId();
        $externalId = PushDevice::externalIdFor($user);

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
     * Delete the user's enabled SMS subscriptions, except the one carrying `$keepPhone`.
     *
     * Passing null (or a blank phone) deletes every enabled SMS subscription,
     * which is how a removed phone number stops receiving texts. A disabled one
     * is a carrier opt-out and stays on record. A no-op when OneSignal has no
     * app id or has never seen the user.
     *
     * @throws RuntimeException On any other failure, composed without the phone so the queue can retry.
     */
    public function pruneSmsSubscriptions(string $externalId, ?string $keepPhone): void
    {
        $appId = MagicStarter::onesignalAppId();

        if ($appId === null) {
            return;
        }

        $keep = $keepPhone === null ? '' : $this->normalisePhone($keepPhone);

        foreach ($this->smsSubscriptions($appId, $externalId, 'OneSignal SMS prune') as $subscription) {
            if ($subscription->getEnabled() === false || ($keep !== '' && $this->tokenOf($subscription) === $keep)) {
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
     * Run the three reconcile steps: read, delete enabled stale numbers, register the current one.
     *
     * @throws RuntimeException Composed from the failing operation, never the SDK exception.
     */
    private function reconcile(Model $user, string $appId, string $externalId, string $phone): void
    {
        $registered = false;

        foreach ($this->smsSubscriptions($appId, $externalId, 'OneSignal SMS registration') as $subscription) {
            if ($this->tokenOf($subscription) === $phone) {
                // Enabled or not: a disabled one is an opt-out and stays that way.
                $registered = true;

                continue;
            }

            // A disabled stale one is an opt-out whose record must survive.
            if ($subscription->getEnabled() === false) {
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
        $properties->setLanguage($this->primaryLanguage(MailLocale::for($user)));

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
     * Strip the formatting a person types into a phone (spaces, dashes, dots, brackets).
     */
    private function normalisePhone(string $phone): string
    {
        return (string) preg_replace('/[\s\-().]/', '', $phone);
    }

    /**
     * The normalised token of a subscription, empty when OneSignal holds none.
     *
     * @param  Subscription<int|null, mixed>  $subscription
     */
    private function tokenOf(Subscription $subscription): string
    {
        return $this->normalisePhone((string) $subscription->getToken());
    }

    /**
     * The lower-cased primary subtag of a locale (`en_US` and `en-US` become `en`).
     */
    private function primaryLanguage(string $locale): string
    {
        return strtolower(preg_split('/[-_]/', $locale)[0]);
    }

    /**
     * Run one SDK call, translating every failure into a message-safe exception.
     *
     * An HTTP 404 returns null when `$notFoundIsFine` (the user or subscription
     * is already gone, which is the outcome the caller wanted). An `Error` is
     * caught as well: the SDK raises a `TypeError` when it deserialises a
     * response shape it does not expect, and letting it through would break the
     * consumer's send that `ensureSmsSubscription()` promises never to poison.
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
