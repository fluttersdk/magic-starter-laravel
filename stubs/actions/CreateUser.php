<?php

namespace App\Actions\MagicStarter;

use FlutterSdk\MagicStarter\Contracts\CreatesUsers;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Handle new user registration and initial setup.
 */
class CreateUser implements CreatesUsers
{
    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, mixed>  $input  The validated registration data.
     * @return Authenticatable The created user instance.
     */
    public function create(array $input): Authenticatable
    {
        // TODO: Implement user creation logic.
        // Example: Create user model, hash password, create personal team.
        throw new \RuntimeException('CreateUser action not implemented. Publish and implement this stub.');
    }
}
