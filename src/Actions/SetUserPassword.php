<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\SetsUserPasswords;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Social\SocialSignInRefused;
use FlutterSdk\MagicStarter\Support\UserPassword;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Default action writing a social-only account's first password.
 */
class SetUserPassword implements SetsUserPasswords
{
    /**
     * Validate and save the user's first password.
     *
     * @param  array<string, mixed>  $input  `password` and `password_confirmation`
     *
     * @throws SocialSignInRefused
     */
    public function set(Authenticatable $user, array $input): void
    {
        // 1. The same rules as a password change, minus the current password
        //    there is none of.
        Validator::make($input, [
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ])->validate();

        DB::transaction(function () use ($user, $input): void {
            // 2. Read the password under a row lock, so of two concurrent first
            //    passwords exactly one lands and the other is refused.
            $userModel = MagicStarter::userModel();

            $locked = $userModel::query()
                ->whereKey($user->getAuthIdentifier())
                ->lockForUpdate()
                ->firstOrFail();

            if (UserPassword::isSet($locked)) {
                throw new SocialSignInRefused('password_already_set');
            }

            // 3. Hash and save.
            $locked->update([
                'password' => Hash::make((string) $input['password']),
            ]);
        });
    }
}
