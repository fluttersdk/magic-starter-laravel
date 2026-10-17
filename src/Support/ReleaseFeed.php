<?php

namespace FlutterSdk\MagicStarter\Support;

use FlutterSdk\MagicStarter\Http\Resources\AppReleaseResource;
use FlutterSdk\MagicStarter\Models\AppRelease;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The cached body of the public releases endpoint.
 *
 * The body is cached under a key that carries a version token, and a release
 * saved or deleted replaces the token rather than deleting the body. A rebuild
 * that read the table before a write and stores its body after the write's
 * observer ran therefore stores it under a token nobody reads any more, so the
 * write cannot be hidden behind the stale body (the race plain cache-aside has).
 * A superseded body expires with the TTL, which is also the backstop for a
 * write that bypasses model events.
 */
final class ReleaseFeed
{
    public const CACHE_KEY = 'magic-starter:releases';

    public const VERSION_KEY = 'magic-starter:releases:version';

    public const TTL_SECONDS = 3600;

    /**
     * The `{data, meta}` body, built once and then served from the cache.
     *
     * `data` is capped by `magic-starter.releases.limit`; `meta.latest_build` is
     * not, because a client compares its own build against every published one.
     *
     * @return array{
     *     data: list<array<string, mixed>>,
     *     meta: array{latest_build: array{ios: int|null, android: int|null}},
     * }
     */
    public static function payload(): array
    {
        // 1. Read the version first, so a write that lands during the rebuild
        //    leaves this body under the version it replaced.
        $key = self::CACHE_KEY . ':' . self::version();

        return Cache::remember($key, self::TTL_SECONDS, static fn (): array => [
            'data' => self::releases(),
            'meta' => [
                'latest_build' => self::latestBuilds(),
            ],
        ]);
    }

    /**
     * Retire the cached body: the next read builds a new one from the table.
     *
     * Replaces the version token instead of deleting the body, see the class
     * doc block for why.
     */
    public static function forget(): void
    {
        Cache::forever(self::VERSION_KEY, (string) Str::uuid());
    }

    /**
     * The current version token, minted when the store holds none.
     *
     * A token is random rather than a counter, so a token lost to eviction is
     * never minted again and cannot point back at an old body.
     */
    private static function version(): string
    {
        Cache::add(self::VERSION_KEY, (string) Str::uuid());

        return (string) Cache::get(self::VERSION_KEY);
    }

    /**
     * The newest published releases, as the resource shapes them.
     *
     * @return list<array<string, mixed>>
     */
    private static function releases(): array
    {
        $releases = AppRelease::query()
            ->published()
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit((int) config('magic-starter.releases.limit', 50))
            ->get();

        return array_values(AppReleaseResource::collection($releases)->resolve());
    }

    /**
     * The highest build of each platform over every published release, null for a platform none carries.
     *
     * @return array{ios: int|null, android: int|null}
     */
    private static function latestBuilds(): array
    {
        $builds = AppRelease::query()
            ->published()
            ->toBase()
            ->selectRaw('max(ios_build) as ios, max(android_build) as android')
            ->first();

        return [
            'ios' => $builds?->ios === null ? null : (int) $builds->ios,
            'android' => $builds?->android === null ? null : (int) $builds->android,
        ];
    }
}
