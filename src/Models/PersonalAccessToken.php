<?php

namespace FlutterSdk\MagicStarter\Models;

use FlutterSdk\MagicStarter\Database\Factories\PersonalAccessTokenFactory;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * @property string $id
 * @property string $tokenable_type
 * @property string $tokenable_id
 * @property string $name
 * @property string $token
 * @property array|null $abilities
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    use ConditionallyUsesUuids;
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): PersonalAccessTokenFactory
    {
        return PersonalAccessTokenFactory::new();
    }
}
