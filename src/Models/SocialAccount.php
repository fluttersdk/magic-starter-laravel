<?php

namespace FlutterSdk\MagicStarter\Models;

use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One identity a person holds at a sign-in provider, linked to a user.
 *
 * Identity is the pair (`provider`, `provider_user_id`), the provider's own
 * stable id, and never the email: an email can change, be recycled or be
 * withheld, so `email_at_link` only records what the provider said when the
 * link was made.
 *
 * No provider access token or id_token is stored. The one secret kept is the
 * Apple refresh token, which Sign in with Apple needs to revoke the grant when
 * an account is deleted; it is encrypted at rest and hidden from serialisation.
 *
 * @property string|int $id
 * @property string|int $user_id
 * @property string $provider
 * @property string $provider_user_id
 * @property string|null $tenant_id
 * @property string|null $email_at_link
 * @property string|null $client_id
 * @property string|null $refresh_token
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model $user
 */
class SocialAccount extends Model
{
    use ConditionallyUsesUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'provider',
        'provider_user_id',
        'tenant_id',
        'email_at_link',
        'client_id',
        'refresh_token',
        'revoked_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'refresh_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'refresh_token' => 'encrypted',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * The user this identity signs in as.
     *
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(MagicStarter::userModel());
    }
}
