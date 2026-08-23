<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Testing\TestResponse;

/**
 * A webhook delivery whose body reaches the application BYTE FOR BYTE.
 *
 * This exists because `postJson()` cannot be used to test a signed webhook.
 * `postJson()` takes an ARRAY and encodes it itself, so the bytes that arrive
 * are the framework's encoding of the payload rather than the sender's: an
 * unescaped slash becomes `\/`, `9.90` becomes `9.9`, a Turkish character
 * becomes a `\u` escape, and every line break and indentation the sender chose
 * is gone.
 *
 * Every webhook signature this package verifies is an HMAC over the RAW bytes
 * of `"{timestamp}.{body}"` (Stripe's scheme, and the one RevenueCat's handler
 * follows), so a body the framework re-encoded fails a signature that was
 * genuinely valid. A test built on `postJson()` therefore cannot tell a real
 * signature failure from its own harness, which is the whole reason the raw
 * bytes travel through {@see TestCase::call()} here instead.
 *
 * Usage:
 *
 *     RawWebhookRequest::withBody($raw)
 *         ->signedWith($secret)
 *         ->deliverTo($this, 'webhooks/revenuecat');
 */
final class RawWebhookRequest
{
    /**
     * The signature header RevenueCat actually sends.
     *
     * Named here rather than at each call site so the harness and the handler
     * cannot drift apart in a way that reads as a signature failure, which is
     * exactly what a guessed name would have produced: the value is taken from
     * RevenueCat's own webhook documentation, which specifies
     * `X-RevenueCat-Webhook-Signature: t=<unix_timestamp>,v1=<hmac_sha256_hex>`.
     *
     * Note that HMAC signing is OPT-IN per webhook integration and is described
     * as the stronger option; the baseline mechanism is a static `Authorization`
     * header value configured in the RevenueCat dashboard. This package refuses
     * that baseline outright, so there is no second path to exercise: an
     * integration left on it is refused exactly like an unsigned delivery.
     *
     * UNTYPED, and it has to be. `public const string` is PHP 8.3, this package
     * floors at 8.2, and PHPUnit PARSES every test-support file: a typed constant
     * here is the same fatal parse error as one under `src/`, and the fact that
     * PHPStan never looks at this directory would not soften it.
     */
    public const SIGNATURE_HEADER = 'X-RevenueCat-Webhook-Signature';

    /**
     * @param  string  $raw  The exact bytes to deliver, signed and unmodified.
     * @param  array<string, string>  $headers  Headers as a client would send them.
     */
    private function __construct(
        private readonly string $raw,
        private readonly array $headers = ['Content-Type' => 'application/json'],
    ) {}

    /**
     * A delivery of exactly these bytes.
     *
     * @param  string  $raw  The body as the sender wrote it, whitespace, escaping and number formatting included.
     */
    public static function withBody(string $raw): self
    {
        return new self($raw);
    }

    /**
     * A delivery of an event array, encoded ONCE here.
     *
     * Encoding once is the point: the bytes this produces are the bytes that get
     * signed and the bytes that get delivered, so there is no second encoding
     * anywhere that could disagree with the signature.
     *
     * @param  array<string, mixed>  $payload
     * @param  int  $flags  `json_encode` flags, the sender's own choice of encoding.
     */
    public static function withPayload(array $payload, int $flags = 0): self
    {
        return new self((string) json_encode($payload, $flags));
    }

    /**
     * The same delivery carrying one more header.
     */
    public function withHeader(string $name, string $value): self
    {
        return new self($this->raw, [...$this->headers, $name => $value]);
    }

    /**
     * The same delivery, signed over `"{timestamp}.{raw body}"`.
     *
     * @param  string  $secret  The shared signing secret.
     * @param  int|null  $timestamp  The signing time, defaulting to now. Pass an
     *                               old one to exercise a tolerance window.
     * @param  string  $header  The header the signature travels in.
     */
    public function signedWith(string $secret, ?int $timestamp = null, string $header = self::SIGNATURE_HEADER): self
    {
        $signedAt = $timestamp ?? time();

        return $this->withHeader(
            $header,
            "t={$signedAt},v1=" . self::signatureFor($this->raw, $secret, $signedAt),
        );
    }

    /**
     * The HMAC a verifier must recompute, over the raw bytes it received.
     *
     * Exposed so a test can sign one body and verify another: the two sides both
     * go through this method, so a byte-fidelity failure surfaces as a signature
     * mismatch rather than as a difference in how the two halves computed it.
     *
     * @param  string  $raw  The body bytes, exactly as they travelled.
     * @param  string  $secret  The shared signing secret.
     * @param  int  $timestamp  The signing time carried in the header.
     */
    public static function signatureFor(string $raw, string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$raw}", $secret);
    }

    /**
     * The bytes this delivery will send.
     */
    public function body(): string
    {
        return $this->raw;
    }

    /**
     * The headers this delivery will send, as a client would name them.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * POST the raw body to a route inside the given test's application.
     *
     * `call()` rather than `postJson()` because `call()` hands its `$content`
     * argument straight to `Request::create()`, so nothing between here and the
     * controller re-encodes it.
     *
     * @param  TestCase  $test  The test whose application receives the delivery.
     * @param  string  $uri  The route to deliver to.
     */
    public function deliverTo(TestCase $test, string $uri): TestResponse
    {
        return $test->call('POST', $uri, [], [], [], $this->serverVariables(), $this->raw);
    }

    /**
     * The headers as CGI server variables, which is the only shape
     * {@see TestCase::call()} accepts.
     *
     * `Content-Type` is deliberately NOT prefixed: PHP carries it as
     * `CONTENT_TYPE`, and a `HTTP_CONTENT_TYPE` would leave the request with no
     * content type at all.
     *
     * @return array<string, string>
     */
    private function serverVariables(): array
    {
        $variables = [];

        foreach ($this->headers as $name => $value) {
            $normalized = strtoupper(str_replace('-', '_', $name));

            $variables[$normalized === 'CONTENT_TYPE' ? $normalized : "HTTP_{$normalized}"] = $value;
        }

        return $variables;
    }
}
