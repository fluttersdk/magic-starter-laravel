<?php

namespace FlutterSdk\MagicStarter\Models;

use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The body staff chose for one registered prompt, replacing its code default.
 *
 * Only the body is stored: the boundary block and the required phrases belong to
 * the code registration and are never written here. `default_hash` records which
 * code default the edit started from. Every save and delete forgets
 * {@see self::CACHE_KEY} once the write has committed (at once outside a
 * transaction), so the resolver's cached map of overrides is rebuilt on the next
 * read and a change takes effect without waiting out the TTL. The forget waits
 * for the commit because a reader that rebuilt the map from the rows before it
 * would otherwise cache them again just after the forget; the resolver's TTL
 * bounds the race that remains. A write that bypasses the model (a raw query,
 * `insert`, `upsert`) fires no event and leaves the cache stale until that TTL.
 *
 * @property string|int $id
 * @property string $key
 * @property string $body
 * @property string $default_hash
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AiPromptOverride extends Model
{
    use ConditionallyUsesUuids;

    /** The cache key under which the resolver holds every override, keyed by prompt key. */
    public const CACHE_KEY = 'magic-starter:ai-prompt-overrides';

    protected $table = 'ai_prompt_overrides';

    /** @var list<string> */
    protected $fillable = [
        'key',
        'body',
        'default_hash',
    ];

    protected static function booted(): void
    {
        static::saved(static function (): void {
            DB::afterCommit(static fn () => Cache::forget(self::CACHE_KEY));
        });

        static::deleted(static function (): void {
            DB::afterCommit(static fn () => Cache::forget(self::CACHE_KEY));
        });
    }
}
