<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use FlutterSdk\MagicStarter\Support\UserPassword;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Auth\GenericUser;

/**
 * The one answer to "can this user sign in with a password", for a consumer
 * User with or without the package's `HasSocialAccounts` trait.
 */
final class UserPasswordTest extends TestCase
{
    public function test_a_user_without_has_password_is_judged_by_its_stored_password(): void
    {
        $this->assertTrue(UserPassword::isSet(new GenericUser([
            'id' => 1,
            'password' => 'hashed-password',
        ])));
        $this->assertFalse(UserPassword::isSet(new GenericUser([
            'id' => 2,
            'password' => null,
        ])));
        $this->assertFalse(UserPassword::isSet(new GenericUser([
            'id' => 3,
            'password' => '',
        ])));
    }

    public function test_a_user_with_has_password_answers_for_itself(): void
    {
        $user = new class(['id' => 1, 'password' => null]) extends GenericUser
        {
            public function hasPassword(): bool
            {
                return true;
            }
        };

        $this->assertTrue(UserPassword::isSet($user));
    }
}
