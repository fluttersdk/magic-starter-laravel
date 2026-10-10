<?php

namespace FlutterSdk\MagicStarter\Models;

use FlutterSdk\MagicStarter\Enums\BillingEventType;
use FlutterSdk\MagicStarter\Enums\BillingProvider;
use FlutterSdk\MagicStarter\Enums\BillingSource;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One billing outcome: what the package decided about a subscriber's plan, by
 * which path ({@see BillingSource}), and why.
 *
 * The table is an append-only history. Rows are written once and never edited,
 * so the model refuses `update()` and `delete()` with a {@see LogicException}
 * and the table carries `created_at` only. The prune command is the one
 * exception: it deletes by age through the query builder, which fires no model
 * events, so the guard here never stands in its way.
 *
 * `billable_type` and `billable_id` are plain strings with no foreign key (a
 * refusal may have no billable, and a raw store id must fit), and
 * `actor_user_id` is nulled when its user is deleted, so a row outlives both.
 * `external_id` is the rail's own id for what happened (a Stripe event id, a
 * RevenueCat event id) and `properties` carries whatever else the outcome
 * needs.
 *
 * @property string|int $id
 * @property BillingEventType $type
 * @property BillingSource $source
 * @property BillingProvider|null $provider
 * @property string|null $billable_type
 * @property string|null $billable_id
 * @property string|int|null $actor_user_id
 * @property string|null $reason
 * @property string|null $external_id
 * @property array<string, mixed>|null $properties
 * @property Carbon|null $created_at
 * @property-read Model|null $billable
 * @property-read Model|null $actor
 */
class BillingEvent extends Model
{
    use ConditionallyUsesUuids;

    public const UPDATED_AT = null;

    protected $table = 'billing_events';

    /** @var list<string> */
    protected $fillable = [
        'type',
        'source',
        'provider',
        'billable_type',
        'billable_id',
        'actor_user_id',
        'reason',
        'external_id',
        'properties',
        'created_at',
    ];

    /**
     * Refuse every edit and removal of a row that already exists.
     */
    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('billing_events is append-only: a row is never updated.');
        });

        static::deleting(static function (): never {
            throw new LogicException('billing_events is append-only: a row is never deleted, only pruned by age.');
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
            'type' => BillingEventType::class,
            'source' => BillingSource::class,
            'provider' => BillingProvider::class,
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The subject the outcome was about, resolved from `billable_type` as it
     * was written (`getMorphClass()`), or null when the row had none or that
     * subject was deleted.
     *
     * @return MorphTo<Model, $this>
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The user who caused the outcome, or null for a rail-driven one or once
     * that user was deleted.
     *
     * @return BelongsTo<Model, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(MagicStarter::userModel(), 'actor_user_id');
    }
}
