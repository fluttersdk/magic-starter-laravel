<?php

namespace FlutterSdk\MagicStarter\Support;

use FlutterSdk\MagicStarter\Enums\BillingEventType;
use FlutterSdk\MagicStarter\Enums\BillingProvider;
use FlutterSdk\MagicStarter\Enums\BillingSource;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\BillingEvent;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The one seam every billing outcome passes through: it leaves a
 * `billing_events` row, dispatches the outcome's {@see BillingEventType::eventClass()}
 * event and logs the success line, and it never breaks the billing it records.
 *
 * Recording runs inside the callers' own transactions (the Stripe webhook
 * wraps its whole delivery in one), so a failure here must stay here:
 *
 * - The insert runs in its own `DB::transaction()`, which is a SAVEPOINT
 *   whenever the caller already opened one. On PostgreSQL a failed statement
 *   aborts the whole transaction: every later query dies with 25P02 and the
 *   final COMMIT silently becomes a ROLLBACK, so the entitlement the webhook
 *   was writing would never apply. Rolling back to the savepoint leaves the
 *   caller's transaction usable. Only a {@see QueryException} is caught, and it
 *   is logged at error level; anything else is a bug and propagates.
 * - The event is dispatched OUTSIDE that savepoint, whether the row was
 *   written, refused or skipped. It is handed to `DB::afterCommit()`, and a
 *   rolled-back savepoint discards the after-commit callbacks registered
 *   inside it, so dispatching inside would lose it exactly when the row
 *   failed. Registered at the caller's level instead, it runs once the
 *   caller commits, is discarded when the caller rolls back, and runs at
 *   once outside any transaction.
 * - A listener runs in the billing path, so its failure is reported through
 *   `report()` and never propagated: a throwing synchronous listener would
 *   otherwise turn a completed Stripe request into a 500 or, inside a
 *   webhook's commit callbacks, skip the callbacks queued after it (the
 *   trial's card check among them).
 * - A missing table (an application that upgraded without migrating) skips
 *   the row with one warning per process. Only a table that EXISTS is
 *   remembered: a queue or Octane worker started before the migration must
 *   start writing rows once it has run, without a restart.
 *
 * Bound as a singleton, so the table check costs one query per worker.
 */
class BillingEventRecorder
{
    /**
     * Whether the missing-table warning was already written by this process.
     */
    private static bool $warnedMissingTable = false;

    /**
     * True once the table was seen; never set to false, see the class docblock.
     */
    private bool $tableExists = false;

    /**
     * Record one billing outcome.
     *
     * @param  Model|null  $billable  The subscriber the outcome is about; null for a refusal that never resolved one.
     * @param  string|null  $reason  A stable snake_case rule name, never prose.
     * @param  string|null  $externalId  The rail's own id for what happened (a Stripe or RevenueCat event id).
     * @param  array<string, mixed>  $properties  Whatever else the outcome needs; never secrets.
     * @param  Authenticatable|null  $actor  Who caused it; defaults to the authenticated user, and is kept only
     *                                       when it is an instance of the configured user model.
     * @return BillingEvent The row, unsaved (`exists` false) when it could not be written.
     */
    public function record(
        BillingEventType $type,
        BillingSource $source,
        ?Model $billable,
        ?BillingProvider $provider = null,
        ?string $reason = null,
        ?string $externalId = null,
        array $properties = [],
        ?Authenticatable $actor = null,
    ): BillingEvent {
        // 1. Capture everything now, while the caller's state is the one that decided.
        $event = new BillingEvent([
            'type' => $type,
            'source' => $source,
            'provider' => $provider,
            'billable_type' => $billable?->getMorphClass(),
            'billable_id' => $billable === null ? null : (string) $billable->getKey(),
            'actor_user_id' => $this->actorKey($actor ?? auth()->user()),
            'reason' => $reason,
            'external_id' => $externalId,
            'properties' => $properties,
            'created_at' => now(),
        ]);

        // 2. Write the row inside its own savepoint, never past it.
        $this->persist($event);

        // 3. Outside the savepoint, so a failed insert still announces the outcome.
        $outcome = new ($type->eventClass())($event);

        DB::afterCommit(static fn () => rescue(static fn () => event($outcome), report: true));

        // 4. Refusals are logged by their call sites, which already carry a warning line.
        $line = $this->successLine($type);

        if ($line !== null) {
            $context = [
                'type' => $type->value,
                'source' => $source->value,
                'provider' => $provider?->value,
                'reason' => $reason,
                'billable_type' => $event->billable_type,
                'billable_id' => $event->billable_id,
                'external_id' => $externalId,
            ];

            DB::afterCommit(static fn () => BillingLog::info($line, $context));
        }

        return $event;
    }

    /**
     * Whether an outcome with this type, external id and reason is already on
     * record, so a caller that re-runs one decision (a queue retry) records it
     * once. A null id or reason matches only a null column.
     *
     * @return bool False when the table is missing: nothing is on record, and the
     *              next {@see self::record()} skips the row with its warning anyway.
     */
    public function recorded(BillingEventType $type, ?string $externalId, ?string $reason): bool
    {
        $model = new BillingEvent;

        if (! $this->tableExists($model->getTable())) {
            return false;
        }

        return $model->newQuery()
            ->where('type', $type->value)
            ->where('external_id', $externalId)
            ->where('reason', $reason)
            ->exists();
    }

    private function persist(BillingEvent $event): void
    {
        if (! $this->tableExists($event->getTable())) {
            return;
        }

        try {
            DB::transaction(static fn (): bool => $event->save());
        } catch (QueryException $exception) {
            BillingLog::error('A billing event row could not be written; billing continues.', [
                'type' => $event->type->value,
                'reason' => $event->reason,
                'billable_type' => $event->billable_type,
                'billable_id' => $event->billable_id,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function tableExists(string $table): bool
    {
        if ($this->tableExists) {
            return true;
        }

        $this->tableExists = Schema::hasTable($table);

        if (! $this->tableExists && ! self::$warnedMissingTable) {
            self::$warnedMissingTable = true;

            BillingLog::warning('The billing_events table is missing; billing outcomes are not recorded.', [
                'reason' => 'billing_events_table_missing',
                'table' => $table,
            ]);
        }

        return $this->tableExists;
    }

    private function actorKey(?Authenticatable $actor): mixed
    {
        $userModel = MagicStarter::userModel();

        if (! $actor instanceof Model || ! $actor instanceof $userModel) {
            return null;
        }

        return $actor->getKey();
    }

    /**
     * The info line for a success, or null for a refusal.
     */
    private function successLine(BillingEventType $type): ?string
    {
        return match ($type) {
            BillingEventType::ENTITLEMENT_APPLIED => 'A billing entitlement was applied.',
            BillingEventType::CHECKOUT_STARTED => 'A billing checkout was started.',
            BillingEventType::SUBSCRIPTION_SWAPPED => 'A subscription was swapped to another plan.',
            BillingEventType::SUBSCRIPTION_CANCELLED => 'A subscription was cancelled.',
            BillingEventType::PORTAL_OPENED => 'A billing portal session was opened.',
            BillingEventType::TRIAL_RECORDED => 'A trial was recorded.',
            BillingEventType::TRIAL_CANCELLED => 'A refused trial ended.',
            BillingEventType::TRIAL_REFUSAL_WITHDRAWN => 'A trial refusal was withdrawn; nothing was cancelled.',
            BillingEventType::ENTITLEMENT_DROPPED,
            BillingEventType::REQUEST_REFUSED,
            BillingEventType::DELIVERY_REFUSED,
            BillingEventType::TRIAL_REFUSED => null,
        };
    }
}
