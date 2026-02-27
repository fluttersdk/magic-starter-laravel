<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\UpdatesUserPasswords;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Default password update action with current-password verification.
 */
class UpdateUserPassword implements UpdatesUserPasswords
{
    /**
     * Validate and update the given user's password.
     *
     * @param  Authenticatable  $user  The user whose password to update.
     * @param  array<string, mixed>  $input  The password data.
     */
    public function update(Authenticatable $user, array $input): void
    {
        $isGuestWithoutPassword = (bool) ($user->is_guest ?? false) && empty($user->password);

        $rules = [
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];

        if (! $isGuestWithoutPassword) {
            $rules['current_password'] = ['required', 'string'];
        }

        Validator::make($input, $rules)->after(function ($validator) use ($user, $input, $isGuestWithoutPassword): void {
            if (! $isGuestWithoutPassword && ! Hash::check((string) $input['current_password'], (string) $user->password)) {
                $validator->errors()->add(
                    'current_password',
                    __('The current password is incorrect.'),
                );
            }
        })->validate();

        $user->update([
            'password' => Hash::make($input['password']),
        ]);

        $fresh = $user->fresh();

        if ($fresh && (bool) $fresh->is_guest) {
            $hasEmail = ! empty($fresh->email);
            $hasPhone = ! empty($fresh->phone);

            if ($hasEmail || $hasPhone) {
                $fresh->update([
                    'is_guest' => false,
                ]);
            }
        }
    }
}
