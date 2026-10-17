<?php

namespace FlutterSdk\MagicStarter\Tests\Support\AiRuns;

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Support\AiRuns\SecretMasker;
use FlutterSdk\MagicStarter\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * Locks what never reaches an `ai_runs` body in clear: every default pattern
 * has a positive vector, the briefing's exact vectors keep their surroundings,
 * and ordinary prose that only resembles a secret is left alone.
 *
 * Token-shaped vectors are assembled at runtime, so the source never carries a
 * literal a secret scanner would flag.
 */
class SecretMaskerTest extends TestCase
{
    /**
     * One positive vector per default pattern, in declaration order.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function defaultPatternVectors(): iterable
    {
        $anthropic = 'sk-ant-api03-' . str_repeat('A', 22);
        $stripe = 'sk' . '_live_' . str_repeat('a1B2', 6);
        $github = 'gh' . 'p_' . str_repeat('a1B2', 9);
        $fineGrained = 'github' . '_pat_' . str_repeat('Z9_y', 6);
        $aws = 'AK' . 'IA' . str_repeat('Q7', 8);
        $google = 'AI' . 'za' . str_repeat('x', 34) . '-';
        $slack = 'xo' . 'xb-' . '1234567890-' . str_repeat('k', 12);
        $jwt = 'ey' . 'JhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.' . str_repeat('s', 20);
        $pem = "-----BEGIN RSA PRIVATE KEY-----\n" . str_repeat('M', 64) . "\n-----END RSA PRIVATE KEY-----";

        yield 'openai-style sk key' => ["the key is {$anthropic} here", $anthropic];
        yield 'stripe secret key' => ["stripe {$stripe} here", $stripe];
        yield 'github classic token' => ["token {$github} used", $github];
        yield 'github fine-grained token' => ["pat {$fineGrained} used", $fineGrained];
        yield 'aws access key id' => ["aws {$aws} id", $aws];
        yield 'google api key ending in a dash' => ["google {$google} key", $google];
        yield 'slack token' => ["slack {$slack} token", $slack];
        yield 'jwt' => ["jwt {$jwt} end", $jwt];
        yield 'bearer header' => ['Authorization: Bearer abc.def.ghi123456', 'abc.def.ghi123456'];
        yield 'url userinfo' => ['https://user:hunter22@example.com/x', 'user:hunter22'];
        yield 'query string credential' => ['https://api.example.com/v1?api_key=AbC123xyz&x=1', 'AbC123xyz'];
        yield 'pem private key' => ["before\n{$pem}\nafter", str_repeat('M', 64)];
    }

    #[DataProvider('defaultPatternVectors')]
    public function test_each_default_pattern_redacts_its_secret(string $text, string $secret): void
    {
        $masked = $this->masker()->mask($text);

        $this->assertIsString($masked);
        $this->assertStringNotContainsString($secret, $masked);
        $this->assertStringContainsString(SecretMasker::PLACEHOLDER, $masked);
    }

    public function test_every_default_pattern_is_covered_by_a_vector(): void
    {
        $vectors = array_column(iterator_to_array(self::defaultPatternVectors()), 0);

        $this->assertCount(12, SecretMasker::DEFAULT_PATTERNS);

        foreach (SecretMasker::DEFAULT_PATTERNS as $pattern) {
            $matched = array_filter($vectors, static fn (string $vector): bool => preg_match($pattern, $vector) === 1);

            $this->assertNotEmpty($matched, "No vector exercises {$pattern}.");
        }
    }

    /**
     * Secrets the first pattern set missed, each with the exact text it must become.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function missedVectors(): iterable
    {
        $liveRestricted = 'rk' . '_live_' . str_repeat('Q9w8', 6);
        $testSecret = 'sk' . '_test_' . str_repeat('Z1y2', 6);
        $pgp = "-----BEGIN PGP PRIVATE KEY BLOCK-----\n\nlQOYBF" . str_repeat('x', 40)
            . "\n-----END PGP PRIVATE KEY BLOCK-----";
        $openSsh = "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjE" . str_repeat('A', 40) . "\nAAAA";

        yield 'url userinfo with an empty user' => ['redis://:p4ssw0rd123@host', 'redis://[redacted]@host'];
        yield 'url password holding an at sign' => [
            'postgres://app:pa@ss@host/db',
            'postgres://[redacted]@host/db',
        ];
        yield 'client_secret query parameter' => [
            'https://idp.example.com/token?client_secret=Zx9yW8vU7t&grant=1',
            'https://idp.example.com/token?client_secret=[redacted]&grant=1',
        ];
        yield 'refresh_token query parameter' => [
            'https://idp.example.com/token?grant=1&refresh_token=Rf1x2y3z4w',
            'https://idp.example.com/token?grant=1&refresh_token=[redacted]',
        ];
        yield 'id_token query parameter' => [
            'https://app.example.com/cb?id_token=Id9x8y7z6w&state=1',
            'https://app.example.com/cb?id_token=[redacted]&state=1',
        ];
        yield 'stripe live restricted key' => ["key {$liveRestricted} end", 'key [redacted] end'];
        yield 'stripe test secret key' => ["key {$testSecret} end", 'key [redacted] end'];
        yield 'pgp private key block' => ["before\n{$pgp}\nafter", "before\n[redacted]\nafter"];
        yield 'private key with no end line' => ["key:\n{$openSsh}", "key:\n[redacted]"];
        yield 'bearer with a colon' => ['Bearer: tok_9f8e7d6c5b4a3928', 'Bearer: [redacted]'];
        yield 'authorization token scheme' => [
            'Authorization: Token abcdef1234567890',
            'Authorization: Token [redacted]',
        ];
        yield 'api key header' => ['X-Api-Key: 9f8e7d6c5b4a39281706', 'X-Api-Key: [redacted]'];
        yield 'json password' => ['{"password": "hunter2hunter2"}', '{"password": "[redacted]"}'];
        yield 'form password' => ['password=hunter2&user=bob', 'password=[redacted]&user=bob'];
    }

    #[DataProvider('missedVectors')]
    public function test_a_previously_missed_secret_is_masked(string $text, string $expected): void
    {
        $this->assertSame($expected, $this->masker()->mask($text));
    }

    /**
     * Every default key name, written as text the way a header, a form or a log line carries it.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function keyValueVectors(): iterable
    {
        yield 'proxy-authorization' => [
            'Proxy-Authorization: Basic dXNlcjpwYXNzd29yZA==',
            'Proxy-Authorization: Basic [redacted]',
        ];
        yield 'x-auth-token' => ['X-Auth-Token: 0a1b2c3d4e', 'X-Auth-Token: [redacted]'];
        yield 'auth_token' => ['auth_token=0a1b2c3d4e;', 'auth_token=[redacted];'];
        yield 'private_key' => ["private_key: 'k3y-v4lue'", "private_key: '[redacted]'"];
        yield 'set-cookie' => ['Set-Cookie: session=abc123; Path=/', 'Set-Cookie: [redacted]; Path=/'];
        yield 'id_token' => ['id_token: eyJ0eXAi', 'id_token: [redacted]'];
        yield 'api-key' => ['api-key=0a1b2c3d4e', 'api-key=[redacted]'];
        yield 'cookie' => ['Cookie: sid=0a1b2c', 'Cookie: [redacted]'];
        yield 'client_secret in yaml' => ['client_secret: s3cr3t-v4lue', 'client_secret: [redacted]'];
    }

    #[DataProvider('keyValueVectors')]
    public function test_a_sensitive_key_written_as_text_has_its_value_masked(string $text, string $expected): void
    {
        $this->assertSame($expected, $this->masker()->mask($text));
    }

    public function test_the_default_keys_carry_the_added_names(): void
    {
        $added = [
            'proxy-authorization',
            'x-auth-token',
            'auth_token',
            'private_key',
            'set-cookie',
            'id_token',
            'api-key',
        ];

        foreach ($added as $key) {
            $this->assertContains($key, SecretMasker::DEFAULT_KEYS);
            $this->assertSame([$key => '[redacted]'], $this->masker()->mask([$key => 'value']));
        }
    }

    public function test_a_bearer_token_keeps_its_header(): void
    {
        $this->assertSame(
            'Authorization: Bearer [redacted]',
            $this->masker()->mask('Authorization: Bearer abc.def.ghi123456'),
        );
    }

    public function test_url_userinfo_is_replaced_and_the_host_kept(): void
    {
        $this->assertSame(
            'https://[redacted]@example.com/x',
            $this->masker()->mask('https://user:hunter22@example.com/x'),
        );
    }

    public function test_a_query_credential_keeps_its_name_and_the_parameters_after_it(): void
    {
        $masked = $this->masker()->mask('https://api.example.com/v1?api_key=AbC123xyz&x=1');

        $this->assertIsString($masked);
        $this->assertStringContainsString('api_key=[redacted]&x=1', $masked);
        $this->assertStringNotContainsString('AbC123xyz', $masked);
    }

    public function test_an_anthropic_key_is_replaced_whole(): void
    {
        $this->assertSame('[redacted]', $this->masker()->mask('sk-ant-api03-AAAAAAAAAAAAAAAAAAAAAA'));
    }

    public function test_a_sensitive_key_in_a_decoded_json_object_is_redacted(): void
    {
        $decoded = json_decode('{"password": "p4ssw0rd!"}', true);

        $this->assertSame(['password' => '[redacted]'], $this->masker()->mask($decoded));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function innocentVectors(): iterable
    {
        yield 'sk inside a word' => ['risk-assessment-for-the-quarterly-report'];
        yield 'url without userinfo or query' => ['https://example.com/status'];
        yield 'basic followed by a short word' => ['Basic plan'];
        yield 'a key name inside a longer word' => ['tokens: 1200, passwords_reset=3'];
        yield 'a url with a port and no userinfo' => ['http://example.com:8080/path?x=1'];
    }

    #[DataProvider('innocentVectors')]
    public function test_text_that_only_resembles_a_secret_is_unchanged(string $text): void
    {
        $this->assertSame($text, $this->masker()->mask($text));
    }

    public function test_key_names_match_case_insensitively_at_any_depth_and_replace_whole_values(): void
    {
        $masked = $this->masker()->mask([
            'headers' => [
                'X-API-Key' => 'abc',
                'Cookie' => ['session' => 'xyz'],
                'Accept' => 'application/json',
            ],
            'Client_Secret' => 12345,
            'note' => 'Bearer abcdefgh12345',
            7 => 'kept',
        ]);

        $this->assertSame([
            'headers' => [
                'X-API-Key' => '[redacted]',
                'Cookie' => '[redacted]',
                'Accept' => 'application/json',
            ],
            'Client_Secret' => '[redacted]',
            'note' => 'Bearer [redacted]',
            7 => 'kept',
        ], $masked);
    }

    public function test_values_that_are_not_strings_or_arrays_pass_through(): void
    {
        $masker = $this->masker();

        $this->assertNull($masker->mask(null));
        $this->assertSame(42, $masker->mask(42));
        $this->assertTrue($masker->mask(true));
    }

    public function test_a_pattern_that_matches_empty_text_still_walks_the_whole_string(): void
    {
        $masked = (new SecretMasker(extraPatterns: ['/q*/']))->mask('ab');

