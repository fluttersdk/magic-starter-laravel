<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/**
 * The Stripe SDK's transport, replaced: every request Cashier's real Stripe
 * client makes is recorded and answered from a queue of canned JSON bodies.
 *
 * Only the transport is faked, so everything above it (Cashier's builders, the
 * SDK's parameter encoding, the resource it hydrates from the answer) is the
 * code that runs in production. That is the point: a fake builder can only
 * certify what the controller asked the builder for, while this certifies what
 * Stripe would actually have received.
 *
 * Installed with {@see self::install()} and removed with {@see self::uninstall()}
 * in the test's `tearDown()`. The SDK keeps its client in a STATIC, so a stub
 * left installed would answer for every later test in the process.
 *
 * A request with no answer queued gets a 500 carrying a Stripe error body that
 * names the request, which the SDK raises as an `ApiErrorException`: an
 * unexpected call fails loudly, with the call in the message, rather than
 * hydrating an empty object a test could pass against.
 */
final class StripeHttpStub implements ClientInterface
{
    /**
     * Every request made, in order. `path` is the URL path alone (`/v1/customers`),
     * and `params` is the nested array the SDK encodes onto the wire, so a nested
     * field reads as `$params['subscription_data']['trial_end']`.
     *
     * @var list<array{method: string, path: string, params: array<string, mixed>}>
     */
    public array $requests = [];

    /**
     * Raw body, HTTP status and headers, answered in the order they were queued.
     *
     * @var list<array{0: string, 1: int, 2: array<string, string>}>
     */
    private array $answers = [];

    /**
     * Put a fresh stub in front of the SDK and answer it.
     */
    public static function install(): self
    {
        $stub = new self;

        ApiRequestor::setHttpClient($stub);

        return $stub;
    }

    /**
     * Hand the SDK back its default transport, which it builds lazily on the
     * next request.
     */
    public static function uninstall(): void
    {
        ApiRequestor::setHttpClient(null);
    }

    /**
     * Queue the next answer.
     *
     * @param  array<string, mixed>  $body  Decoded JSON, as Stripe would send it.
     * @param  int  $status  The HTTP status; a 4xx or 5xx makes the SDK raise.
     */
    public function answer(array $body, int $status = 200): self
    {
        $this->answers[] = [
            (string) json_encode($body),
            $status,
            [],
        ];

        return $this;
    }

    /**
     * The requests made to one method and path, in order.
     *
     * @param  'delete'|'get'|'post'  $method
     * @return list<array{method: string, path: string, params: array<string, mixed>}>
     */
    public function requestsTo(string $method, string $path): array
    {
        return array_values(array_filter(
            $this->requests,
            static fn (array $request): bool => $request['method'] === $method && $request['path'] === $path,
        ));
    }

    /**
     * @param  'delete'|'get'|'post'  $method
     * @param  string  $absUrl
     * @param  array<int, string>  $headers
     * @param  array<string, mixed>  $params
     * @param  bool  $hasFile
     * @param  'v1'|'v2'  $apiMode
     * @param  int|null  $maxNetworkRetries
     * @return array{0: string, 1: int, 2: array<string, string>}
     */
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $path = (string) parse_url($absUrl, PHP_URL_PATH);

        $this->requests[] = [
            'method' => $method,
            'path' => $path,
            'params' => $params,
        ];

        return array_shift($this->answers) ?? [
            (string) json_encode([
                'error' => [
                    'type' => 'api_error',
                    'message' => sprintf('StripeHttpStub has no answer queued for %s %s.', strtoupper($method), $path),
                ],
            ]),
            500,
            [],
        ];
    }
}
