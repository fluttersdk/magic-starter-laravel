<?php

namespace FlutterSdk\MagicStarter\Models;

use FlutterSdk\MagicStarter\Enums\TrialRefusalReason;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
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
 * `refused_at` set was refused for `refusal_reason` ({@see TrialRefusalReason})
 * and no longer counts, which is what {@see scopeLive()} leaves out. A refused
 * row whose `checked_at` is still null is a cancel the check still owes.
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
 * @property TrialRefusalReason|null $refusal_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model|null $user
 * @property-read Model|null $billable
 */
class BillingTrial extends Model
{
    use ConditionallyUsesUuids;

    /**
     * The subscription metadata key a trial checkout tags with the acting
     * user's key.
     *
     * The webhook records a `billing_trials` row only for a subscription
     * carrying it, and it is the only place the PERSON travels: under the team
     * subject the Stripe customer is the team, so nothing else on the
     * subscription says who started the trial. A wire value: every trial
     * subscription already in Stripe carries it, so it never changes.
     */
    public const USER_METADATA_KEY = 'magic_starter_trial_user';

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
            'refusal_reason' => TrialRefusalReason::class,
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
     * The subject the trial billed, resolved from `billable_type` as it was
     * written (`getMorphClass()`), or null once that subject was deleted.
     *
     * @return MorphTo<Model, $this>
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
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