        $this->assertSame('[redacted]a[redacted]b[redacted]', $masked);
    }

    public function test_extra_patterns_extend_the_defaults(): void
    {
        config(['magic-starter.ai_runs.mask.extra_patterns' => ['/\bACME-(?<secret>[0-9]{6})\b/']]);

        $masked = SecretMasker::fromConfig()->mask('ticket ACME-123456 and sk-ant-api03-AAAAAAAAAAAAAAAAAAAAAA');

        $this->assertSame('ticket ACME-[redacted] and [redacted]', $masked);
    }

    public function test_extra_keys_extend_the_defaults_in_arrays_and_in_text(): void
    {
        config(['magic-starter.ai_runs.mask.extra_keys' => ['X-Tenant-Secret']]);
        $masker = SecretMasker::fromConfig();

        $this->assertSame(
            ['x-tenant-secret' => '[redacted]', 'password' => '[redacted]'],
            $masker->mask(['x-tenant-secret' => 'abc', 'password' => 'p4ss']),
        );
        $this->assertSame('X-Tenant-Secret: [redacted]', $masker->mask('X-Tenant-Secret: t3n4nt'));
    }

    public function test_config_cannot_shorten_the_defaults(): void
    {
        config(['magic-starter.ai_runs' => [
            'mask' => [
                'patterns' => [],
                'keys' => [],
            ],
        ]]);

        $masker = SecretMasker::fromConfig();

        $this->assertSame('[redacted]', $masker->mask('sk-ant-api03-AAAAAAAAAAAAAAAAAAAAAA'));
        $this->assertSame(['password' => '[redacted]'], $masker->mask(['password' => 'p4ss']));
    }

    public function test_a_string_the_regex_engine_gives_up_on_is_replaced_whole(): void
    {
        $limit = (string) ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1');

        try {
            $masked = $this->masker()->mask('note password=hunter2 and nothing else');
        } finally {
            ini_set('pcre.backtrack_limit', $limit);
        }

        $this->assertSame(SecretMasker::PLACEHOLDER, $masked);
    }

    public function test_booting_with_an_invalid_extra_pattern_is_refused_naming_it(): void
    {
        config([
            'magic-starter.features' => [Features::aiRuns()],
            'magic-starter.ai_runs.mask.extra_patterns' => [
                '/\bACME-[0-9]{6}\b/',
                '/acme-(unclosed/',
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('[magic-starter.ai_runs.mask.extra_patterns] holds [/acme-(unclosed/]');

        (new MagicStarterServiceProvider($this->app))->boot();
    }

    public function test_an_invalid_extra_pattern_is_not_checked_while_the_feature_is_off(): void
    {
        config([
            'magic-starter.features' => [],
            'magic-starter.ai_runs.mask.extra_patterns' => ['/acme-(unclosed/'],
        ]);

        (new MagicStarterServiceProvider($this->app))->boot();

        $this->addToAssertionCount(1);
    }

    private function masker(): SecretMasker
    {
        return SecretMasker::fromConfig();
    }
}
