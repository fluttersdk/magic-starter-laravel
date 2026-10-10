<?php

namespace FlutterSdk\MagicStarter\Models;

use FlutterSdk\MagicStarter\Enums\GrantEndReason;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One manual plan grant: an operator gave a billable a plan in the admin panel
 * with no payment behind it, for a stated reason and, optionally, until a date.
 *
 * A grant is OPEN while `ended_at` is null ({@see scopeOpen()}) and ends once,
 * for a {@see GrantEndReason}. The entitlement a grant writes carries
 * {@see productId()} in `plan_product_id`, which is how an expiry or a revoke
 * checks that the billable is still on this grant before it takes the plan away.
 *
 * Retention is deliberate. `billable_id` is a string with no foreign key and
 * `granted_by` is nulled when its operator is deleted, so a row outlives both.
 * `billable_type` always holds `$billable->getMorphClass()`.
 *
 * @property string|int $id
 * @property string $billable_type
 * @property string $billable_id
 * @property string $plan
 * @property string $reason
 * @property Carbon|null $expires_at
 * @property string|int|null $granted_by
 * @property Carbon|null $ended_at
 * @property GrantEndReason|null $end_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model|null $grantedBy
 * @property-read Model|null $billable
 */
class BillingGrant extends Model
{
    use ConditionallyUsesUuids;

    /** @var list<string> */
    protected $fillable = [
        'billable_type',
        'billable_id',
        'plan',
        'reason',
        'expires_at',
        'granted_by',
        'ended_at',
        'end_reason',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'ended_at' => 'datetime',
            'end_reason' => GrantEndReason::class,
        ];
    }

    /**
     * The operator who made the grant, or null once that user was deleted.
     *
     * @return BelongsTo<Model, $this>
     */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(MagicStarter::userModel(), 'granted_by');
    }

    /**
     * The subject the grant was made for, resolved from `billable_type` as it
     * was written (`getMorphClass()`), or null once that subject was deleted.
     *
     * @return MorphTo<Model, $this>
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Grants that have not ended.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    /**
     * The value the entitlement this grant writes stores in `plan_product_id`.
     */
    public function productId(): string
    {
        return 'grant:' . $this->getKey();
    }

    /**
     * Every grant made for one billable.
     *
     * `billable_id` is a string column, and a bare integer key bound against it
     * misses on PostgreSQL, so the key is bound as a string.
     *
     * @return Builder<static>
     */
    public static function forBillable(Model $billable): Builder
    {
        return static::query()
            ->where('billable_type', $billable->getMorphClass())
            ->where('billable_id', (string) $billable->getKey());
    }
}
