<?php

namespace FlutterSdk\MagicStarter\Tests\Support\AiRuns;

use FlutterSdk\MagicStarter\Support\AiRuns\SecretMasker;
use FlutterSdk\MagicStarter\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

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
     * One positive vector per default pattern, in config order.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function defaultPatternVectors(): iterable
    {
        $anthropic = 'sk-ant-api03-' . str_repeat('A', 22);
        $github = 'gh' . 'p_' . str_repeat('a1B2', 9);
        $fineGrained = 'github' . '_pat_' . str_repeat('Z9_y', 6);
        $aws = 'AK' . 'IA' . str_repeat('Q7', 8);
        $google = 'AI' . 'za' . str_repeat('x', 34) . '-';
        $slack = 'xo' . 'xb-' . '1234567890-' . str_repeat('k', 12);
        $jwt = 'ey' . 'JhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.' . str_repeat('s', 20);
        $pem = "-----BEGIN RSA PRIVATE KEY-----\n" . str_repeat('M', 64) . "\n-----END RSA PRIVATE KEY-----";

        yield 'openai-style sk key' => ["the key is {$anthropic} here", $anthropic];
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

    public function test_every_configured_default_pattern_is_covered_by_a_vector(): void
    {
        /** @var list<string> $patterns */
        $patterns = config('magic-starter.ai_runs.mask.patterns');
        $vectors = array_column(iterator_to_array(self::defaultPatternVectors()), 0);

        $this->assertCount(11, $patterns);

        foreach ($patterns as $pattern) {
            $matched = array_filter($vectors, static fn (string $vector): bool => preg_match($pattern, $vector) === 1);

            $this->assertNotEmpty($matched, "No vector exercises {$pattern}.");
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

    public function test_extra_patterns_extend_the_defaults(): void
    {
        config(['magic-starter.ai_runs.mask.extra_patterns' => ['/\bACME-(?<secret>[0-9]{6})\b/']]);

        $masked = SecretMasker::fromConfig()->mask('ticket ACME-123456 and sk-ant-api03-AAAAAAAAAAAAAAAAAAAAAA');

        $this->assertSame('ticket ACME-[redacted] and [redacted]', $masked);
    }

    private function masker(): SecretMasker
    {
        return SecretMasker::fromConfig();
    }
}
