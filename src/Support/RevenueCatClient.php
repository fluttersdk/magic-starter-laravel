<?php

namespace FlutterSdk\MagicStarter\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * The authoritative read of a store subscriber's state.
 *
 * RevenueCat's own guidance is that a webhook is a signal, not a source: the
 * payload says something changed, and the change itself is read back from
 * `GET /v1/subscribers/{app_user_id}`. This client is that read, and it is the
 * only place the store rail's truth enters the application (the queued
 * re-read job dispatched from the store webhook is its only caller).
 *
 * ## A failed read is an ERROR, never an empty subscriber
 *
 * Every failure here raises. That is the load-bearing decision in the file:
 * the caller turns this response into a tier, so a response that never arrived
 * must not be readable as "nothing is owed". Returning an empty array on a 503
 * would revoke every paying team the moment RevenueCat had an outage, and it
 * would do it silently. A raise leaves the entitlement exactly as it was and
 * hands the retry to the queue.
 *
 * The same reasoning covers a body without a `subscriber` object: a shape this
 * client cannot read is not an absence of subscriptions.
 *
 * ## The budget bounds the OPERATION, not one call
 *
 * A per-call timeout sized against a wall is wrong the moment anything
 * retries: a per-call timeout under an outer deadline still lets a forgotten
 * retry multiply it. So one budget covers every attempt, a retry only starts
 * if enough of the budget is left to be worth starting, and the arithmetic
 * that matters holds by construction:
 *
 *   one authoritative read <= `magic-starter.billing.revenuecat.operation_budget_seconds` (10)
 *   the re-read job         <= its own queue timeout
 *   the queue worker        <= its own supervisor timeout
 *
 * Only a transient failure is retried (a connection failure, a 5xx, a 429). A
 * 401 or a 404 is a configuration answer, and asking the same question again
 * cannot change it.
 */
class RevenueCatClient
{
    /**
     * RevenueCat's documented API base. A constant rather than a config-only
     * value because it is a published endpoint, not a secret and not a
     * deployment choice; `magic-starter.billing.revenuecat.base_url` overrides
     * it so a test can point the client at a local listener.
     */
    public const DEFAULT_BASE_URL = 'https://api.revenuecat.com/v1';

    /**
     * How many attempts one read may ever make, budget permitting. Pinned as a
     * public constant because the retry policy is part of this client's
     * contract: a test asserts the count rather than merely asserting a throw.
     */
    public const MAXIMUM_ATTEMPTS = 3;

    /**
     * The whole-operation budget, in seconds, when config does not name one.
     */
    protected const DEFAULT_OPERATION_BUDGET_SECONDS = 10;

    /**
     * The smallest slice of the budget worth starting a RETRY with. The first
     * attempt always gets whatever the budget holds; a retry that could only
     * run for a moment would spend the remainder of the budget to fail again.
     */
    protected const MINIMUM_RETRY_SECONDS = 2.0;

    /**
     * How long to wait before a retry, in milliseconds. Spent from the same
     * budget as the attempts themselves.
     */
    protected const RETRY_DELAY_MS = 200;

    /**
     * Read one subscriber's authoritative state.
     *
     * @param  string  $appUserId  the RevenueCat App User ID, which in the
     *                             consuming application is the billable's key
     * @return array<string, mixed> the `subscriber` object: `subscriptions`,
     *                              `entitlements`, `management_url` and friends
     *
     * @throws ConnectionException When no attempt reached RevenueCat inside the budget.
     * @throws RequestException When RevenueCat answered with a non-2xx status.
     * @throws RuntimeException When the API key is unconfigured, the budget is exhausted
     *                          before an attempt could start, or the body carries no
     *                          `subscriber` object.
     */
    public function subscriber(string $appUserId): array
    {
        $deadline = microtime(true) + $this->operationBudgetSeconds();
        $attempts = 0;
        $lastFailure = null;

        while ($attempts < self::MAXIMUM_ATTEMPTS) {
            $remaining = $deadline - microtime(true);

            // 1. Out of budget. On the first pass that means the budget itself
            //    was too small to try at all, which is a configuration answer
            //    rather than a network one, hence the raise below.
            if ($remaining <= 0 || ($attempts > 0 && $remaining < self::MINIMUM_RETRY_SECONDS)) {
                break;
            }

            $attempts++;

            try {
                return $this->attempt($appUserId, $remaining);
            } catch (ConnectionException|RequestException $failure) {
                // 2. Not swallowed: a transient failure is retried inside the
                //    same budget and re-thrown below if nothing succeeds, and
                //    anything else leaves immediately because a second identical
                //    question gets the same answer.
                if (! $this->isTransient($failure)) {
                    throw $failure;
                }

                $lastFailure = $failure;
                usleep(self::RETRY_DELAY_MS * 1000);
            }
        }

        if ($lastFailure instanceof Throwable) {
            throw $lastFailure;
        }

        throw new RuntimeException(
            "The RevenueCat operation budget was exhausted before a read of subscriber [{$appUserId}] could start.",
        );
    }

    /**
     * One attempt, bounded by whatever is left of the operation budget.
     *
     * @return array<string, mixed>
     */
    protected function attempt(string $appUserId, float $timeout): array
    {
        $response = Http::withToken($this->apiKey())
            ->acceptJson()
            ->timeout($timeout)
            ->get($this->subscriberUrl($appUserId))
            ->throw();

        $subscriber = $response->json('subscriber');

        if (! is_array($subscriber)) {
            throw new RuntimeException(
                "RevenueCat answered for subscriber [{$appUserId}] without a subscriber object.",
            );
        }

        return $subscriber;
    }

    /**
     * Whether asking the same question again could plausibly get a different
     * answer.
     */
    protected function isTransient(Throwable $failure): bool
    {
        if ($failure instanceof ConnectionException) {
            return true;
        }

        return $failure instanceof RequestException
            && ($failure->response->serverError() || $failure->response->status() === 429);
    }

    /**
     * The endpoint for one subscriber.
     */
    protected function subscriberUrl(string $appUserId): string
    {
        return $this->baseUrl() . '/subscribers/' . rawurlencode($appUserId);
    }

    /**
     * The API base, without its trailing slash.
     */
    protected function baseUrl(): string
    {
        $configured = config('magic-starter.billing.revenuecat.base_url');

        return rtrim(is_string($configured) && $configured !== '' ? $configured : self::DEFAULT_BASE_URL, '/');
    }

    /**
     * The secret API key.
     *
     * Deliberately fallback-free. A default would be either a secret in a
     * public repository or a value that authenticates as nobody, and the
     * second one fails as a 401 the caller would then have to be careful not
     * to read as an expired subscription. An unconfigured rail refuses to ask.
     */
    protected function apiKey(): string
    {
        $key = config('magic-starter.billing.revenuecat.secret_api_key');

        if (! is_string($key) || trim($key) === '') {
            throw new RuntimeException(
                'The RevenueCat secret API key is not configured, so the authoritative subscriber read '
                . 'cannot be made. Set REVENUECAT_SECRET_API_KEY.',
            );
        }

        return $key;
    }

    /**
     * The whole-operation budget in seconds.
     */
    protected function operationBudgetSeconds(): float
    {
        $configured = config('magic-starter.billing.revenuecat.operation_budget_seconds');

        return is_numeric($configured) && (float) $configured > 0
            ? (float) $configured
            : (float) self::DEFAULT_OPERATION_BUDGET_SECONDS;
    }
}
