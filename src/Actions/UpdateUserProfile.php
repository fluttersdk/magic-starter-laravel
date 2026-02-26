<?php

declare(strict_types=1);

namespace FlutterSdk\MagicStarter\Actions;

use DateTimeZone;
use FlutterSdk\MagicStarter\Contracts\UpdatesUserProfiles;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Rules\E164Phone;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Default profile update action with optional extended fields.
 *
 * When Features::extendedProfile() is enabled, also validates/stores
 * phone, timezone, and language fields.
 */
class UpdateUserProfile implements UpdatesUserProfiles
{
    /**
     * Validate and update the given user's profile information.
     *
     * @param  Authenticatable  $user  The user to update.
     * @param  array<string, mixed>  $input  The profile data.
     */
    public function update(Authenticatable $user, array $input): void
    {
        // 1. Build validation rules based on enabled features.
        $rules = [
            'name' => ['required', 'string', 'min:2', 'max:255'],
        ];

        if (Features::hasExtendedProfileFeatures()) {
            $rules['phone'] = [
                'nullable',
                'string',
                'max:20',
                new E164Phone,
            ];
            $rules['timezone'] = [
                'nullable',
                'string',
                Rule::in(
                    config(
                        'magic-starter.supported_timezones',
                        DateTimeZone::listIdentifiers(),
                    ),
                ),
            ];
            $rules['language'] = [
                'nullable',
                'string',
                Rule::in(
                    config(
                        'magic-starter.supported_locales',
                        ['en'],
                    ),
                ),
            ];
        }

        $validated = Validator::make($input, $rules)->validate();

        // 2. Update the user with validated attributes.
        $user->update($validated);
    }
}
