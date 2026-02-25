<?php

declare(strict_types=1);

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
        Validator::make($input, [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8'],
        ])->after(function ($validator) use ($user, $input): void {
            if (! Hash::check((string) $input['current_password'], (string) $user->password)) {
                $validator->errors()->add(
                    'current_password',
                    'The current password is incorrect.',
                );
            }
        })->validate();

        $user->update([
            'password' => $input['password'],
        ]);
    }
}
