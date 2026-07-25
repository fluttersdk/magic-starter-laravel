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
            $aliases = ['external_id' => [(string) $notifiable->getKey()]];
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
}
