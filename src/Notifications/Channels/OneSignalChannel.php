<?php

namespace FlutterSdk\MagicStarter\Notifications\Channels;

use Illuminate\Notifications\Notification;
use InvalidArgumentException;
use onesignal\client\api\DefaultApi;
use onesignal\client\model\CreateNotificationSuccessResponse;
use onesignal\client\model\Notification as OneSignalNotification;
use Throwable;

/**
 * OneSignal push notification channel using the official v5 PHP SDK.
 *
 * This channel sends push notifications via alias-based targeting. The OneSignal v5 API
 * uses "aliases" (e.g. external_id, onesignal_id) to identify recipients instead of
 * legacy player IDs. When no explicit aliases or segments are set on the notification
 * payload, this channel automatically resolves aliases from the notifiable entity.
 *
 * Notification classes that wish to use this channel must implement a `toOneSignal()`
 * method returning an `\onesignal\client\model\Notification` instance:
 *
 *     public function toOneSignal(mixed $notifiable): \onesignal\client\model\Notification
 *
 * The `app_id` is always forced from package config (`magic-starter.onesignal.app_id`),
 * regardless of what the notification sets.
 */
class OneSignalChannel
{
    public function __construct(
        private DefaultApi $client,
    ) {}

    /**
     * Send the given notification via OneSignal.
     *
     *
     * @return CreateNotificationSuccessResponse<int|null, mixed>|null
     *
     * @throws InvalidArgumentException When toOneSignal() returns an unexpected type.
     * @throws Throwable Re-thrown after reporting when the API call fails.
     */
    public function send(mixed $notifiable, Notification $notification): ?CreateNotificationSuccessResponse
    {
        // 1. Skip if the notification does not support OneSignal
        if (! is_callable([$notification, 'toOneSignal'])) {
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

        // 3. Build the OneSignal notification payload (toOneSignal is user-defined per Notification class)
        $payload = \call_user_func([$notification, 'toOneSignal'], $notifiable);

        if (! $payload instanceof OneSignalNotification) {
            throw new InvalidArgumentException(sprintf(
                '%s::toOneSignal() must return %s; got %s.',
                get_class($notification),
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
        $appId = config('magic-starter.onesignal.app_id');

        if (! is_string($appId) || trim($appId) === '') {
            throw new InvalidArgumentException(
                'The OneSignal app ID configuration value [magic-starter.onesignal.app_id] must be a non-empty string.',
            );
        }

        $payload->setAppId($appId);

        // 6. Send via the OneSignal API
        try {
            return $this->client->createNotification($payload);
        } catch (Throwable $exception) {
            report($exception);

            throw $exception;
        }
    }
}
