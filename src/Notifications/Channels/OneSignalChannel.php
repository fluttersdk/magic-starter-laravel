<?php

namespace FlutterSdk\MagicStarter\Notifications\Channels;

use FlutterSdk\MagicStarter\MagicStarter;
use Illuminate\Notifications\Notification;
use InvalidArgumentException;
use onesignal\client\api\DefaultApi;
use onesignal\client\model\CreateNotificationSuccessResponse;
use onesignal\client\model\Notification as OneSignalNotification;
use RuntimeException;
use Throwable;

/**
 * OneSignal push notification channel using the official v5 PHP SDK.
 *
 * This channel sends push notifications via alias-based targeting. The OneSignal v5 API
 * uses "aliases" (e.g. external_id, onesignal_id) to identify recipients instead of
 * legacy player IDs. When no explicit aliases or segments are set on the notification
 * payload, this channel automatically resolves aliases from the notifiable entity.
 *
 * Notification classes that wish to use this channel must implement a builder
 * method (default `toOneSignal()`) returning an `\onesignal\client\model\Notification`:
 *
 *     public function toOneSignal(mixed $notifiable): \onesignal\client\model\Notification
 *
 * The builder method is configurable so a single channel implementation can back
 * multiple driver channels: the package registers `onesignal` (builder `toOneSignal`)
 * for push and `onesignal-sms` (builder `toSms`) for SMS, keeping push and SMS
 * independently toggleable while sharing one send pipeline.
 *
 * The `app_id` is always forced from package config (`magic-starter.onesignal.app_id`),
 * regardless of what the notification sets.
 */
class OneSignalChannel
{
    /**
     * Keys the Flutter client reads a deep link from, in its own order.
     *
     * Mirrors `magic_deeplink`'s `OneSignalDeeplinkHandler.extractUri`
     * (`onesignal_deeplink_handler.dart:66`). The two lists have to agree: a
     * key this side ignores is a link the browser never follows, and a key
     * this side prefers over the client's choice sends the two platforms to
     * different screens from one payload.
     *
     * @var list<string>
     */
    private const DEEP_LINK_KEYS = ['url', 'deep_link', 'link', 'uri'];

    public function __construct(
        private DefaultApi $client,
        private string $builderMethod = 'toOneSignal',
    ) {}

    /**
     * Send the given notification via OneSignal.
     *
     * @return CreateNotificationSuccessResponse<int|null, mixed>|null
     *
     * @throws InvalidArgumentException When the configured builder method returns an unexpected type.
     * @throws Throwable Re-thrown after reporting when the API call fails.
     */
    public function send(mixed $notifiable, Notification $notification): ?CreateNotificationSuccessResponse
    {
        // 1. Skip if the notification does not support this OneSignal builder
        if (! is_callable([$notification, $this->builderMethod])) {
            return null;
        }

        // 2. Resolve aliases from the notifiable
        if (method_exists($notifiable, 'routeNotificationForOneSignal')) {
            /** @var array<string, array<int, string>> $aliases */
            $aliases = $notifiable->routeNotificationForOneSignal();
        } elseif (method_exists($notifiable, 'getKey')) {
            // Prefixed, exactly as HasNotifications prefixes it. This used to
            // send the key BARE, which is the one shape that can never reach
            // anybody: the Flutter client registers the device as
            // `<prefix><id>`, so a bare id addresses a device nothing
            // registered, and OneSignal rejects a bare numeric external id
            // outright. Neither failure is visible from here, since a send to
            // an unknown alias is accepted and delivered to nobody.
            //
            // Reached by an ordinary mistake rather than an exotic one: a
            // published `App\Models\User` that forgot the trait, which
            // `MagicStarter::userModel()` detects and uses automatically.
            $aliases = [
                'external_id' => [
                    MagicStarter::onesignalExternalIdPrefix() . $notifiable->getKey(),
                ],
            ];
        } else {
            throw new InvalidArgumentException(sprintf(
                '%s must implement routeNotificationForOneSignal() or getKey() to receive OneSignal notifications.',
                get_debug_type($notifiable),
            ));
        }

        // 3. Build the OneSignal notification payload (the builder is user-defined per Notification class)
        $payload = \call_user_func([$notification, $this->builderMethod], $notifiable);

        if (! $payload instanceof OneSignalNotification) {
            throw new InvalidArgumentException(sprintf(
                '%s::%s() must return %s; got %s.',
                get_class($notification),
                $this->builderMethod,
                OneSignalNotification::class,
                get_debug_type($payload),
            ));
        }

        // 4. Apply default aliases and target channel when none are explicitly set
        if ($payload->getIncludeAliases() === null && $payload->getIncludedSegments() === null) {
            $payload->setIncludeAliases($aliases);
            $payload->setTargetChannel((string) config('magic-starter.onesignal.target_channel', 'push'));
        }

        // 5. Always force app_id from package config (validated non-empty)
        $appId = MagicStarter::onesignalAppId();

        if ($appId === null) {
            throw new InvalidArgumentException(
                'The OneSignal app ID configuration value [magic-starter.onesignal.app_id] must be a non-empty string.',
            );
        }

        $payload->setAppId($appId);

        // 5b. Carry the deep link into `web_url`, which is the only thing a
        //     browser reads.
        $this->applyWebUrl($payload);

        // 6. Send via the OneSignal API. A transport exception is reported and
        //    rethrown so the ShouldQueue job can retry the delivery.
        try {
            $response = $this->client->createNotification($payload);
        } catch (Throwable $exception) {
            report($exception);

            throw $exception;
        }

        // 7. A zero-recipient send is an HTTP 200 with an empty id. Report it as an
        //    honest delivery failure without throwing: unlike a transport error it
        //    is not retryable, so rethrowing would only poison the queue.
        if ($response instanceof CreateNotificationSuccessResponse && $response->getId() === '') {
            report(new RuntimeException(
                'OneSignal accepted the notification but delivered it to zero recipients (empty notification id).',
            ));
        }

        return $response;
    }

