<?php

namespace FlutterSdk\MagicStarter\Audit;

use FlutterSdk\MagicStarter\MagicStarter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records every Eloquent create, update and delete as an audit row.
 *
 * Registered as a wildcard listener on `eloquent.{created,updated,deleted}: *`,
 * so the dispatcher hands it the event name and the payload `[$model]`. It runs
 * synchronously at the moment of the event because that is the only time the
 * old values exist: `Model::performUpdate()` fires `updated` after
 * `syncChanges()` and before `finishSave()` calls `syncOriginal()`. The INSERT
 * itself waits for the commit (see {@see Auditor::write()}).
 *
 * Query-builder bulk writes fire no model events and are not audited.
 */
class ModelAuditListener
{
    /**
     * @param  string  $event  e.g. `eloquent.updated: App\Models\Team`.
     * @param  array<int, mixed>  $payload
     */
    public function handle(string $event, array $payload): void
    {
        $model = $payload[0] ?? null;

        if (! $model instanceof Model || $this->shouldSkip($model)) {
            return;
        }

        match (Str::between($event, 'eloquent.', ':')) {
            'created' => Auditor::write('created', $model, null, $model->getAttributes()),
            'updated' => $this->recordUpdate($model),
            'deleted' => $this->recordDeletion($model),
            default => null,
        };
    }

    /**
     * Store only the changed keys, with the raw values they held before.
     *
     * Raw (`getRawOriginal()`) rather than cast (`getOriginal()`), so the old
     * side has the same shape as `getChanges()`: an encrypted cast would
     * otherwise hand over plaintext, and a date cast a Carbon instance.
     *
     * Attributes the model ignores (see {@see Redactor::ignoredKeys()}) are
     * dropped from the row, and an update left with nothing but them and the
     * `updated_at` they dragged along writes no row at all. A model with no
     * ignore list is untouched, so a bare `touch()` on it is still recorded.
     */
    private function recordUpdate(Model $model): void
    {
        $changes = $model->getChanges();
        $ignored = Redactor::ignoredKeys($model);

        if ($ignored !== []) {
            $kept = array_diff_key($changes, array_flip($ignored));

            // Skip only when an ignored key actually moved: a bare touch on
            // the same model is a change of its own and stays recorded.
            if (count($kept) < count($changes) && $this->onlyTimestampLeft($model, $kept)) {
                return;
            }

            $changes = $kept;
        }

        Auditor::write(
            'updated',
            $model,
            array_intersect_key($model->getRawOriginal(), $changes),
            $changes,
        );
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function onlyTimestampLeft(Model $model, array $changes): bool
    {
        $updatedAt = $model->usesTimestamps() ? $model->getUpdatedAtColumn() : null;

        if ($updatedAt !== null) {
            unset($changes[$updatedAt]);
        }

        return $changes === [];
    }

    /**
     * Record a deletion, or erase the trail when the user model itself goes.
     *
     * A deleted user gets no row: its values are the person's data, and the
     * purge that follows would delete a row about them anyway. The purge waits
     * for the commit, after every row this transaction captured before it, so
     * those rows are erased too; a rolled-back deletion erases nothing.
     */
    private function recordDeletion(Model $model): void
    {
        if (Auditor::isUser($model)) {
            $key = $model->getKey();

            DB::afterCommit(function () use ($key): void {
                Auditor::forgetUser($key);
            });

            return;
        }

        Auditor::write('deleted', $model, $model->getAttributes(), null);
    }

    /**
     * Models the trail never records.
     *
     * Pivots and the membership model are skipped because a pivot has no key of
     * its own in integer mode (`Pivot::$incrementing` is false), so its row
     * could not be addressed. The membership model is named on its own because
     * an adopter may publish one that is not a Pivot subclass.
     */
    private function shouldSkip(Model $model): bool
    {
        if (! Auditor::isAuditing()) {
            return true;
        }

        if ($model instanceof Audit || $model instanceof Pivot) {
            return true;
        }

        if (is_a($model, MagicStarter::membershipModel())) {
            return true;
        }

        /** @var list<class-string> $excluded */
        $excluded = config('magic-starter.audit.exclude', []);

        foreach ($excluded as $class) {
            if ($model instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
