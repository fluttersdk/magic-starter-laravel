<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use FlutterSdk\MagicStarter\Support\RevenueCatClient;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The store rail's authoritative read: a bearer-keyed GET, retried only when
 * the failure is transient, bounded by ONE budget across every attempt.
 *
 * `LoopbackHttpServer` does not travel with this port: it is 493 lines shared
 * with a probe-engine test that has nothing to do with billing, so it stays in
 * the application these classes were ported from. `Http::fake` cannot certify
 * the WIRE the way a real socket does (a wrong path, a dropped header, an
 * unhonoured `->timeout()` are all invisible above Guzzle's `CurlFactory`), so
 * this suite recovers the bearer-key and budget assertions in a NARROWER form:
 * the request shape through `Http::assertSent()`, and the budget through the
 * client's own wall-clock bookkeeping rather than a real stalled connection.
 * `usleep()` inside a fake response gives each simulated attempt a real,
 * measurable cost, which is enough to prove the retry loop stops consuming the
 * budget rather than restarting it on every call.
 */
class RevenueCatClientTest extends TestCase
{
    private const API_KEY = 'sk_test_revenuecat_secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['magic-starter.billing.revenuecat.secret_api_key' => self::API_KEY]);
    }

    /**
     * THE REQUEST SHAPE. The client asks the documented endpoint, with a
     * bearer key, and reads the `subscriber` object back out of the body.
     */
    public function test_the_client_asks_the_authoritative_endpoint_with_a_bearer_key(): void
    {
        Http::fake([
            '*' => Http::response([
                'subscriber' => [
                    'original_app_user_id' => 'the-subscriber',
                    'management_url' => 'https://app.revenuecat.com/manage/the-subscriber',
                ],
            ]),
        ]);

        $subscriber = (new RevenueCatClient)->subscriber('the-subscriber');

        Http::assertSent(function ($request): bool {
            return $request->method() === 'GET'
                && $request->url() === RevenueCatClient::DEFAULT_BASE_URL . '/subscribers/the-subscriber'
                && $request->hasHeader('Authorization', 'Bearer ' . self::API_KEY);
        });

        $this->assertSame(
            'https://app.revenuecat.com/manage/the-subscriber',
            $subscriber['management_url'] ?? null,
        );
    }

    /**
     * A configured base URL with a trailing slash does not produce a doubled
     * one in the request path.
     */
    public function test_a_configured_base_url_is_used_without_a_doubled_slash(): void
    {
        config(['magic-starter.billing.revenuecat.base_url' => 'https://example.test/v1/']);

        Http::fake(['*' => Http::response(['subscriber' => []])]);

        (new RevenueCatClient)->subscriber('the-subscriber');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://example.test/v1/subscribers/the-subscriber');
    }

    /**
     * A 5xx is retried up to the attempt limit and succeeds on the eventual
     * good answer.
     */
    public function test_a_transient_failure_is_retried_up_to_the_attempt_limit(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['error' => 'unavailable'], 503)
                ->push(['error' => 'unavailable'], 503)
                ->push(['subscriber' => ['original_app_user_id' => 'the-subscriber']], 200),
        ]);

        $subscriber = (new RevenueCatClient)->subscriber('the-subscriber');

        Http::assertSentCount(RevenueCatClient::MAXIMUM_ATTEMPTS);
        $this->assertSame('the-subscriber', $subscriber['original_app_user_id'] ?? null);
    }

    /**
     * A 401 is a configuration answer, not a network hiccup: asking again
     * cannot change it, so it is raised on the FIRST attempt.
     */
    public function test_a_permanent_failure_is_not_retried(): void
    {
        Http::fake(['*' => Http::response(['error' => 'unauthorized'], 401)]);

        $this->expectException(RequestException::class);

        try {
            (new RevenueCatClient)->subscriber('the-subscriber');
        } finally {
            Http::assertSentCount(1);
        }
    }

    /**
     * A body with no `subscriber` object raises rather than reading as an
     * absence of subscriptions: a shape this client cannot read is not the
     * same claim as "nothing is owed".
     */
    public function test_a_body_without_a_subscriber_object_raises(): void
    {
        Http::fake(['*' => Http::response(['not_a_subscriber' => []], 200)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('without a subscriber object');

        (new RevenueCatClient)->subscriber('the-subscriber');
    }

    /**
     * An unconfigured API key refuses to ask at all, rather than authenticating
     * as nobody and reading the resulting 401 as an expired subscription.
     */
    public function test_an_unconfigured_api_key_refuses_to_ask(): void
    {
        config(['magic-starter.billing.revenuecat.secret_api_key' => null]);

        Http::fake(['*' => Http::response(['subscriber' => []])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('REVENUECAT_SECRET_API_KEY');

        (new RevenueCatClient)->subscriber('the-subscriber');
    }

    /**
     * THE BUDGET BOUNDS THE OPERATION, NOT ONE CALL.
     *
     * Each simulated attempt costs real wall-clock time (`usleep`), and the
     * budget is sized so that a first, failed attempt already leaves less than
     * `RevenueCatClient::MINIMUM_RETRY_SECONDS` behind. The property under test
     * is that the retry loop reads that and stops, rather than starting a
     * second attempt on the strength of a freshly reset per-call timeout.
     *
     * The mutant this catches is a per-call bound instead of an operation one:
     * a client that granted each attempt its own fresh slice of time would
     * retry up to the full `MAXIMUM_ATTEMPTS`, and a PER-CALL assertion such as
     * "one request completes in under a second" would still pass on that
     * mutant, because 0.5s per attempt is still under a second. Only the TOTAL
     * elapsed time tells the two apart: one attempt costs ~0.5s here, three
     * would cost ~1.9s (3 * 0.5s attempts plus 2 * 0.2s retry delays).
     */
    public function test_the_operation_budget_bounds_the_whole_retried_operation_not_each_call(): void
    {
        config(['magic-starter.billing.revenuecat.operation_budget_seconds' => 1]);

        Http::fake(function () {
            usleep(500_000);

            return Http::response(['error' => 'unavailable'], 503);
        });

        $startedAt = microtime(true);

        try {
            (new RevenueCatClient)->subscriber('the-subscriber');
            $this->fail('A permanently-failing budget must raise, not answer with a subscriber.');
        } catch (RequestException) {
            // Expected: the sole attempt's own 503 is re-thrown once the
            // budget cannot afford a retry.
        }

        $elapsed = microtime(true) - $startedAt;

        Http::assertSentCount(1);

        // 1.4s rather than the 1s budget itself: the two outcomes this
        // separates are ~0.5s (one attempt) and ~1.9s (three attempts plus two
        // retry delays), so a threshold between them proves the same thing with
        // room for a loaded CI box. Sizing it flush against the budget would
        // turn a busy runner into a red build that says nothing about the code.
        $this->assertLessThan(
            1.4,
            $elapsed,
            "The client took {$elapsed}s against a 1s budget and a single 0.5s attempt, so it retried "
            . 'past the point the operation budget allowed.',
        );
    }
}
