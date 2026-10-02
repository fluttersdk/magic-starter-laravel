<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use DOMDocument;
use DOMXPath;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use FlutterSdk\MagicStarter\Tests\TestCase;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Psr\Http\Message\ResponseInterface;

/**
 * Proves the local OAuth provider harness behaves like the providers it stands in for.
 *
 * Every test talks to a real `php -S` process over a real socket, so a harness that
 * never started fails here with a refused connection rather than passing vacuously.
 */
final class FakeOAuthServerTest extends TestCase
{
    private const REDIRECT_URI = 'https://app.example.test/auth/callback';

    private FakeOAuthServer $server;

    private Client $http;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = FakeOAuthServer::start();
        $this->http = new Client(['http_errors' => false, 'allow_redirects' => false]);
    }

    protected function tearDown(): void
    {
        $this->server->stop();

        parent::tearDown();
    }

    /**
     * Test that the authorize leg redirects to redirect_uri with a code and the echoed state.
     */
    public function test_github_authorize_redirects_with_code_and_echoed_state(): void
    {
        $response = $this->authorize('/login/oauth/authorize', ['state' => 'state-123']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringStartsWith(self::REDIRECT_URI . '?', $response->getHeaderLine('Location'));
        $this->assertSame('state-123', $this->callbackQuery($response)['state']);
        $this->assertNotEmpty($this->callbackQuery($response)['code']);
    }

    /**
     * Test that a wrong code_verifier is refused with 400 and the right one is accepted.
     */
    public function test_token_exchange_checks_the_pkce_verifier_against_the_stored_challenge(): void
    {
        [$verifier, $challenge] = $this->pkcePair();
        $code = $this->callbackQuery($this->authorize('/login/oauth/authorize', ['code_challenge' => $challenge]))['code'];

        $wrong = $this->exchange('/login/oauth/access_token', $code, ['code_verifier' => 'not-the-verifier']);
        $right = $this->exchange('/login/oauth/access_token', $code, ['code_verifier' => $verifier]);

        $this->assertSame(400, $wrong->getStatusCode());
        $this->assertSame(200, $right->getStatusCode());
        $this->assertNotEmpty(json_decode((string) $right->getBody(), true)['access_token']);
    }

    /**
     * Test that a token request with a different redirect_uri is refused.
     */
    public function test_token_exchange_refuses_a_different_redirect_uri(): void
    {
        $code = $this->callbackQuery($this->authorize('/login/oauth/authorize'))['code'];

        $response = $this->exchange('/login/oauth/access_token', $code, ['redirect_uri' => 'https://evil.example.test/cb']);

        $this->assertSame(400, $response->getStatusCode());
    }

    /**
     * Test that a wrong client_secret is refused.
     */
    public function test_token_exchange_refuses_a_wrong_client_secret(): void
    {
        $code = $this->callbackQuery($this->authorize('/login/oauth/authorize'))['code'];

        $response = $this->exchange('/login/oauth/access_token', $code, ['client_secret' => 'wrong']);

        $this->assertSame(400, $response->getStatusCode());
    }

    /**
     * Test that a code redeems once: a replay of an accepted code is refused.
     */
    public function test_an_accepted_code_cannot_be_replayed(): void
    {
        $code = $this->callbackQuery($this->authorize('/login/oauth/authorize'))['code'];

        $first = $this->exchange('/login/oauth/access_token', $code);
        $replay = $this->exchange('/login/oauth/access_token', $code);

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(400, $replay->getStatusCode());
    }

    /**
     * Test that the GitHub user endpoints serve the configured identity.
     */
    public function test_github_user_endpoints_serve_the_configured_identity(): void
    {
        $this->server->setIdentity(['id' => 777, 'login' => 'hubot', 'email' => 'hubot@example.test']);
        $headers = ['Authorization' => 'token fake-access-token'];

        $user = json_decode((string) $this->http->get($this->server->url('/user'), ['headers' => $headers])->getBody(), true);
        $emails = json_decode(
            (string) $this->http->get($this->server->url('/user/emails'), ['headers' => $headers])->getBody(),
            true,
        );

        $this->assertSame(777, $user['id']);
        $this->assertSame('hubot', $user['login']);
        $this->assertSame('hubot@example.test', $emails[0]['email']);
        $this->assertTrue($emails[0]['primary']);
        $this->assertTrue($emails[0]['verified']);
    }

    /**
     * Test that the Microsoft id_token carries oid and tid and verifies against the served JWKS.
     */
    public function test_microsoft_id_token_verifies_against_the_served_jwks(): void
    {
        $this->server->setIdentity(['oid' => 'oid-1', 'tid' => 'tid-1']);
        $code = $this->callbackQuery(
            $this->authorize('/microsoft/common/oauth2/v2.0/authorize', ['nonce' => 'nonce-1']),
        )['code'];

        $token = json_decode(
            (string) $this->exchange('/microsoft/common/oauth2/v2.0/token', $code)->getBody(),
            true,
        );
        $configuration = json_decode(
            (string) $this->http->get($this->server->url('/microsoft/tid-1/v2.0/.well-known/openid-configuration'))->getBody(),
            true,
        );
        $jwks = json_decode((string) $this->http->get($configuration['jwks_uri'])->getBody(), true);
        $claims = JWT::decode($token['id_token'], JWK::parseKeySet($jwks));

        $this->assertSame('oid-1', $claims->oid);
        $this->assertSame('tid-1', $claims->tid);
        $this->assertSame('nonce-1', $claims->nonce);
        $this->assertSame(FakeOAuthServer::CLIENT_ID, $claims->aud);
        $this->assertSame($configuration['issuer'], $claims->iss);
        $this->assertSame($this->server->jwks(), $jwks);
    }

    /**
     * Test that the Apple authorize endpoint answers with a form that posts the echoed nonce.
     */
    public function test_apple_authorize_answers_with_a_form_posting_the_echoed_nonce(): void
    {
        $this->server->setIdentity(['email' => 'apple@example.test']);

        $response = $this->authorize('/apple/auth/authorize', ['state' => 'state-9', 'nonce' => 'nonce-9']);
        $document = new DOMDocument;
        $document->loadHTML((string) $response->getBody());
        $form = (new DOMXPath($document))->query('//form')->item(0);
        $fields = [];

        foreach ((new DOMXPath($document))->query('//form//input') as $input) {
            $fields[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        $claims = JWT::decode($fields['id_token'], JWK::parseKeySet($this->server->jwks()));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(self::REDIRECT_URI, $form->getAttribute('action'));
        $this->assertSame('post', strtolower($form->getAttribute('method')));
        $this->assertSame('state-9', $fields['state']);
        $this->assertNotEmpty($fields['code']);
        $this->assertSame('nonce-9', $claims->nonce);
        $this->assertSame('apple@example.test', json_decode($fields['user'], true)['email']);
    }

    /**
     * Test that the request log records what was sent, including the code_verifier.
     */
    public function test_request_log_records_the_received_code_verifier(): void
    {
        [$verifier, $challenge] = $this->pkcePair();
        $code = $this->callbackQuery($this->authorize('/login/oauth/authorize', ['code_challenge' => $challenge]))['code'];
        $this->exchange('/login/oauth/access_token', $code, ['code_verifier' => $verifier]);

        $tokenRequests = array_values(array_filter(
            $this->server->requests(),
            fn (array $request): bool => $request['path'] === '/login/oauth/access_token',
        ));

        $this->assertCount(1, $tokenRequests);
        $this->assertSame('POST', $tokenRequests[0]['method']);
        $this->assertSame($verifier, $tokenRequests[0]['body']['code_verifier']);
    }

    /**
     * Test that the port stops accepting connections once the server is stopped.
     */
    public function test_the_port_is_closed_after_stop(): void
    {
        $port = (int) parse_url($this->server->baseUrl(), PHP_URL_PORT);

        $this->assertNotFalse(@fsockopen('127.0.0.1', $port, $errno, $error, 1));

        $this->server->stop();

        $this->assertFalse(@fsockopen('127.0.0.1', $port, $errno, $error, 1));
    }

    /**
     * Test that the Socialite helper completes a stateless PKCE flow against the harness.
     */
    public function test_github_provider_helper_completes_a_stateless_pkce_flow(): void
    {
        [$verifier, $challenge] = $this->pkcePair();
        $this->server->setIdentity(['id' => 4242, 'login' => 'octocat', 'email' => 'octocat@example.test']);

        $redirect = $this->server->githubProvider(Request::create('/'), self::REDIRECT_URI)
            ->stateless()
            ->with(['state' => 'state-1', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256'])
            ->redirect();
        $callback = $this->http->get($redirect->getTargetUrl());
        $user = $this->server
            ->githubProvider(Request::create('/', 'GET', $this->callbackQuery($callback)), self::REDIRECT_URI)
            ->stateless()
            ->with(['code_verifier' => $verifier])
            ->user();

        $this->assertSame(302, $callback->getStatusCode());
        $this->assertSame('state-1', $this->callbackQuery($callback)['state']);
        $this->assertSame(4242, $user->getId());
        $this->assertSame('octocat@example.test', $user->getEmail());
    }

    /**
     * Hit an authorize endpoint the way a browser would, without following the redirect.
     */
    private function authorize(string $path, array $query = []): ResponseInterface
    {
        return $this->http->get($this->server->url($path), ['query' => array_merge([
            'client_id' => FakeOAuthServer::CLIENT_ID,
            'redirect_uri' => self::REDIRECT_URI,
            'response_type' => 'code',
        ], $query)]);
    }

    /**
     * Hit a token endpoint the way Socialite does; $overrides replace the valid default fields.
     */
    private function exchange(string $path, string $code, array $overrides = []): ResponseInterface
    {
        return $this->http->post($this->server->url($path), ['form_params' => array_merge([
            'grant_type' => 'authorization_code',
            'client_id' => FakeOAuthServer::CLIENT_ID,
            'client_secret' => FakeOAuthServer::CLIENT_SECRET,
            'code' => $code,
            'redirect_uri' => self::REDIRECT_URI,
        ], $overrides)]);
    }

    /**
     * The query string the harness redirected the browser back with.
     *
     * @return array<string, string>
     */
    private function callbackQuery(ResponseInterface $response): array
    {
        parse_str((string) parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY), $query);

        return $query;
    }

    /**
     * A PKCE verifier and its S256 challenge (RFC 7636).
     *
     * @return array{0: string, 1: string}
     */
    private function pkcePair(): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return [$verifier, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
    }
}
