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

        // 2. Apply search filter if provided (already sorted by relevance).
        $search = $request->input('search');

        if ($search !== null && $search !== '') {
            $timezones = $this->filterBySearch($timezones, (string) $search);
        } else {
            // 3. Sort by offset_minutes ascending (UTC first, then eastward) when no search.
            $timezones = $timezones->sortBy('offset_minutes')->values();
        }

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
     * Results are sorted by relevance (exact match > starts with > contains).
     *
     * @param  Collection<int, array{identifier: string, label: string, offset: string, offset_minutes: int, region: string}>  $timezones  The full timezone list.
     * @param  string  $search  The search query string.
     * @return Collection<int, array{identifier: string, label: string, offset: string, offset_minutes: int, region: string}>
     */
    private function filterBySearch(Collection $timezones, string $search): Collection
    {
        $needle = mb_strtolower(trim($search));

        if ($needle === '') {
            return $timezones;
        }

        // First, score and filter
        $scored = $timezones->map(function (array $tz) use ($needle): ?array {
            $identifierLower = mb_strtolower($tz['identifier']);
            $offsetLower = mb_strtolower($tz['offset']);
            $regionLower = mb_strtolower($tz['region']);

            // Calculate relevance score (higher = more relevant)
            $score = 0;

            // Exact match on identifier = highest priority
            if ($identifierLower === $needle) {
                $score += 1000;
            }
            // Starts with search term in identifier = high priority
            elseif (str_starts_with($identifierLower, $needle)) {
                $score += 500;
            }
            // Contains search term in identifier = medium priority
            elseif (str_contains($identifierLower, $needle)) {
                $score += 300;
            }

            // Exact match on region
            if ($regionLower === $needle) {
                $score += 200;
            }
            // Contains in region
            elseif (str_contains($regionLower, $needle)) {
                $score += 100;
            }

            // Offset match
            if ($offsetLower === $needle) {
                $score += 150;
            } elseif (str_contains($offsetLower, $needle)) {
                $score += 50;
            }

            if ($score === 0) {
                return null;
            }

            $tz['_relevance'] = $score;

            return $tz;
        });

        // Remove nulls (non-matches), sort by relevance, and clean up
        return $scored
            ->filter()
            ->sortByDesc('_relevance')
            ->map(fn (array $tz): array => [
                'identifier' => $tz['identifier'],
                'label' => $tz['label'],
                'offset' => $tz['offset'],
                'offset_minutes' => $tz['offset_minutes'],
                'region' => $tz['region'],
            ])
            ->values();
    }
}
