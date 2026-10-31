<?php

namespace FlutterSdk\MagicStarter\Tests\Http;

use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * An IPv6 client is routinely handed a whole /64, so a limiter keyed on the full
 * address gives one host 2^64 fresh buckets: guesses against one account, or
 * confirmation mail to one inbox, are then not bounded at all. Every named limiter
 * that keys on the source address collapses an IPv6 address to its /64; an IPv4
 * address is untouched.
 */
final class RateLimiterAddressKeyTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function addressKeyedLimiters(): array
    {
        return [
            'login' => ['magic-starter-auth-login', 'POST', '/auth/login'],
            'register' => ['magic-starter-auth-register', 'POST', '/auth/register'],
            'social' => ['magic-starter-auth-social', 'POST', '/auth/social/google/token'],
            'password reset' => ['magic-starter-auth-password-reset', 'POST', '/auth/forgot-password'],
            'two factor challenge' => ['magic-starter-2fa-challenge', 'POST', '/auth/two-factor-challenge'],
            'guest auth' => ['magic-starter-guest-auth', 'POST', '/auth/guest'],
            'otp send' => ['magic-starter-otp', 'POST', '/auth/otp/send'],
            'otp verify' => ['magic-starter-otp', 'POST', '/auth/otp/verify'],
            'settings' => ['magic-starter-settings', 'GET', '/settings'],
            'releases' => ['magic-starter-releases', 'GET', '/releases'],
            'email verification, signed out' => ['magic-starter-email-verification', 'POST', '/auth/email/verification-notification'],
            'push test, signed out' => ['magic-starter-push-test', 'POST', '/push/test'],
            'support, signed out' => ['magic-starter-support', 'GET', '/support/conversations'],
            'support public, submission' => ['magic-starter-support-public', 'POST', '/support/public'],
            'newsletter unsubscribe' => ['magic-starter-newsletter-unsubscribe', 'POST', '/newsletter/unsubscribe/abc'],
            'newsletter unsubscribe page' => ['magic-starter-newsletter-unsubscribe-page', 'GET', '/newsletter/unsubscribe/abc'],
        ];
    }

    #[DataProvider('addressKeyedLimiters')]
    public function test_two_ipv6_addresses_in_one_64_share_every_bucket(string $name, string $method, string $path): void
    {
        $this->assertSame(
            $this->keysFor($name, $method, $path, '2001:db8:1:2:aaaa::1'),
            $this->keysFor($name, $method, $path, '2001:db8:1:2:bbbb::9'),
            "{$name} must not hand a fresh bucket to each address of one /64.",
        );
    }

    #[DataProvider('addressKeyedLimiters')]
    public function test_a_different_64_has_its_own_bucket(string $name, string $method, string $path): void
    {
        $this->assertNotSame(
            $this->keysFor($name, $method, $path, '2001:db8:1:2:aaaa::1'),
            $this->keysFor($name, $method, $path, '2001:db8:1:3::1'),
            "{$name} must still tell two networks apart.",
        );
    }

    #[DataProvider('addressKeyedLimiters')]
    public function test_ipv4_is_still_keyed_per_address(string $name, string $method, string $path): void
    {
        $this->assertNotSame(
            $this->keysFor($name, $method, $path, '203.0.113.7'),
            $this->keysFor($name, $method, $path, '203.0.113.8'),
            "{$name} must keep one bucket per IPv4 address.",
        );
    }

    public function test_the_public_thread_read_collapses_ipv6_too(): void
    {
        $a = $this->keysFor('magic-starter-support-public', 'GET', '/support/public/threads/tok', '2001:db8:1:2:aaaa::1', thread: 'tok');
        $b = $this->keysFor('magic-starter-support-public', 'GET', '/support/public/threads/tok', '2001:db8:1:2:bbbb::9', thread: 'tok');
        $c = $this->keysFor('magic-starter-support-public', 'GET', '/support/public/threads/tok', '2001:db8:1:3::1', thread: 'tok');

        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
        $this->assertStringStartsWith('read|', $a[0]);
    }

    public function test_the_ipv4_key_is_the_address_itself(): void
    {
        $this->assertSame(
            ['203.0.113.7|a@example.test'],
            $this->keysFor('magic-starter-auth-login', 'POST', '/auth/login', '203.0.113.7'),
        );
    }

    /**
     * @return list<string>
     */
    private function keysFor(string $name, string $method, string $path, string $address, ?string $thread = null): array
    {
        $limiter = RateLimiter::limiter($name);

        $this->assertNotNull($limiter, "The `{$name}` limiter is not registered.");

        $request = Request::create($path, $method, [
            'email' => 'a@example.test',
            'phone' => '+905550000000',
            'device_id' => 'device-1',
            'two_factor_token' => 'token-1',
        ], [], [], ['REMOTE_ADDR' => $address]);

        if ($thread !== null) {
            $route = new Route($method, '/support/public/threads/{token}', fn () => null);
            $route->bind($request);
            $request->setRouteResolver(fn () => $route);
        }

        return array_map(
            static fn (Limit $limit): string => (string) $limit->key,
            Arr::wrap($limiter($request)),
        );
    }
}
