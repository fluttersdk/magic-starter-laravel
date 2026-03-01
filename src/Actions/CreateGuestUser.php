<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\CreatesGuestUsers;
use FlutterSdk\MagicStarter\Enums\Role;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Support\RequestLocaleDetector;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Validator;

/**
 * Action for creating or finding a guest user by device ID.
 */
class CreateGuestUser implements CreatesGuestUsers
{
    /**
     * Create or find a guest user.
     *
     * @param  array<string, mixed>  $input  The guest user data.
     * @return Authenticatable The guest user instance.
     */
    public function create(array $input): Authenticatable
    {
        // 1. Validate device ID.
        $validated = Validator::make($input, [
            'device_id' => ['required', 'string', 'max:255'],
        ])->validate();

        // 2. Detect locale early — needed for both the guest name and team name.
        $defaults = config('magic-starter.defaults', []);
        $request = request();
        $locale = $request ? RequestLocaleDetector::detectLocale($request) : null;
        $locale = $locale ?? ($defaults['locale'] ?? 'en');

        // 3. Prepare default attributes for a guest user.
        $guestName = trans('magic-starter::teams.guest_name', [], $locale);

        $attributes = [
            'is_guest' => true,
            'name' => $guestName,
            'email' => null,
            'password' => null,
        ];

        // 4. Handle extended profile features (locale) if enabled.
        if (Features::hasExtendedProfileFeatures()) {
            $attributes['locale'] = $locale;
        }

        // 5. Handle timezone if either timezones or extended-profile feature is enabled.
        if (Features::hasTimezoneOrExtendedProfileFeatures()) {
            $defaults ??= config('magic-starter.defaults', []);
            $request ??= request();

            $detectedTimezone = $request ? RequestLocaleDetector::detectTimezone($request) : null;

            $attributes['timezone'] = $detectedTimezone ?? ($defaults['timezone'] ?? 'UTC');
        }

        // 6. Find existing guest or create a new one using firstOrCreate.
        $userModel = MagicStarter::userModel();

        $user = $userModel::query()->firstOrCreate(
            ['device_id' => $validated['device_id']],
            $attributes,
        );

        // 7. Create personal team for newly created guests when teams feature is enabled.
        if ($user->wasRecentlyCreated && Features::hasTeamFeatures()) {
            $this->createPersonalTeam($user, $locale);
        }

        return $user;
    }

    /**
     * Create a personal team for the guest user.
     *
     * Mirrors CreatePersonalTeamListener logic but uses the guest's locale
     * for the translated team name.
     *
     * @param  Authenticatable  $user  The newly created guest user.
     * @param  string  $locale  The locale to use for the team name.
     */
    private function createPersonalTeam(Authenticatable $user, string $locale): void
    {
        $teamModel = MagicStarter::teamModel();

        // 1. Determine the display name for the team.
        $firstName = explode(' ', $user->name, 2)[0];

        // 2. Create the personal team with a localized name.
        $team = $teamModel::query()->create([
            'user_id' => $user->id,
            'name' => trans(
                'magic-starter::teams.personal_team_name',
                ['name' => $firstName],
                $locale,
            ),
            'personal_team' => true,
        ]);

        // 3. Attach user as team owner.
        $team->users()->attach($user->id, ['role' => Role::OWNER->value]);

        // 4. Clear cached relations and set as current team.
        $user->unsetRelation('ownedTeams')->unsetRelation('teams');
        $user->update(['current_team_id' => $team->id]);
    }
}
