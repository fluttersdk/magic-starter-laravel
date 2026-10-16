<?php

namespace FlutterSdk\MagicStarter\Tests\Fixtures;

use FlutterSdk\MagicStarter\Traits\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;

/**
 * A user that verifies its email, with no Filament contract, so tests of the
 * verified-address gates also run in CI's job without the optional packages.
 */
class ConcreteVerifiableUser extends ConcreteUser implements MustVerifyEmailContract
{
    use MustVerifyEmail;
}
