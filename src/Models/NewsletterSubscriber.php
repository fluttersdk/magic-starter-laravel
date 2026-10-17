<?php

namespace FlutterSdk\MagicStarter\Models;

use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A user's newsletter subscription and the consent it rests on.
 *
 * The subscription belongs to a user, not to an address; `email` is a copy kept
 * in step with the user's. A row written by address alone (the code that ran
 * before the owner column existed) is claimed by {@see forUser()}. The
 * unsubscribe token is minted once, when the row is created, and never rotated,
 * so every mail ever sent keeps a working one-click link.
 *
 * Ask for marketing recipients through {@see scopeMarketable()} only.
 *
 * @property string|int $id
 * @property string|int|null $user_id
 * @property string $email
 * @property bool $is_active
 * @property string|null $source
 * @property Carbon|null $consented_at
 * @property string|null $consent_version
 * @property string|null $consent_source
 * @property Carbon|null $unsubscribed_at
 * @property string|null $unsubscribe_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model|null $user
 */
class NewsletterSubscriber extends Model
{
    use ConditionallyUsesUuids;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'email',
        'is_active',
        'source',
        'consented_at',
        'consent_version',
        'consent_source',
        'unsubscribed_at',
    ];

    /**
     * The token is a bearer secret for the unsubscribe link.
     *
     * @var list<string>
     */
    protected $hidden = [
        'unsubscribe_token',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $subscriber): void {
            if (empty($subscriber->unsubscribe_token)) {
                $subscriber->unsubscribe_token = self::mintUnsubscribeToken();
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'consented_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

    /**
     * The person this subscription belongs to.
     *
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(MagicStarter::userModel());
    }

    /**
     * The rows a marketing mail may go to: active, consented, not withdrawn,
     * and owned by a user.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeMarketable(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->whereNotNull('consented_at')
            ->whereNull('unsubscribed_at')
            ->whereNotNull('user_id');
    }

    /**
     * The user's subscription, claiming a row written by address alone.
     *
     * Only an owner-less row is claimed by address: a row another user owns
     * stays theirs even when its address matches.
     */
    public static function forUser(Authenticatable $user): ?self
    {
        $owned = self::query()->where('user_id', $user->getAuthIdentifier())->first();

        if ($owned !== null) {
            return $owned;
        }

        $email = $user->email ?? null;

        if (empty($email)) {
            return null;
        }

        $unclaimed = self::query()
            ->whereNull('user_id')
            ->whereRaw('lower(email) = ?', [Str::lower($email)])
            ->first();

        if ($unclaimed === null) {
            return null;
        }

        $unclaimed->user_id = $user->getAuthIdentifier();
        $unclaimed->unsubscribe_token ??= self::mintUnsubscribeToken();
        $unclaimed->save();

        return $unclaimed;
    }

    private static function mintUnsubscribeToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(40)), '+/', '-_'), '=');
    }
}