    /**
     * Derive `web_url` from the payload's deep link, when neither is settled.
     *
     * A browser reads `web_url` and nothing else. It does NOT read the custom
     * data the mobile clients navigate from, and that asymmetry is invisible
     * until somebody clicks: measured on 2026-09-10 against a real deployment,
     * a push carrying only `data.deep_link` opened the site root in a NEW tab
     * while the app was already open in another one, so the tapped incident
     * was never reached and nothing anywhere reported a failure.
     *
     * The reason is worth keeping, because the obvious mental model is wrong.
     * A web push click is handled by the service worker, not by the page: with
     * a launch url it opens that url, and OneSignal supplies the dashboard's
     * Site URL when the payload names none. Either way the result is an
     * ordinary page load, and no Dart is running in a fresh document to read
     * `additionalData`. The client-side bridge only ever gets a turn when the
     * worker focuses an EXISTING tab instead, which is not the path a
     * configured app takes.
     *
     * Left alone when the builder set `web_url` itself: an application that
     * wants the browser somewhere other than the in-app route has said so, and
     * this is a default rather than a policy. Also left alone when no origin
     * is configured, because guessing one would send people to the wrong host.
     *
     * Only a rooted path is accepted. The value reaches here from the
     * application's own notification class rather than from a request, so this
     * is not input validation; it is the narrow contract that makes
     * concatenation safe, and it keeps an absolute link in the payload from
     * being silently rewritten onto a different origin.
     *
     * @param  OneSignalNotification<int|null, mixed>  $payload
     */
    private function applyWebUrl(OneSignalNotification $payload): void
    {
        // `url` as well as `web_url`. The SDK's own field documentation says
        // to "Omit if including web_url or app_url", so setting both would
        // produce a payload that contradicts its own contract, and a builder
        // that named a launch url has already decided where the click goes.
        if ($this->isSet($payload->getWebUrl()) || $this->isSet($payload->getUrl())) {
            return;
        }

        $origin = config('magic-starter.onesignal.web_origin');

        if (! is_string($origin) || trim($origin) === '') {
            return;
        }

        // Both shapes. The SDK types this field `object|null` and this
        // package's own push-test endpoint sets it with `(object)`
        // (`PushTestController::200`), so an is_array check skipped exactly
        // the endpoint an adopter would use to verify this feature. An
        // application composing the payload by hand is as likely to pass an
        // array, which is what every notification in the wild does today.
        $data = $payload->getData();

        if (is_object($data)) {
            $data = get_object_vars($data);
        }

        if (! is_array($data)) {
            return;
        }

        foreach (self::DEEP_LINK_KEYS as $key) {
            $link = $data[$key] ?? null;

            if (! is_string($link) || ! str_starts_with($link, '/')) {
                continue;
            }

            $payload->setWebUrl(rtrim(trim($origin), '/') . $link);

            return;
        }
    }

    /**
     * Whether a nullable SDK string field carries a value.
     */
    private function isSet(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }
}
