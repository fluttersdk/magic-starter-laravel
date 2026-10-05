<?php

namespace FlutterSdk\MagicStarter\Tests\Fixtures;

use Filament\Models\Contracts\FilamentUser;
use FlutterSdk\MagicStarter\Filament\Concerns\AuthorizesAdminPanel;
use FlutterSdk\MagicStarter\Models\PushDevice;
use FlutterSdk\MagicStarter\Traits\MustVerifyEmail;
use FlutterSdk\MagicStarter\Traits\TwoFactorAuthenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

/**
 * A panel user for Filament tests, gated by the package's own access trait.
 */
class ConcreteAdminUser extends ConcreteUser implements FilamentUser, MustVerifyEmailContract
{
    use AuthorizesAdminPanel;
    use HasApiTokens;
    use MustVerifyEmail;
    use TwoFactorAuthenticatable;

    /**
     * @return HasMany<PushDevice, $this>
     */
    public function pushDevices(): HasMany
    {
        return $this->hasMany(PushDevice::class, 'user_id');
    }
}
