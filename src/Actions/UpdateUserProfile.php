<?php

namespace FlutterSdk\MagicStarter\Actions;

use DateTimeZone;
use FlutterSdk\MagicStarter\Contracts\UpdatesUserProfiles;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Rules\E164Phone;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
     *
     * @throws ValidationException
     */
    public function update(Authenticatable $user, array $input): void
    {
        // 1. Build validation rules based on enabled features.
        $isGuest = (bool) ($user->is_guest ?? false);
        $userTable = (new (MagicStarter::userModel()))->getTable();
        $rules = [
            'name' => [
                $isGuest ? 'nullable' : 'required',
                'string',
                'min:2',
                'max:255',
            ],
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique($userTable, 'email')->ignore($user->getAuthIdentifier()),
            ],
        ];
        if (Features::hasExtendedProfileFeatures()) {
            $rules['phone'] = [
                'nullable',
                'string',
                'max:20',
                new E164Phone,
            ];
            $rules['phone_country'] = [
                'nullable',
                'string',
                'size:2',
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

        // 3. Check if guest user now qualifies for conversion.
        $fresh = $user->fresh();

        if ($fresh && (bool) $fresh->is_guest) {
            $hasEmail = ! empty($fresh->email);
            $hasPhone = ! empty($fresh->phone);
            $hasPassword = ! empty($fresh->password);

            if (($hasEmail || $hasPhone) && $hasPassword) {
                $fresh->update([
                    'is_guest' => false,
                ]);
            }
        }
    }
}
