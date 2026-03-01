<?php

namespace FlutterSdk\MagicStarter\Listeners;

use FlutterSdk\MagicStarter\Enums\Role;
use FlutterSdk\MagicStarter\MagicStarter;
use Illuminate\Auth\Events\Registered;

/**
 * Creates a personal team for newly registered users.
 *
 * Registered via MagicStarterServiceProvider when Features::teams() is enabled.
 * Includes an idempotency check to prevent duplicate teams from race conditions.
 */
class CreatePersonalTeamListener
{
    /**
     * Handle the Registered event.
     */
    public function handle(Registered $event): void
    {
        $user = $event->user;
        $teamModel = MagicStarter::teamModel();

        // 1. Idempotency check via DB query (not cached relationship) to prevent
        //    race conditions when the event is dispatched multiple times.
        $existingPersonalTeam = $teamModel::query()
            ->where('user_id', $user->id)
            ->where('personal_team', true)
            ->first();

        if ($existingPersonalTeam) {
            return;
        }

        // 2. Create the personal team.
        $team = $teamModel::query()->create([
            'user_id' => $user->id,
            'name' => explode(' ', $user->name, 2)[0] . "'s Team",
            'personal_team' => true,
        ]);

        // 3. Add owner to team members pivot.
        $team->users()->attach($user->id, ['role' => Role::OWNER->value]);

        // 4. Clear cached relations so subsequent code sees the new team.
        $user->unsetRelation('ownedTeams')->unsetRelation('teams');

        // 5. Set as current team.
        $user->update(['current_team_id' => $team->id]);
    }
}
