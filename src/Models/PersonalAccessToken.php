<?php

namespace FlutterSdk\MagicStarter\Models;

use FlutterSdk\MagicStarter\Database\Factories\PersonalAccessTokenFactory;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
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
     * Find the token a plain-text bearer names, or null.
     *
     * Sanctum looks the `<id>|<secret>` prefix up by primary key as it arrives.
     * A prefix the key column cannot hold reached PostgreSQL as an invalid
     * literal and the query threw (SQLSTATE 22P02 for a non-uuid under uuid
     * keys, 22003 for digits past bigint under integer keys), so a malformed
     * bearer answered 500 instead of 401. Such a prefix names no token.
     *
     * @param  string  $token
     */
    public static function findToken($token): ?static
    {
        if (str_contains($token, '|') && ! self::isStorableKey(Str::before($token, '|'))) {
            return null;
        }

        return parent::findToken($token);
    }

    /**
     * Whether the key column can hold `$id` at all: a uuid under uuid keys, a
     * non-negative integer within PHP's (and bigint's) range otherwise.
     */
    private static function isStorableKey(string $id): bool
    {
        if (MigrationHelper::usesUuids()) {
            return Str::isUuid($id);
        }

        return filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) !== false;
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): PersonalAccessTokenFactory
    {
        return PersonalAccessTokenFactory::new();
    }
}
