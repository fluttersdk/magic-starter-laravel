<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use FlutterSdk\MagicStarter\Http\Resources\TimezoneResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Provides a paginated, searchable list of IANA timezones.
 *
 * Data is sourced from PHP's DateTimeZone::listIdentifiers() — this is NOT
 * backed by a database table. Supports case-insensitive search on both the
 * identifier string and the UTC offset string (e.g., "+03:00").
 */
class TimezoneController
{
    /**
     * Return a paginated list of timezones, optionally filtered by search.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        // 1. Build the full timezone collection with offset metadata.
        $timezones = $this->buildTimezoneList();

        // 2. Apply search filter if provided.
        $search = $request->input('search');

        if ($search !== null && $search !== '') {
            $timezones = $this->filterBySearch($timezones, (string) $search);
        }

        // 3. Sort by offset_minutes ascending (UTC first, then eastward).
        $timezones = $timezones->sortBy('offset_minutes')->values();

        // 4. Manually paginate the collection.
        $perPage = (int) $request->input('per_page', 15);
        $page = (int) $request->input('page', 1);
        $total = $timezones->count();

        $paginator = new LengthAwarePaginator(
            items: $timezones->forPage($page, $perPage)->values(),
            total: $total,
            perPage: $perPage,
            currentPage: $page,
            options: [
                'path' => $request->url(),
                'query' => $request->query(),
            ],
        );

        return TimezoneResource::collection($paginator);
    }

    /**
     * Build the complete timezone collection from PHP's DateTimeZone list.
     *
     * Each item contains the IANA identifier, a human-readable label with
     * UTC offset, the offset string, offset in minutes, and the region.
     *
     * @return Collection<int, array{
     *     identifier: string,
     *     label: string,
     *     offset: string,
     *     offset_minutes: int,
     *     region: string,
     * }>
     */
    private function buildTimezoneList(): Collection
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return collect(DateTimeZone::listIdentifiers())
            ->map(function (string $identifier) use ($now): array {
                $tz = new DateTimeZone($identifier);
                $offsetSeconds = $tz->getOffset($now);
                $offsetMinutes = intdiv($offsetSeconds, 60);

                $hours = intdiv(abs($offsetSeconds), 3600);
                $minutes = intdiv(abs($offsetSeconds) % 3600, 60);
                $sign = $offsetSeconds >= 0 ? '+' : '-';
                $offset = sprintf('%s%02d:%02d', $sign, $hours, $minutes);

                $region = str_contains($identifier, '/')
                    ? strstr($identifier, '/', true)
                    : $identifier;

                return [
                    'identifier' => $identifier,
                    'label' => "(UTC{$offset}) {$identifier}",
                    'offset' => $offset,
                    'offset_minutes' => $offsetMinutes,
                    'region' => (string) $region,
                ];
            });
    }

    /**
     * Filter timezones by case-insensitive partial match on identifier or offset.
     *
     * @param  Collection<int, array{identifier: string, label: string, offset: string, offset_minutes: int, region: string}>  $timezones  The full timezone list.
     * @param  string  $search  The search query string.
     * @return Collection<int, array{identifier: string, label: string, offset: string, offset_minutes: int, region: string}>
     */
    private function filterBySearch(Collection $timezones, string $search): Collection
    {
        $needle = mb_strtolower($search);

        return $timezones->filter(function (array $tz) use ($needle): bool {
            return str_contains(mb_strtolower($tz['identifier']), $needle)
                || str_contains(mb_strtolower($tz['offset']), $needle);
        });
    }
}
