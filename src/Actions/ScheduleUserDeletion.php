<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\SchedulesUserDeletion;
use FlutterSdk\MagicStarter\Models\PushDevice;
use FlutterSdk\MagicStarter\Support\ReadsBillableAttributes;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
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
 * do something irreversible.
 *
 * The refusals mirror the purge's own. A shared team would take other people's
 * data with it, and a billing team would strand a charge, so a user who could
 * still act on either is told now rather than surprised in thirty days. An
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
     *
     * @throws ValidationException When a shared or billing owned team refuses it.
     * @throws RuntimeException When the users table lacks the deletion columns.
     */
    public function schedule(Authenticatable $user, bool $orphan = false): void
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
    }

    /**
     * The teams this user owns that a store or a valid Cashier subscription is
     * still billing.
     *
     * Shared with the purge, which holds an account back on the same answer, so
     * the refusal now and the hold later cannot disagree.
     *
     * @return Collection<int, Model> empty for a user model without teams
     */
    public static function billingOwnedTeams(Authenticatable $user): Collection
    {
        if (! method_exists($user, 'ownedTeams')) {
            return new Collection;
        }

        // The card-rail predicate is an instance method on the shared trait, so a
        // throwaway instance reads through it rather than carrying a copy.
        $reader = new self;

        return $user->ownedTeams()->get()->filter(
            fn (Model $team): bool => SubscriptionGuardedDeleteTeam::storeIsBilling($team)
                || $reader->stripeIsBilling($team),
        )->values();
    }

    /**
     * Refuse a user who owns a shared team or a billing team.
     *
     * @throws ValidationException
     */
    protected function refuseUnlessDeletable(Authenticatable $user): void
    {
        $shared = DeleteUser::sharedOwnedTeams($user);

        if ($shared->isNotEmpty()) {
            $this->refuse('owns_shared_teams', $shared);
        }

        $billing = self::billingOwnedTeams($user);

        if ($billing->isNotEmpty()) {
            $this->refuse('team_has_active_subscription', $billing);
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
     *
     * @throws ValidationException
     */
    protected function refuse(string $code, Collection $teams): never
    {
        $message = (string) __('magic-starter::social.' . $code);

        $exception = ValidationException::withMessages([
            'user' => $message,
        ]);

        $exception->response = new JsonResponse([
            'message' => $message,
            'code' => $code,
            'team_ids' => $teams->map(fn (Model $team): string => (string) $team->getKey())->all(),
            'errors' => $exception->errors(),
        ], 422);

        throw $exception;
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
