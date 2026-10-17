<?php

namespace FlutterSdk\MagicStarter\Jobs;

use FlutterSdk\MagicStarter\Models\PushDevice;
use FlutterSdk\MagicStarter\Support\OneSignalSubscriptions;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Removes the SMS subscriptions a user's OneSignal profile no longer earns.
 *
 * Dispatched by the `HasNotifications` trait after a phone change commits. A
 * number left behind in OneSignal keeps receiving texts, because OneSignal fans
 * an SMS send out to every SMS subscription the user has, so the old number is
 * removed as soon as the account stops using it. Clearing the phone removes
 * every enabled SMS subscription; a disabled one is a carrier opt-out and stays.
 *
 * It only ever deletes. Registering the new number is not done here: the package
 * does not know whether the user opted in to SMS, so registration stays lazy and
 * happens at send time ({@see OneSignalSubscriptions::ensureSmsSubscription()}).
 *
 * The job carries the model and reads the CURRENT phone when it runs, so a
 * second change before this one is handled still leaves the right number in
 * place, and no phone number (PII) travels in the queue payload.
 *
 * A user purged before the job runs is skipped by the queue
 * ({@see self::$deleteWhenMissingModels}); {@see DeleteOneSignalUser} is what
 * removes that user from OneSignal.
 *
 * A failure throws, and the queue retries it with a backoff: OneSignal allows
 * one request per second per user or subscription.
 */
class PruneSmsSubscriptions implements ShouldQueue
{
    use Queueable;

    /**
     * Drop the job when the user no longer exists instead of failing it.
     */
    public bool $deleteWhenMissingModels = true;

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
     * @param  Model  $user  Carried as a model reference so the phone is read fresh when the job runs.
     */
    public function __construct(
        public Model $user,
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

        $externalId = PushDevice::externalIdFor($this->user);

        if ($externalId === null) {
            return;
        }

        $phone = $this->user->getAttribute('phone');

        app(OneSignalSubscriptions::class)->pruneSmsSubscriptions($externalId, is_string($phone) ? $phone : null);
    }
}
