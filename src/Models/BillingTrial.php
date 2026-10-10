<?php

namespace FlutterSdk\MagicStarter\Models;

use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One trialing Stripe subscription that package checkout opened: the
 * anti-abuse record behind "one trial per person and per card".
 *
 * `billable_type` always holds `$billable->getMorphClass()`, so a row written
 * for a user and one written for a team stay apart whatever the application
 * bills (`magic-starter.billing.billable`). `billable_id` is that model's key.
 *
 * Retention is deliberate. The row outlives its user (`user_id` is nulled on
 * delete) and its billable (`billable_id` has no foreign key), because a
 * fingerprint that vanished with the account would let a person delete it and
 * start another trial. `subscription_created_at` is Stripe's own `created`, the
 * order the earliest subscription wins on, not the time this row was written.
 * `checked_at` is stamped once the card check finished, either way; a row with
 * `refused_at` set was refused for `refusal_reason` (`card_reused` or
 * `duplicate`) and no longer counts, which is what {@see scopeLive()} leaves out.
 *
 * @property string|int $id
 * @property string|int|null $user_id
 * @property string $billable_type
 * @property string|int $billable_id
 * @property string $stripe_subscription_id
 * @property Carbon $subscription_created_at
 * @property string|null $card_fingerprint
 * @property Carbon|null $checked_at
 * @property Carbon|null $refused_at
 * @property string|null $refusal_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model|null $user
 */
class BillingTrial extends Model
{
    use ConditionallyUsesUuids;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'billable_type',
        'billable_id',
        'stripe_subscription_id',
        'subscription_created_at',
        'card_fingerprint',
        'checked_at',
        'refused_at',
        'refusal_reason',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subscription_created_at' => 'datetime',
            'checked_at' => 'datetime',
            'refused_at' => 'datetime',
        ];
    }

    /**
     * The person who started the trial, or null once that user was deleted.
     *
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(MagicStarter::userModel());
    }

    /**
     * Rows that were not refused.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('refused_at');
    }
}
