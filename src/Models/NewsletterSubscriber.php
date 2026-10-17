<?php

namespace FlutterSdk\MagicStarter\Models;

use FlutterSdk\MagicStarter\Audit\Auditor;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Notifications\NewsletterSubscribedNotification;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use FlutterSdk\MagicStarter\Support\MailLocale;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
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

    /**
     * Record the user's consent to the newsletter and confirm it by mail.
     *
     * Creates the subscription when there is none, reactivates a withdrawn one
     * and keeps its token. The confirmation goes out only when the row was not
     * a marketing recipient before, so repeating the call mails nothing.
     */
    public static function subscribeUser(Authenticatable $user, string $source): self
    {
        $row = self::forUser($user) ?? new self([
            'user_id' => $user->getAuthIdentifier(),
            'email' => $user->email ?? null,
            'source' => $source,
        ]);

        $wasMarketable = $row->exists
            && $row->is_active
            && $row->consented_at !== null
            && $row->unsubscribed_at === null;

        $version = (string) config('magic-starter.newsletter.consent_version');

        $row->fill([
            'is_active' => true,
            'unsubscribed_at' => null,
            'consented_at' => now(),
            'consent_version' => $version,
            'consent_source' => $source,
        ])->save();

        if (! $wasMarketable) {
            Notification::send(
                $user,
                (new NewsletterSubscribedNotification($row))->locale(MailLocale::for($user)),
            );
        }

        Auditor::record('newsletter.subscribed', $row, [
            'consent_version' => $version,
            'source' => $source,
        ]);

        return $row;
    }

    /**
     * Withdraw the consent. Does nothing when it was already withdrawn.
     *
     * The save is silenced so the model listener writes no row of its own: the
     * one `newsletter.unsubscribed` row says who withdrew and how. A caller whose
     * request URL carries the token passes `['url' => null]`, which replaces the
     * request context's url so the secret never reaches the trail.
     *
     * @param  string  $via  Where the withdrawal came from, e.g. `profile` or `link`.
     * @param  array<string, mixed>  $auditContext  Merged over the request context of the audit row.
     */
    public function unsubscribe(string $via, array $auditContext = []): void
    {
        $now = now();

        // A conditional update, so two withdrawals racing on one row (a mail provider's one-click
        // POST and the reader's own click) record one audit row. A row the previous release
        // re-activated after a withdrawal is active again, so it can be withdrawn again.
        $withdrawn = Auditor::withoutAuditing(fn (): int => self::query()
            ->whereKey($this->getKey())
            ->where(static function (Builder $query): void {
                $query->where('is_active', true)->orWhereNull('unsubscribed_at');
            })
            ->update([
                'is_active' => false,
                'unsubscribed_at' => $now,
                'updated_at' => $now,
            ]));

        if ($withdrawn === 0) {
            return;
        }

        $this->forceFill([
            'is_active' => false,
            'unsubscribed_at' => $now,
            'updated_at' => $now,
        ])->syncOriginal();

        Auditor::record('newsletter.unsubscribed', $this, [
            'via' => $via,
            ...$auditContext,
        ]);
    }

    private static function mintUnsubscribeToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(40)), '+/', '-_'), '=');
    }
}
