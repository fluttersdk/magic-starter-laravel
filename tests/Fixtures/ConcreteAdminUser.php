<?php

namespace FlutterSdk\MagicStarter\Tests\Fixtures;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use FlutterSdk\MagicStarter\Models\PushDevice;
use FlutterSdk\MagicStarter\Traits\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

/**
 * A panel user for Filament tests. Panel access is open until the package's
 * own access trait replaces `canAccessPanel()`.
 */
class ConcreteAdminUser extends ConcreteUser implements FilamentUser, MustVerifyEmailContract
{
    use HasApiTokens;
    use MustVerifyEmail;

    /**
     * @return HasMany<PushDevice, $this>
     */
    public function pushDevices(): HasMany
    {
        return $this->hasMany(PushDevice::class, 'user_id');
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}
