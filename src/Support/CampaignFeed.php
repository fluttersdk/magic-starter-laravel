<?php

namespace FlutterSdk\MagicStarter\Support;

use FlutterSdk\MagicStarter\Http\Resources\CampaignResource;
use FlutterSdk\MagicStarter\Models\Campaign;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The cached campaign rows behind `GET billing/campaign`.
 *
 * What is cached is every enabled campaign whose window had not ended when the
 * rows were read, already shaped by {@see CampaignResource}. Which of them is
 * running is decided at READ time against now(), so the clock starts and ends
 * a campaign and no write has to. A save or a delete replaces the version token
 * the rows are cached under, as {@see ReleaseFeed} does and for the race it
 * describes; the TTL is the backstop for a write that bypasses model events.
 *
 * The checkout never reads this: it asks the table, so the discount a session
 * carries does not depend on a cache.
 */
final class CampaignFeed
{
    public const CACHE_KEY = 'magic-starter:campaigns';

    public const VERSION_KEY = 'magic-starter:campaigns:version';

    public const TTL_SECONDS = 3600;

    /**
     * The campaign running now, as the client reads it, or null.
     *
     * The write contracts allow one enabled campaign at any moment, so the
     * first row whose window holds now is the only one.
     *
     * @return array<string, mixed>|null
     */
    public static function active(): ?array
    {
        $now = now();

        foreach (self::rows() as $row) {
            if (Carbon::parse($row['starts_at'])->lte($now) && Carbon::parse($row['ends_at'])->gt($now)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Retire the cached rows: the next read builds them from the table.
     */
    public static function forget(): void
    {
        Cache::forever(self::VERSION_KEY, (string) Str::uuid());
    }

    /**
     * Every enabled campaign that had not ended when the rows were read.
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(): array
    {
        // Read the version first, so a write that lands during the rebuild
        // leaves these rows under the version it replaced.
        $key = self::CACHE_KEY . ':' . self::version();

        return Cache::remember($key, self::TTL_SECONDS, static function (): array {
            $campaigns = Campaign::query()
                ->where('enabled', true)
                ->where('ends_at', '>', now())
                ->orderBy('starts_at')
                ->get();

            return array_values(CampaignResource::collection($campaigns)->resolve());
        });
    }

    /**
     * The current version token, minted when the store holds none.
     */
    private static function version(): string
    {
        Cache::add(self::VERSION_KEY, (string) Str::uuid());

        return (string) Cache::get(self::VERSION_KEY);
    }
}
