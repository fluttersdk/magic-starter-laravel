<?php

namespace FlutterSdk\MagicStarter\Tests\Social;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use FlutterSdk\MagicStarter\Models\SocialAccount;
use FlutterSdk\MagicStarter\Social\AppleClientSecret;
use FlutterSdk\MagicStarter\Social\AppleProviderFactory;
use FlutterSdk\MagicStarter\Tests\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Exceptions;
use stdClass;

class AppleProviderFactoryTest extends TestCase
{
    private const BUNDLE_ID = 'com.example.app';

    private const SERVICES_ID = 'com.example.web';

    private string $privatePem;

    private string $publicPem;

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        openssl_pkey_export($key, $privatePem);

        $this->privatePem = $privatePem;
        $this->publicPem = openssl_pkey_get_details($key)['key'];

        config([
            'magic-starter.social.audiences.apple' => [
                self::BUNDLE_ID,
                self::SERVICES_ID,
            ],
            'magic-starter.social.apple' => [
                'team_id' => 'TEAM123456',
                'key_id' => 'KEY1234567',
                'private_key' => $this->privatePem,
                'bundle_id' => self::BUNDLE_ID,
                'services_id' => self::SERVICES_ID,
            ],
        ]);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The refresh token is an encrypted cast.
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
    }

    public function test_client_secret_for_the_bundle_id_is_an_es256_jwt_about_that_client(): void
    {
        $headers = new stdClass;
        $claims = JWT::decode(
            $this->app->make(AppleClientSecret::class)->for(self::BUNDLE_ID),
            new Key($this->publicPem, 'ES256'),
            $headers,
        );

        $this->assertSame('ES256', $headers->alg);
        $this->assertSame('KEY1234567', $headers->kid);
        $this->assertSame('TEAM123456', $claims->iss);
        $this->assertSame('https://appleid.apple.com', $claims->aud);
        $this->assertSame(self::BUNDLE_ID, $claims->sub);
        $this->assertSame(3600, $claims->exp - $claims->iat);
    }

    public function test_client_secret_for_the_services_id_reads_the_private_key_from_a_path(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'apple-key');
        file_put_contents($path, $this->privatePem);
        config(['magic-starter.social.apple.private_key' => $path]);

        try {
            $claims = JWT::decode(
                $this->app->make(AppleClientSecret::class)->for(self::SERVICES_ID),
                new Key($this->publicPem, 'ES256'),
            );
        } finally {
            unlink($path);
        }

        $this->assertSame(self::SERVICES_ID, $claims->sub);
        $this->assertSame('TEAM123456', $claims->iss);
    }

    public function test_factory_is_a_container_singleton(): void
    {
        $this->assertSame(
            $this->app->make(AppleProviderFactory::class),
            $this->app->make(AppleProviderFactory::class),
        );
    }

    public function test_making_providers_leaves_the_global_apple_client_secret_alone(): void
    {
        config(['services.apple.client_secret' => 'untouched']);
        $history = [];
        $this->app->make(AppleProviderFactory::class)->setHttpClient($this->mockClient([
            new Response(200),
            new Response(200),
        ], $history));

        $this->app->make(AppleProviderFactory::class)->make(self::BUNDLE_ID)->revokeToken('a', 'refresh_token');
        $this->app->make(AppleProviderFactory::class)->make(self::SERVICES_ID)->revokeToken('b', 'refresh_token');

        $this->assertSame('untouched', config('services.apple.client_secret'));
        $this->assertCount(2, $history);
    }

    public function test_revoke_posts_the_stored_refresh_token_as_the_rows_client(): void
    {
        $history = [];
        $factory = $this->app->make(AppleProviderFactory::class);
        $factory->setHttpClient($this->mockClient([new Response(200)], $history));

        $revoked = $factory->revoke($this->account(self::SERVICES_ID, 'stored-refresh-token'));

        $this->assertTrue($revoked);
        $this->assertCount(1, $history);

        $request = $history[0]['request'];
        parse_str((string) $request->getBody(), $form);

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://appleid.apple.com/auth/revoke', (string) $request->getUri());
        $this->assertSame('stored-refresh-token', $form['token']);
        $this->assertSame('refresh_token', $form['token_type_hint']);
        $this->assertSame(self::SERVICES_ID, $form['client_id']);
        $this->assertSame(
            self::SERVICES_ID,
            JWT::decode($form['client_secret'], new Key($this->publicPem, 'ES256'))->sub,
        );
    }

    public function test_revoke_reports_a_failure_without_throwing(): void
    {
        Exceptions::fake();
        $history = [];
        $factory = $this->app->make(AppleProviderFactory::class);
        $factory->setHttpClient($this->mockClient([new Response(500)], $history));

        $revoked = $factory->revoke($this->account(self::BUNDLE_ID, 'stored-refresh-token'));

        $this->assertFalse($revoked);
        Exceptions::assertReportedCount(1);
    }

    public function test_revoke_without_a_stored_refresh_token_sends_nothing(): void
    {
        $history = [];
        $factory = $this->app->make(AppleProviderFactory::class);
        $factory->setHttpClient($this->mockClient([], $history));

        $this->assertFalse($factory->revoke($this->account(self::BUNDLE_ID, null)));
        $this->assertCount(0, $history);
    }

    private function account(string $clientId, ?string $refreshToken): SocialAccount
    {
        return new SocialAccount([
            'provider' => 'apple',
            'provider_user_id' => 'apple-sub-1',
            'client_id' => $clientId,
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * @param  list<Response>  $responses
     * @param  array<int, array<string, mixed>>  $history
     */
    private function mockClient(array $responses, array &$history): Client
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new Client(['handler' => $stack]);
    }
}
