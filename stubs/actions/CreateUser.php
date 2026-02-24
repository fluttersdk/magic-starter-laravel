<?php

namespace App\Actions\MagicStarter;

use FlutterSdk\MagicStarter\Contracts\CreatesUsers;

/**
 * Handle creating a new user during registration.
 */
class CreateUser implements CreatesUsers
{
    /**
     * Create a newly registered user.
     */
    public function create(array $input): mixed
    {
        // TODO: Implement user creation logic.
        // Example: validate input, hash password, create and return user model.
        throw new \RuntimeException('CreateUser action not implemented. Publish and implement this stub.');
    }
}
