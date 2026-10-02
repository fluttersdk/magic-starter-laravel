<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Illuminate\Http\Request;
use Laravel\Socialite\Two\GithubProvider;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * A local OAuth provider on a real socket, so a flow test measures real HTTP.
 *
 * It launches `php -S 127.0.0.1:<free port> FakeOAuthServer/router.php` and serves three provider shapes:
 *
 *  - GitHub: `/login/oauth/authorize`, `/login/oauth/access_token`, `/user`, `/user/emails`.
 *  - Microsoft: `/microsoft/{tenant}/oauth2/v2.0/{authorize,token}`, whose token response carries an RS256
 *    `id_token` (`oid`, `tid`), plus the OpenID configuration and JWKS that verify it.
 *  - Apple: `/apple/auth/{authorize,token,keys}`, whose authorize endpoint answers with an auto-submitting
 *    form_post carrying `code`, `state`, `id_token` and `user`.
 *
 * The token endpoints check client_id, client_secret, redirect_uri equality and, when the authorize leg carried
 * a `code_challenge`, S256(code_verifier). A refused exchange does not consume the code; an accepted one does.
 *
 * The router is a separate process, so everything it shares with the test (codes, identity, the signing key and
 * the request log) lives in a temp directory handed over through `FAKE_OAUTH_STATE_DIR`. Every request is
 * appended to the log, which lets a test assert what was SENT, not only what came back.
 *
 * Always pair {@see self::start()} with {@see self::stop()} in tearDown.
 */
final class FakeOAuthServer
{
    /**
     * The client_id the harness accepts. Untyped: typed constants are PHP 8.3 and this package floors at 8.2.
     */
    public const CLIENT_ID = 'fake-client-id';

    public const CLIENT_SECRET = 'fake-client-secret';

    /**
     * The `kid` of the id_token signing key, as published in the JWKS.
     */
    public const KEY_ID = 'fake-oauth-key';

    /**
     * The identity every provider shape serves until a test calls {@see self::setIdentity()}.
     *
     * @var array<string, mixed>
     */
    private const DEFAULT_IDENTITY = [
        'id' => 4242,
        'node_id' => 'MDQ6VXNlcjQyNDI=',
        'login' => 'octocat',
        'name' => 'The Octocat',
        'email' => 'octocat@example.test',
        'email_verified' => true,
        'avatar_url' => 'https://avatars.example.test/u/4242',
        'oid' => '00000000-0000-0000-0000-000000004242',
        'tid' => '11111111-1111-1111-1111-111111111111',
        'sub' => '000042.apple.sub',
    ];

    private function __construct(
        private readonly string $stateDir,
        private readonly int $port,
        private ?Process $process,
    ) {}

    /**
     * Start the server and block until it accepts connections.
     *
     * @throws RuntimeException When the process dies or does not answer within five seconds.
     */
    public static function start(): self
    {
        // 1. Everything the router process needs is written before it exists, so it never races the test.
        $stateDir = sys_get_temp_dir() . '/fake-oauth-' . bin2hex(random_bytes(6));
        mkdir($stateDir . '/codes', 0777, true);
        self::writeSigningKey($stateDir);
        file_put_contents($stateDir . '/identity.json', json_encode(self::DEFAULT_IDENTITY));
        file_put_contents($stateDir . '/config.json', json_encode([
            'client_id' => self::CLIENT_ID,
            'client_secret' => self::CLIENT_SECRET,
        ]));

        // 2. Launch the router, then wait for the socket.
        $port = self::freePort();
        $process = new Process(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/FakeOAuthServer/router.php'],
            __DIR__ . '/FakeOAuthServer',
            ['FAKE_OAUTH_STATE_DIR' => $stateDir],
        );
        $process->start();

        $server = new self($stateDir, $port, $process);
        $server->waitUntilListening();

        return $server;
    }

    /**
     * Stop the process and delete its state. Safe to call twice.
     */
    public function stop(): void
    {
        if ($this->process === null) {
            return;
        }

        $this->process->stop(2);
        $this->process = null;

        foreach (glob($this->stateDir . '/{,codes/}*', GLOB_BRACE | GLOB_NOSORT) ?: [] as $file) {
            is_file($file) && unlink($file);
        }
        @rmdir($this->stateDir . '/codes');
        @rmdir($this->stateDir);
    }

    public function __destruct()
    {
        $this->stop();
    }

