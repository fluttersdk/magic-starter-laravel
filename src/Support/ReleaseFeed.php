<?php

namespace FlutterSdk\MagicStarter\Support;

use FlutterSdk\MagicStarter\Http\Resources\AppReleaseResource;
use FlutterSdk\MagicStarter\Models\AppRelease;
use Illuminate\Support\Facades\Cache;

/**
 * The cached body of the public releases endpoint.
 *
 * One key holds the whole answer, so a release saved or deleted drops exactly
 * that key and nothing else in the host's cache. The TTL is the backstop for a
 * write that bypasses model events; the observer is the normal way out.
 */
final class ReleaseFeed
{
    public const CACHE_KEY = 'magic-starter:releases';

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
        return Cache::remember(self::CACHE_KEY, self::TTL_SECONDS, static fn (): array => [
            'data' => self::releases(),
            'meta' => [
                'latest_build' => self::latestBuilds(),
            ],
        ]);
    }

    /**
     * Drop the cached body so the next read rebuilds it from the table.
     */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
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
