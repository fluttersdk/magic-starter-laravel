<?php

namespace FlutterSdk\MagicStarter\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * A read-only view of one RevenueCat project's configuration through API v2:
 * its apps, products, entitlements, offerings, packages and webhooks.
 *
 * `billing:doctor --remote` diffs this against the manifest. Nothing here
 * writes, and nothing here can: every request is a GET, because the package
 * describes the configuration and an agent applies it.
 *
 * It authenticates with a v2 secret key of its own rather than the v1 key
 * {@see RevenueCatClient} uses. The v1 key reads subscribers; v2 project
 * configuration needs a key scoped to `project_configuration:*:read`, and
 * widening the subscriber key to cover it would hand the webhook path more than
 * it needs.
 *
 * Requests are SEQUENTIAL. The v2 API allows 60 requests a minute per key, and
 * one doctor run is a handful of pages; a 429 is waited out for as long as its
 * `Retry-After` asks (capped, so a hostile or broken header cannot park the
 * command for an hour) and the request repeated.
 */
class RevenueCatProjectClient
{
    /**
     * RevenueCat's documented v2 base.
     */
    public const BASE_URL = 'https://api.revenuecat.com/v2';

    /**
     * Attempts per request: the first plus two waits on a 429 or a 5xx.
     */
    public const MAXIMUM_ATTEMPTS = 3;

    /**
     * Pages followed per list before the cursor is treated as broken. A
     * project with more than this many of anything is not one a doctor run
     * should be paging through blindly.
     */
    protected const MAXIMUM_PAGES = 50;

    protected const TIMEOUT_SECONDS = 10;

    /**
     * The longest `Retry-After` honoured, in seconds: the length of the rate
     * window itself.
     */
    protected const MAXIMUM_RETRY_AFTER_SECONDS = 60;

    /**
     * The wait when a retryable answer names none, in seconds.
     */
    protected const DEFAULT_RETRY_AFTER_SECONDS = 1;

    /**
     * @return list<array<string, mixed>> Each app: `id`, `name`, `type` (`app_store`, `play_store`, ...).
     */
    public function apps(): array
    {
        return $this->list('apps');
    }

    /**
     * @return list<array<string, mixed>> Each product: `id`, `store_identifier`, `type`, `app_id`.
     */
    public function products(): array
    {
        return $this->list('products');
    }

    /**
     * @return list<array<string, mixed>> Each entitlement with its attached products under `products.items`.
     */
    public function entitlements(): array
    {
        return $this->list('entitlements', [
            'expand' => 'items.product',
        ]);
    }

    /**
     * @return list<array<string, mixed>> Each offering: `id`, `lookup_key`, `is_current`.
     */
    public function offerings(): array
    {
        return $this->list('offerings');
    }

    /**
     * @return list<array<string, mixed>> Each package with `lookup_key`, `position`, and its products under
     *                                    `products.items[].product`.
     */
    public function packages(string $offeringId): array
    {
        return $this->list('offerings/' . rawurlencode($offeringId) . '/packages', [
            'expand' => 'items.product',
        ]);
    }

    /**
     * The webhook integrations. Their `signing_secret` is in the payload; a
     * caller reads whether it is set and never prints it.
     *
     * @return list<array<string, mixed>>
     */
    public function webhookIntegrations(): array
    {
        return $this->list('integrations/webhooks');
    }

    /**
     * Every item of one project list, following `starting_after` cursors.
     *
     * The cursor is lifted out of `next_page` and sent back against the SAME
     * path, rather than the `next_page` path being requested as given: the
     * client then only ever talks to the base it was configured with.
     *
     * @param  string  $resource  Path below `/projects/{project_id}/`.
     * @param  array<string, string>  $query
     * @return list<array<string, mixed>>
     *
     * @throws ConnectionException When RevenueCat could not be reached.
     * @throws RequestException When RevenueCat answered non-2xx after any retries.
     * @throws RuntimeException When the key or project is unconfigured, or the cursor never ends.
     */
    protected function list(string $resource, array $query = []): array
    {
        $url = self::BASE_URL . '/projects/' . rawurlencode($this->projectId()) . '/' . $resource;
        $items = [];
        $cursor = null;

        for ($page = 1; $page <= self::MAXIMUM_PAGES; $page++) {
            $body = $this->get($url, $cursor === null ? $query : [...$query, 'starting_after' => $cursor])->json();

            foreach (is_array($body['items'] ?? null) ? $body['items'] : [] as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }

            $cursor = $this->cursor($body['next_page'] ?? null);

            if ($cursor === null) {
                return $items;
            }
        }

        throw new RuntimeException(sprintf(
            'RevenueCat kept paging [%s] past %d pages; the cursor is not advancing.',
            $resource,
            self::MAXIMUM_PAGES,
        ));
    }

    /**
     * One GET, repeated after a 429 or a 5xx for as long as the answer asks.
     *
     * @param  array<string, string>  $query
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    protected function get(string $url, array $query): Response
    {
        $attempt = 1;

        while (true) {
            $response = Http::withToken($this->apiKey())
                ->acceptJson()
                ->timeout(self::TIMEOUT_SECONDS)
                ->get($url, $query);

            $retryable = $response->status() === 429 || $response->serverError();

            if (! $retryable || $attempt >= self::MAXIMUM_ATTEMPTS) {
                return $response->throw();
            }

            Sleep::for($this->retryAfter($response))->seconds();
            $attempt++;
        }
    }

    /**
     * The wait a retryable answer asks for, in whole seconds within the cap.
     */
    protected function retryAfter(Response $response): int
    {
        $header = trim($response->header('Retry-After'));

        if (! ctype_digit($header)) {
            return self::DEFAULT_RETRY_AFTER_SECONDS;
        }

        return max(1, min((int) $header, self::MAXIMUM_RETRY_AFTER_SECONDS));
    }

    /**
     * The `starting_after` value of a `next_page` path, or null at the end.
     */
    protected function cursor(mixed $nextPage): ?string
    {
        if (! is_string($nextPage) || $nextPage === '') {
            return null;
        }

        parse_str((string) parse_url($nextPage, PHP_URL_QUERY), $query);
        $cursor = $query['starting_after'] ?? null;

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    /**
     * The v2 secret key. Fallback-free, like {@see RevenueCatClient::apiKey()}.
     *
     * @throws RuntimeException
     */
    protected function apiKey(): string
    {
        return $this->required('api_v2_key', 'REVENUECAT_API_V2_KEY');
    }

    /**
     * @throws RuntimeException
     */
    protected function projectId(): string
    {
        return $this->required('project_id', 'REVENUECAT_PROJECT_ID');
    }

    /**
     * @throws RuntimeException Naming the environment variable, never the value.
     */
    protected function required(string $key, string $env): string
    {
        $value = config("magic-starter.billing.revenuecat.{$key}");

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException(
                "The RevenueCat project cannot be read: [magic-starter.billing.revenuecat.{$key}] is not set. "
                . "Set {$env}.",
            );
        }

        return $value;
    }
}
