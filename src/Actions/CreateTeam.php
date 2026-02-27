<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\CreatesTeams;
use FlutterSdk\MagicStarter\Enums\Role;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Default team creation action.
 */
class CreateTeam implements CreatesTeams
{
    /**
     * Validate and create a new team for the given user.
     *
     * @param  Authenticatable  $user  The user creating the team.
     * @param  array<string, mixed>  $input  The team data.
     * @return Model The created team instance.
     *
     * @throws ValidationException
     */
    public function create(Authenticatable $user, array $input): Model
    {
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
        ])->validate();

        // 1. Create the team owned by the user.
        $team = $user->ownedTeams()->create([
            'name' => $validated['name'],
            'personal_team' => false,
        ]);

        // 2. Attach the user as team owner in the pivot.
        $team->users()->attach($user->id, ['role' => Role::OWNER->value]);

        // 3. Set as the user's current team.
        $user->update([
            'current_team_id' => $team->id,
        ]);

        return $team;
    }
}
