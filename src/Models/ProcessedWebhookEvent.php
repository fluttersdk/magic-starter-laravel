<?php

namespace FlutterSdk\MagicStarter\Models;

use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * A record marking a single webhook event (Stripe `event->id` today; reused
 * later for store `transaction_id`) as already processed.
 *
 * The unique `event_id` column is the load-bearing dedup key: a webhook
 * handler is expected to call {@see self::recordIfNew()} BEFORE running its
 * side effects (insert-then-handle), so a re-delivered event is a total
 * no-op instead of double-processing.
 */
class ProcessedWebhookEvent extends Model
{
    use ConditionallyUsesUuids;

    /** @var list<string> */
    protected $fillable = [
        'event_id',
        'type',
        'processed_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'processed_at' => 'datetime',
    ];

    /**
     * Insert-then-handle guard: records the event as processed and returns
     * whether this call was the first to see it.
     *
     * Callers must only run the event's side effects when this returns
     * `true`. A `false` return means the unique `event_id` constraint was
     * already satisfied by an earlier delivery, so the caller must skip
     * processing entirely.
     *
     * @param  string  $eventId  The externally-issued event identifier (e.g. Stripe `event->id`).
     * @param  string  $type  The event kind, for observability only.
     */
    public static function recordIfNew(string $eventId, string $type): bool
    {
        try {
            // Wrapped in its own transaction so the insert runs inside a SAVEPOINT
            // whenever a caller already opened one, which StripeWebhookController
            // does. On PostgreSQL a failed statement aborts the WHOLE transaction:
            // catching the unique violation is not enough, because every later
            // query then dies with 25P02 and the eventual COMMIT silently becomes a
            // ROLLBACK, so a re-delivered event would take the caller down with it.
            // The savepoint scopes the failure to this insert and leaves the outer
            // transaction usable. SQLite never showed this, which is why the whole
            // path went unexercised.
            DB::transaction(function () use ($eventId, $type): void {
                static::query()->create([
                    'event_id' => $eventId,
                    'type' => $type,
                    'processed_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // The event was already recorded by an earlier delivery; skip
            // processing. Laravel raises this typed exception for a unique
            // violation across drivers (pgsql 23505, sqlite 23000, ...); any
            // other DB failure propagates rather than being silently swallowed.
            return false;
        }

        return true;
    }
}
