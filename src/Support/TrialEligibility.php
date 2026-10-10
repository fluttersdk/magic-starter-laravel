<?php

namespace FlutterSdk\MagicStarter\Support;

use FlutterSdk\MagicStarter\Models\BillingTrial;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the acting user may start a free trial on the billable they are
 * buying for.
 *
 * A catalogue product's `trial_days` is the length OFFERED, never a promise to
 * everybody ({@see BillingCatalogue}). This is the per-subject half: checkout
 * asks it before it starts a trial, and the plans endpoint asks it so the
 * screen never shows "Free for 14 days" to somebody the checkout would charge
 * on day one.
 *
 * Four refusals, each closing a different way to farm trials:
 *
 * - A guest. A guest account costs one tap to make, so a trial per guest is a
 *   trial per tap. `isGuest()` exists only where the application applied
 *   `HasGuestSupport`, so it is probed with `method_exists()` first; without
 *   the trait there are no guests, and calling it would be a fatal.
 * - A user with any `billing_trials` row. One trial per PERSON, whatever they
 *   were billing at the time, so a person cannot trial once on their account
 *   and again on every team they create.
 * - A billable with any `billing_trials` row. One trial per SUBJECT, so a team
 *   one member trialed is not trialed again by the next member to try. The
 *   billable is matched on `getMorphClass()` AND its key, which is how the row
 *   was written, so a team never refuses a user that shares its key.
 * - A billable that holds or ever held this rail's `default` subscription, in
 *   ANY status. A returning paid customer is not a new one, and a current
 *   subscriber offered a trial would meet the checkout's `subscription_exists`
 *   refusal straight after a "Start free trial" button.
 *
 * A REFUSED row (`refused_at` set) still counts. The card check refuses a
 * trial whose card already trialed; letting that refusal reset eligibility
 * would hand a second trial to exactly the person it caught.
 *
 * The user is always the request's user, never read off the billable: under
 * the team subject the billable is a team and the person asking is whoever is
 * signed in, and it is that person's history the per-person rule asks about.
 *
 * Resolved through the container, so an application with its own rules (a
 * domain allow-list, a sales-led exception) binds a subclass over it.
 */
class TrialEligibility
{
    /**
     * Whether the `billing_trials` table exists, once looked for; null until then.
     *
     * Memoised on the instance, which the container builds for its consumer
     * (the billing controller), so the schema is read at most once per
     * instance and its warning is said at most once with it.
     */
    protected ?bool $trialsTableExists = null;

    /**
     * Whether [$user] may start a trial on [$billable].
     *
     * Reads the local database only, never the rail: it runs on the plans
     * endpoint, which is on the billing screen's hot path, and the rows it
     * reads are the ones Cashier's webhooks and this package's own trial
     * webhook keep in step.
     *
     * A missing `billing_trials` table answers no: an adopter who set a
     * `trial_days` before running the migration sells at the full price, with
     * a warning, instead of answering 500 from the plans endpoint and the
     * checkout alike.
     *
     * @param  Authenticatable  $user  The request's user, never one read off the billable.
     * @param  Model  $billable  The subject the trial would bill: the user itself or their current team.
     */
    public function allows(Authenticatable $user, Model $billable): bool
    {
        // 1. The cheapest fact first, and the one that needs no table at all.
        if (method_exists($user, 'isGuest') && $user->isGuest()) {
            return false;
        }

        // 2. No history table, no history to read and no trial to record.
        if (! $this->trialsTableExists()) {
            return false;
        }

        // 3. A subscription relation the billable already carries, so a
        //    checkout that ran the subscription guard pays no second query.
        if ($this->heldDefaultSubscription($billable)) {
            return false;
        }

        // 4. One query for both histories, the person's and the subject's.
        return ! $this->hasTrialed($user, $billable);
    }

    /**
     * Whether the `billing_trials` table exists, warning the first time it
     * does not.
     *
     * Asked on the model's own connection, which is where every read of the
     * table goes.
     */
    protected function trialsTableExists(): bool
    {
        if ($this->trialsTableExists !== null) {
            return $this->trialsTableExists;
        }

        $model = new BillingTrial;
        $table = $model->getTable();

        $this->trialsTableExists = Schema::connection($model->getConnectionName())->hasTable($table);

        if (! $this->trialsTableExists) {
            Log::warning('A product offers a trial but the billing_trials table is missing; no trial is offered.', [
                'reason' => 'billing_trials_table_missing',
                'table' => $table,
            ]);
        }

        return $this->trialsTableExists;
    }

    /**
     * Whether the billable holds a Cashier subscription of this rail's type,
     * whatever its status.
     *
     * Read through `getAttribute()` rather than the relation method, because
     * the billable model is the consuming application's: Cashier's trait may be
     * absent altogether (an application selling only in the app stores), which
     * the `method_exists()` probe answers as "never subscribed here".
     */
    protected function heldDefaultSubscription(Model $billable): bool
    {
        if (! method_exists($billable, 'subscriptions')) {
            return false;
        }

        $subscriptions = $billable->getAttribute('subscriptions');

        if (! is_iterable($subscriptions)) {
            return false;
        }

        foreach ($subscriptions as $subscription) {
            if (data_get($subscription, 'type') === StripeSubscriptionState::SUBSCRIPTION_TYPE) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a `billing_trials` row exists for the user or for the billable.
     */
    protected function hasTrialed(Authenticatable $user, Model $billable): bool
    {
        return BillingTrial::query()
            ->where(function (Builder $query) use ($user, $billable): void {
                $query
                    ->where('user_id', $user->getAuthIdentifier())
                    ->orWhere(function (Builder $query) use ($billable): void {
                        $query
                            ->where('billable_type', $billable->getMorphClass())
                            ->where('billable_id', $billable->getKey());
                    });
            })
            ->exists();
    }
}
