<?php

namespace FlutterSdk\MagicStarter\Actions;

use DateTimeZone;
use FlutterSdk\MagicStarter\Contracts\UpdatesUserProfiles;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Rules\E164Phone;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
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

        if (Features::hasTimezoneOrExtendedProfileFeatures()) {
            $rules['timezone'] = [
                'nullable',
                'string',
                Rule::in(DateTimeZone::listIdentifiers()),
            ];
        }

        $validated = Validator::make($input, $rules)->validate();

        // 2. Capture original email before update — needed for change detection.
        $originalEmail = $user->email;

        // 2a. Update the user with validated attributes.
        $user->update($validated);

        // 2b. When email changes and verification is required, reset verification status.
        //     This re-queues the user for verification without breaking unrelated updates.
        if (Features::hasEmailVerificationFeatures()
            && isset($validated['email'])
            && $validated['email'] !== null
            && $validated['email'] !== $originalEmail
            && $user instanceof MustVerifyEmail
        ) {
            $user->update(['email_verified_at' => null]);
            $user->sendEmailVerificationNotification();
        }

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