    /**
     * The origin providers are pointed at, without a trailing slash.
     */
    public function baseUrl(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    /**
     * An absolute harness URL for the given path.
     */
    public function url(string $path): string
    {
        return $this->baseUrl() . $path;
    }

    /**
     * Change the identity every endpoint serves; keys not given keep their current value.
     *
     * Keys: id, node_id, login, name, email, email_verified, avatar_url (GitHub), oid, tid (Microsoft id_token),
     * sub (Apple id_token).
     *
     * @param  array<string, mixed>  $identity
     */
    public function setIdentity(array $identity): void
    {
        $current = json_decode((string) file_get_contents($this->stateDir . '/identity.json'), true);

        file_put_contents($this->stateDir . '/identity.json', json_encode(array_merge($current, $identity)));
    }

    /**
     * Every request the server has received, oldest first.
     *
     * @return list<array{method: string, path: string, query: array<string, mixed>, body: array<string, mixed>, headers: array<string, string>}>
     */
    public function requests(): array
    {
        $log = $this->stateDir . '/requests.log';

        if (! is_file($log)) {
            return [];
        }

        return array_map(
            fn (string $line): array => json_decode($line, true),
            file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
        );
    }

    /**
     * The private key (PEM) the id_tokens are signed with.
     */
    public function signingKeyPem(): string
    {
        return (string) file_get_contents($this->stateDir . '/private.pem');
    }

    /**
     * The matching public key (PEM), for a verifier configured with a key instead of a JWKS.
     */
    public function publicKeyPem(): string
    {
        return openssl_pkey_get_details(openssl_pkey_get_private($this->signingKeyPem()))['key'];
    }

    /**
     * The JWKS the server publishes at `/microsoft/discovery/v2.0/keys` and `/apple/auth/keys`.
     *
     * @return array{keys: list<array<string, string>>}
     */
    public function jwks(): array
    {
        return json_decode((string) file_get_contents($this->stateDir . '/jwks.json'), true);
    }

    /**
     * A Socialite GitHub provider whose browser and server calls land on this harness.
     *
     * The authorize URL is not an HTTP request, so it is overridden; token, user and email calls are real Guzzle
     * requests, so a handler middleware moves them from the GitHub hosts to the harness. That keeps Socialite's own
     * response handling under test and means nothing can reach the real hosts.
     *
     * Stateless, so the state and PKCE legs are driven through `with()` by the test.
     */
    public function githubProvider(Request $request, string $redirectUrl): GithubProvider
    {
        $harness = parse_url($this->baseUrl());
        $handler = HandlerStack::create();
        $handler->push(Middleware::mapRequest(fn (RequestInterface $outgoing): RequestInterface => $outgoing->withUri(
            $outgoing->getUri()->withScheme('http')->withHost($harness['host'])->withPort($harness['port']),
        )));
        $authorizeUrl = $this->url('/login/oauth/authorize');

        return new class($request, self::CLIENT_ID, self::CLIENT_SECRET, $redirectUrl, ['handler' => $handler], $authorizeUrl) extends GithubProvider
        {
            public function __construct($request, $clientId, $clientSecret, $redirectUrl, $guzzle, private string $authorizeUrl)
            {
                parent::__construct($request, $clientId, $clientSecret, $redirectUrl, $guzzle);
            }

            protected function getAuthUrl($state)
            {
                return $this->buildAuthUrlFromBase($this->authorizeUrl, $state);
            }
        };
    }

    /**
     * Generate the id_token key pair and publish its public half as a JWKS.
     */
    private static function writeSigningKey(string $stateDir): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        openssl_pkey_export($key, $pem);
        $rsa = openssl_pkey_get_details($key)['rsa'];
        $base64Url = fn (string $binary): string => rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');

        file_put_contents($stateDir . '/private.pem', $pem);
        file_put_contents($stateDir . '/jwks.json', json_encode(['keys' => [[
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => self::KEY_ID,
            'n' => $base64Url($rsa['n']),
            'e' => $base64Url($rsa['e']),
        ]]]));
    }

    /**
     * Ask the OS for a port nobody holds right now.
     */
    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

        if ($socket === false) {
            throw new RuntimeException('Cannot reserve a local port: ' . $error);
        }

        $port = (int) parse_url('tcp://' . stream_socket_get_name($socket, false), PHP_URL_PORT);
        fclose($socket);

        return $port;
    }

    /**
     * Poll the port until it accepts a connection, failing fast if the process has died.
     */
    private function waitUntilListening(): void
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $connection = @fsockopen('127.0.0.1', $this->port, $errno, $error, 0.2);

            if ($connection !== false) {
                fclose($connection);

                return;
            }

            if (! $this->process->isRunning()) {
                break;
            }

            usleep(100_000);
        }

        $output = $this->process->getErrorOutput();
        $this->stop();

        throw new RuntimeException('FakeOAuthServer did not start: ' . $output);
    }
}
