<?php

namespace FlutterSdk\MagicStarter\Models;

use Carbon\CarbonInterface;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A time-boxed Stripe promotion code staff offer on some catalogue products.
 *
 * Global to the app, not to a team. At most one enabled campaign covers any
 * moment; the write contracts refuse an overlap. The `discount_*` columns are
 * the coupon as Stripe described it when the code was last checked on save.
 *
 * @property string|int $id
 * @property string $name
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property bool $enabled
 * @property list<string> $products Catalogue product keys.
 * @property array<string, string> $headline Locale => text.
 * @property array<string, string> $body Locale => text.
 * @property string $stripe_promotion_code_id
 * @property 'percent'|'amount'|null $discount_type
 * @property float|null $discount_value A percentage for `percent`, a minor-unit amount for `amount`.
 * @property string|null $discount_currency Uppercase ISO code of an `amount` discount.
 * @property array<string, int>|null $currency_options Uppercase currency => minor-unit amount off.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Campaign extends Model
{
    use ConditionallyUsesUuids;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'starts_at',
        'ends_at',
        'enabled',
        'products',
        'headline',
        'body',
        'stripe_promotion_code_id',
        'discount_type',
        'discount_value',
        'discount_currency',
        'currency_options',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'enabled' => 'boolean',
            'products' => 'array',
            'headline' => 'array',
            'body' => 'array',
            'discount_value' => 'float',
            'currency_options' => 'array',
        ];
    }

    /**
     * Enabled campaigns whose window holds `$at` (default now): the start is
     * inclusive and the end exclusive, so back-to-back campaigns never both run.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActiveAt(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $at ??= now();

        return $query
            ->where($this->qualifyColumn('enabled'), true)
            ->where($this->qualifyColumn('starts_at'), '<=', $at)
            ->where($this->qualifyColumn('ends_at'), '>', $at);
    }
}
