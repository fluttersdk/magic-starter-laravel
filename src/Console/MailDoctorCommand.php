<?php

namespace FlutterSdk\MagicStarter\Console;

use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\MailSend;
use FlutterSdk\MagicStarter\Support\OneSignalSubscriptions;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use onesignal\client\api\DefaultApi;
use onesignal\client\ApiException;
use onesignal\client\model\NotificationWithMeta;

/**
 * Check that OneSignal email can carry the mail the router sends its way.
 *
 * Prints whether the app id and the REST key are set (never the key), the state of
 * the app's email channel, the router config and the default mailer. The channel is
 * read with a raw call under the REST key: the SDK's `getApp` authenticates with the
 * Organization key and its `App` model has no `channels` field. OneSignal's reference
 * documents `GET /apps/{id}` as an Organization key call, yet on 2026-10-20 the REST
 * key answered 200 with a `channels` object from production, so the REST key is what
 * the call sends. Should OneSignal ever enforce the documented key, the answer is a
 * 401 or 403, which prints `unknown` rather than failing. Anything but a 200 with
 * `channels.email.enabled` prints `unknown`.
 *
 * With `--deliveries=N` it also reads the counters OneSignal holds for the last N
 * recorded sends, by mail class and OneSignal id, never by address.
 *
 * The command exits 1 only for a live misconfiguration: the email channel is
 * disabled while the router is enabled, so every routed mail would fall to the
 * failover. An `unknown` channel with the router off is not one. It never sends mail.
 */
class MailDoctorCommand extends Command
{
    public const NAME = 'magic-starter:mail:doctor';

    private const APPS_URL = 'https://api.onesignal.com/apps/';

    /**
     * @var string
     */
    protected $signature = self::NAME . ' {--deliveries= : Read the OneSignal counters of the last N recorded sends}';

    /**
     * @var string
     */
    protected $description = 'Check the OneSignal email channel and the mail router';

    /**
     * @return int non-zero when the router is enabled and the email channel is disabled
     */
    public function handle(Container $container): int
    {
        $provisioned = OneSignalSubscriptions::provisioned();
        $appId = MagicStarter::onesignalAppId();

        // 1. Credentials first: without both, nothing below can ask OneSignal anything.
        $this->line('OneSignal app id: ' . ($appId !== null ? 'set' : 'not set'));
        $this->line('OneSignal REST key: ' . ($this->restKey() !== null ? 'set' : 'not set'));

        // 2. The channel state, read only when both credentials are there.
        $channel = $provisioned ? $this->emailChannelEnabled((string) $appId) : null;
        $this->line('Email channel: ' . match ($channel) {
            true => 'enabled',
            false => 'disabled',
            null => 'unknown',
        });

        // 3. The router config and the mailer a mail leaves through by default.
        $routerEnabled = (bool) config('magic-starter.mail.onesignal.enabled');
        $this->line(sprintf(
            'Router: %s, classes [%s], mailer %s',
            $routerEnabled ? 'enabled' : 'disabled',
            implode(', ', (array) config('magic-starter.mail.onesignal.classes')),
            (string) config('magic-starter.mail.onesignal.mailer'),
        ));
        $this->line('Default mailer: ' . (string) config('mail.default'));

        // 4. Delivery counters on request.
        if ($this->option('deliveries') !== null) {
            $this->printDeliveries($container, max(1, (int) $this->option('deliveries')), $provisioned);
        }

        // 5. A routed mail into a switched-off channel always lands on the failover: fail loudly.
        if ($channel === false && $routerEnabled) {
            $this->components->error(
                'The OneSignal email channel is disabled while the router is enabled: every routed mail '
                . 'falls to the failover. Enable email in the OneSignal dashboard or switch the router off.',
            );

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * The REST key, read as the service provider reads it when it builds the client.
     */
    private function restKey(): ?string
    {
        $key = config('magic-starter.onesignal.rest_api_key') ?? config('services.onesignal.rest_api_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * `channels.email.enabled` of the app, or null when OneSignal does not say.
     */
    private function emailChannelEnabled(string $appId): ?bool
    {
        try {
            $response = Http::withHeaders(['Authorization' => 'Key ' . $this->restKey()])
                ->acceptJson()
                ->timeout(15)
                ->get(self::APPS_URL . $appId);
        } catch (ConnectionException) {
            return null;
        }

        if ($response->status() !== 200) {
            return null;
        }

        $enabled = data_get($response->json(), 'channels.email.enabled');

        return is_bool($enabled) ? $enabled : null;
    }

    /**
     * Print the counters of the last `$limit` recorded sends, by class and id.
     */
    private function printDeliveries(Container $container, int $limit, bool $provisioned): void
    {
        $model = new MailSend;

        if (! $model->getConnection()->getSchemaBuilder()->hasTable($model->getTable())) {
            $this->components->warn("No deliveries to read: {$model->getTable()} does not exist.");

            return;
        }

        if (! $provisioned) {
            $this->components->warn('No deliveries to read: OneSignal is not provisioned.');

            return;
        }

        $sends = MailSend::query()->latest('created_at')->limit($limit)->get();
        $client = $container->make(DefaultApi::class);

        $this->line(sprintf('Last %d recorded %s:', $sends->count(), $sends->count() === 1 ? 'send' : 'sends'));

        foreach ($sends as $send) {
            $this->line(sprintf(
                '  %s  %s  %s',
                $send->onesignal_id,
                $send->mail_class ?? '(direct)',
                $this->counters($client, (string) MagicStarter::onesignalAppId(), $send->onesignal_id),
            ));
        }
    }

    /**
     * The counters line of one send; a failed read names the status, never the exception text.
     */
    private function counters(DefaultApi $client, string $appId, string $notificationId): string
    {
        try {
            $notification = $client->getNotification($appId, $notificationId);
        } catch (ApiException|GuzzleException $exception) {
            return sprintf('unavailable (status %d)', $exception->getCode());
        }

        if (! $notification instanceof NotificationWithMeta) {
            return sprintf('unavailable (%s)', get_debug_type($notification));
        }

        return sprintf(
            'successful: %d, failed: %d, errored: %d, received: %d',
            $notification->getSuccessful(),
            $notification->getFailed(),
            $notification->getErrored(),
            $notification->getReceived(),
        );
    }
}
