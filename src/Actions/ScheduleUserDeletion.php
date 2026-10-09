<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\SchedulesUserDeletion;
use FlutterSdk\MagicStarter\Enums\BillingProvider;
use FlutterSdk\MagicStarter\Events\UserDeletionScheduled;
use FlutterSdk\MagicStarter\Jobs\PurgeUserNow;
use FlutterSdk\MagicStarter\Models\PushDevice;
use FlutterSdk\MagicStarter\Support\OwnedTeams;
use FlutterSdk\MagicStarter\Support\ReadsBillableAttributes;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Default deletion scheduler: refuse what the purge could not finish, then lock
 * the account in one transaction and stamp the date the grace period runs from.
 *
 * Locking means every Sanctum token is revoked and every push device row is
 * deleted, so the account stops signing in and stops ringing the moment the
 * user asks, while the rows that hold their data stay until the purge. Nothing
 * is deleted here: the HTTP request that asks for a deletion is not the place to
 * do something irreversible, and an immediate deletion only queues
 * {@see PurgeUserNow} once the lock has committed.
 *
 * The refusals mirror the purge's own, through {@see OwnedTeams}. A shared team
 * would take other people's data with it, and a billing team (or, under user
 * billing, the user's own subscription) would strand a charge, so a user who
 * could still act on either is told now rather than surprised in thirty days. An
 * orphan skips both: the provider has deleted the identity, nobody is left to
 * act, and the purge decides what happens to their teams.
 */
class ScheduleUserDeletion implements SchedulesUserDeletion
{
    use ReadsBillableAttributes;

    /**
     * The two columns the pipeline reads, both added by
     * `add_deletion_columns_to_users_table.php`.
     */
    protected const COLUMNS = [
        'deletion_scheduled_at',
        'orphaned_at',
    ];

    /**
     * Schedule the user's account for deletion.
     *
     * Scheduling an already scheduled account keeps the earlier date, so a
     * provider notification delivered twice cannot push the purge back.
     *
     * @param  bool  $orphan  true when the identity provider deleted the account
     * @param  bool  $immediately  true to queue the purge now instead of after the grace period
     *
     * @throws ValidationException When a shared or billing owned team, or the
     *                             user's own subscription, refuses it.
     * @throws RuntimeException When the users table lacks the deletion columns.
     */
    public function schedule(Authenticatable $user, bool $orphan = false, bool $immediately = false): void
    {
        // 1. Without the columns no purge could ever find this account, and
        //    locking it would strand it signed out forever.
        $this->ensureDeletionColumns($user);

        // 2. Refuse what only the user can resolve, while they still can.
        if (! $orphan) {
            $this->refuseUnlessDeletable($user);
        }

        // 3. Lock and stamp atomically: a stamp without the lock would leave
        //    live tokens on an account that is going away.
        DB::transaction(function () use ($user, $orphan): void {
            $user->tokens()->delete();

            if (Schema::hasTable((new PushDevice)->getTable())) {
                PushDevice::query()->where('user_id', $user->getAuthIdentifier())->delete();
            }

            $stamp = now();

            $user->forceFill([
                'deletion_scheduled_at' => $user->getAttribute('deletion_scheduled_at') ?? $stamp,
                'orphaned_at' => $orphan
                    ? ($user->getAttribute('orphaned_at') ?? $stamp)
                    : $user->getAttribute('orphaned_at'),
            ])->save();
        });

        // 4. Only once the lock has committed, so a listener pausing the host's
        //    resources and the purge both read the stamped row.
        Event::dispatch(new UserDeletionScheduled($user, $immediately));

        // 5. Queued, never run here: a team cascade can outlast the request.
        if ($immediately) {
            PurgeUserNow::dispatch($user->getAuthIdentifier());
        }
    }

    /**
     * Refuse a user who owns a shared team or a billing team, or whom a
     * subscription bills directly under user billing.
     *
     * @throws ValidationException
     */
    protected function refuseUnlessDeletable(Authenticatable $user): void
    {
        $shared = OwnedTeams::shared($user);

        if ($shared->isNotEmpty()) {
            $this->refuse('owns_shared_teams', $shared);
        }

        $billing = OwnedTeams::billing($user);

        if ($billing->isNotEmpty()) {
            $this->refuse('team_has_active_subscription', $billing, $this->providersOf($billing));
        }

        if ($user instanceof Model && OwnedTeams::isBilling($user)) {
            $this->refuse('subscription_active', new Collection);
        }
    }

    /**
     * Raise the refusal as a 422 carrying the stable code and the teams behind it.
     *
     * A {@see ValidationException} so the status and the message are the ones
     * every other refusal on the profile endpoints answers with, carrying its
     * own response because the client switches on `code` and needs `team_ids`
     * to point the user at the teams to resolve.
     *
     * @param  string  $code  a key of `lang/<locale>/social.php`
     * @param  Collection<int, Model>  $teams
     * @param  array<string, BillingProvider>  $providers  team id to the rail billing it; the
     *                                                     `team_providers` key is left out when empty
     *
     * @throws ValidationException
     */
    protected function refuse(string $code, Collection $teams, array $providers = []): never
    {
        $message = (string) __('magic-starter::social.' . $code);

        $exception = ValidationException::withMessages([
            'user' => $message,
        ]);

        $body = [
            'message' => $message,
            'code' => $code,
            'team_ids' => $teams->map(fn (Model $team): string => (string) $team->getKey())->all(),
        ];

        if ($providers !== []) {
            $body['team_providers'] = array_map(
                static fn (BillingProvider $provider): string => $provider->value,
                $providers,
            );
        }

        $exception->response = new JsonResponse($body + ['errors' => $exception->errors()], 422);

        throw $exception;
    }

    /**
     * The rail billing each of these teams, keyed by team id.
     *
     * A store is named from `plan_provider`, which is what the store guard read
     * to refuse. Anything else that refused did so on Cashier's own rows, so it
     * is Stripe whatever the provenance column says: that column is written by
     * the webhook and can lag or be absent on a team Cashier is already billing.
     *
     * @param  Collection<int, Model>  $teams
     * @return array<string, BillingProvider>
     */
    protected function providersOf(Collection $teams): array
    {
        $providers = [];

        foreach ($teams as $team) {
            $providers[(string) $team->getKey()] = SubscriptionGuardedDeleteTeam::storeIsBilling($team)
                ? BillingProvider::fromWire($this->stringAttribute($team, 'plan_provider'))
                : BillingProvider::STRIPE;
        }

        return $providers;
    }

    /**
     * Fail loudly when the users table predates the deletion migration.
     *
     * @throws RuntimeException
     */
    protected function ensureDeletionColumns(Authenticatable $user): void
    {
        $table = $user instanceof Model ? $user->getTable() : 'users';

        foreach (self::COLUMNS as $column) {
            if (! Schema::hasColumn($table, $column)) {
                throw new RuntimeException(sprintf(
                    'Account deletion needs the [%s.%s] column. Publish and run the '
                    . 'magic-starter migrations (add_deletion_columns_to_users_table).',
                    $table,
                    $column,
                ));
            }
        }
    }
}
