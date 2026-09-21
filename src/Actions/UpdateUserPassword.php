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
                    __('magic-starter::auth.password.current_incorrect'),
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
                $this->promote($fresh);
            }
        }
    }

    /**
     * Turn a qualifying guest into a registered account.
     *
     * A guest that sets an email first and a password second is promoted here
     * rather than in UpdateUserProfile, so both paths have to release the device
     * id. It is an anonymous session key that POST auth/guest accepts with no
     * credential beside it, and an account that has stopped being anonymous must
     * stop answering to it. The account reaches itself through its own
     * credentials from here.
     *
     * forceFill rather than update() because neither column is in the published
     * User stub's $fillable (making is_guest mass-assignable would let a crafted
     * payload flag any account as a guest), and mass assignment drops a guarded
     * attribute without erroring, so the promotion never landed on a real install.
     *
     * @param  Authenticatable  $user  The guest that now qualifies as registered.
     */
    private function promote(Authenticatable $user): void
    {
        $user->forceFill([
            'is_guest' => false,
            'device_id' => null,
        ])->save();
    }
}
