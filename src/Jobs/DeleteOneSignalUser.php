<?php

namespace FlutterSdk\MagicStarter\Jobs;

use FlutterSdk\MagicStarter\Support\OneSignalSubscriptions;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Deletes a purged account's user from OneSignal, which stops every channel.
 *
 * Dispatched by the `DeleteUser` action after the purge commits. It carries the
 * external id and not the model, because the row it was derived from is gone by
 * the time the job runs.
 *
 * Like {@see PruneSmsSubscriptions} it never registers anything: the package
 * does not know SMS opt-in, so registration stays lazy at send time.
 *
 * A failure throws, and the queue retries it with a backoff: OneSignal allows
 * one request per second per user or subscription.
 */
class DeleteOneSignalUser implements ShouldQueue
{
    use Queueable;

    /**
     * Attempts before the queue gives up and fails the job.
     */
    public int $tries = 3;

    /**
     * Seconds to wait between attempts.
     *
     * @var array<int, int>
     */
    public array $backoff = [2, 5, 10];

    /**
     * @param  string  $externalId  The OneSignal alias value, carried instead of the model because the row is gone.
     */
    public function __construct(
        public string $externalId,
    ) {}

    /**
     * Resolves nothing before the provisioned check: the OneSignal client throws
     * when it is built without a REST key, so an app that does not use OneSignal
     * must never reach it.
     */
    public function handle(): void
    {
        if (! OneSignalSubscriptions::provisioned()) {
            return;
        }

        app(OneSignalSubscriptions::class)->deleteUser($this->externalId);
    }
}
