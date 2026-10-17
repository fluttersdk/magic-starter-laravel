<?php

namespace FlutterSdk\MagicStarter\Models;

use FlutterSdk\MagicStarter\Enums\ReleaseStatus;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One shipped version of the app and the notes staff wrote for it.
 *
 * Global to the app, not to a team. Only a published row with a `published_at`
 * is visible to clients; see {@see scopePublished()}.
 *
 * @property string|int $id
 * @property string $version
 * @property ReleaseStatus $status
 * @property Carbon|null $published_at
 * @property int|null $ios_build
 * @property int|null $android_build
 * @property list<array{type: string, text: array<string, string>}> $items
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AppRelease extends Model
{
    use ConditionallyUsesUuids;

    /** @var list<string> */
    protected $fillable = [
        'version',
        'status',
        'published_at',
        'ios_build',
        'android_build',
        'items',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReleaseStatus::class,
            'items' => 'array',
            'published_at' => 'datetime',
            'ios_build' => 'integer',
            'android_build' => 'integer',
        ];
    }

    /**
     * Rows a client may read: published and carrying a publish date.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', ReleaseStatus::PUBLISHED)
            ->whereNotNull('published_at');
    }
}
