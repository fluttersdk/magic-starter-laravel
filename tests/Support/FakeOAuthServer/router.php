<?php

/**
 * Router script for the `php -S` process that {@see \FlutterSdk\MagicStarter\Tests\Support\FakeOAuthServer} starts.
 *
 * Test support only. Each request is a fresh PHP run, so everything that must survive between requests lives as
 * files in the directory named by `FAKE_OAUTH_STATE_DIR` (written by FakeOAuthServer before launch):
 *
 *     config.json    client_id and client_secret the token endpoints demand
 *     identity.json  the identity every shape serves
 *     private.pem    the id_token signing key;  jwks.json  its public half
 *     codes/<code>   what the authorize leg saw, for the token leg to check
 *     requests.log   one JSON line per request received
 */

use Firebase\JWT\JWT;

require __DIR__ . '/../../../vendor/autoload.php';

/**
 * Read a JSON file from the state directory.
 */
function oauth_state(string $file): array
{
    return json_decode((string) file_get_contents(getenv('FAKE_OAUTH_STATE_DIR') . '/' . $file), true);
}

/**
 * Send a JSON body and end the request.
 */
function oauth_json(mixed $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);

    exit;
}

/**
 * Refuse the request the way an OAuth server does: a status and an `error` code.
 */
function oauth_refuse(string $error, int $status = 400): never
{
    oauth_json(['error' => $error], $status);
}

/**
 * Append the request to the log the test reads back, headers lower-cased.
 */
function oauth_log_request(string $method, string $path): void
{
    $entry = [
        'method' => $method,
        'path' => $path,
        'query' => $_GET,
        'body' => $_POST,
        'headers' => array_change_key_case(getallheaders(), CASE_LOWER),
    ];

    file_put_contents(getenv('FAKE_OAUTH_STATE_DIR') . '/requests.log', json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * The authorize leg: validate the client, remember what the token leg must match and mint a code.
 *
 * @return array{code: string, record: array<string, mixed>}
 */
function oauth_issue_code(): array
{
    $record = [
        'client_id' => $_GET['client_id'] ?? null,
        'redirect_uri' => $_GET['redirect_uri'] ?? null,
        'code_challenge' => $_GET['code_challenge'] ?? null,
        'nonce' => $_GET['nonce'] ?? null,
        'state' => $_GET['state'] ?? null,
    ];

    if ($record['client_id'] !== oauth_state('config.json')['client_id'] || ! $record['redirect_uri']) {
        oauth_refuse('invalid_request');
    }

    $code = bin2hex(random_bytes(12));
    file_put_contents(getenv('FAKE_OAUTH_STATE_DIR') . '/codes/' . $code . '.json', json_encode($record));

    return ['code' => $code, 'record' => $record];
}

/**
 * Authorize for GitHub and Microsoft: a 302 back to redirect_uri with the code and the echoed state.
 */
function oauth_authorize_redirect(): never
{
    ['code' => $code, 'record' => $record] = oauth_issue_code();
    $query = http_build_query(array_filter(['code' => $code, 'state' => $record['state']], fn ($v) => $v !== null));
    $separator = str_contains($record['redirect_uri'], '?') ? '&' : '?';

    http_response_code(302);
    header('Location: ' . $record['redirect_uri'] . $separator . $query);

    exit;
}

/**
 * Authorize for Apple: an auto-submitting form_post carrying code, state, id_token and user.
 */
function oauth_authorize_form_post(string $origin): never
{
    ['code' => $code, 'record' => $record] = oauth_issue_code();
    $identity = oauth_state('identity.json');
    $fields = [
        'code' => $code,
        'state' => $record['state'],
        'id_token' => oauth_apple_id_token($origin, $record),
        'user' => json_encode([
            'name' => ['firstName' => $identity['name'], 'lastName' => ''],
            'email' => $identity['email'],
        ]),
    ];
    $inputs = '';

    foreach (array_filter($fields, fn ($value) => $value !== null) as $name => $value) {
        $inputs .= sprintf('<input type="hidden" name="%s" value="%s">', htmlspecialchars($name), htmlspecialchars($value));
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><body onload="document.forms[0].submit()">'
        . sprintf('<form method="post" action="%s">%s</form></body></html>', htmlspecialchars($record['redirect_uri']), $inputs);

    exit;
}

/**
 * Sign an RS256 id_token with the harness key; `$claims` are merged over the standard ones.
 */
function oauth_sign_id_token(array $claims, array $record): string
{
    $now = time();
    $claims = array_filter([
        'aud' => $record['client_id'],
        'iat' => $now,
        'nbf' => $now,
        'exp' => $now + 3600,
        'nonce' => $record['nonce'],
    ] + $claims, fn ($value) => $value !== null);

    return JWT::encode(
        $claims,
        (string) file_get_contents(getenv('FAKE_OAUTH_STATE_DIR') . '/private.pem'),
        'RS256',
        oauth_state('jwks.json')['keys'][0]['kid'],
    );
}

/**
 * The Microsoft id_token: the stable identity is `oid` within `tid`, the issuer carries the tenant.
 */
function oauth_microsoft_id_token(string $origin, array $record): string
{
    $identity = oauth_state('identity.json');

    return oauth_sign_id_token([
        'iss' => $origin . '/microsoft/' . $identity['tid'] . '/v2.0',
        'sub' => 'pairwise-' . $identity['oid'],
        'oid' => $identity['oid'],
        'tid' => $identity['tid'],
        'name' => $identity['name'],
        'preferred_username' => $identity['email'],
    ], $record);
}

/**
 * The Apple id_token: `sub` is the stable identity, the nonce is echoed from the authorize leg.
 */
function oauth_apple_id_token(string $origin, array $record): string
{
    $identity = oauth_state('identity.json');

    return oauth_sign_id_token([
        'iss' => $origin . '/apple',
        'sub' => $identity['sub'],
        'email' => $identity['email'],
        'email_verified' => $identity['email_verified'],
    ], $record);
}

/**
 * The token leg: check client, redirect_uri and PKCE against what authorize saw, then redeem the code once.
 *
 * `$idToken` builds the id_token from the saved record; GitHub passes null because it returns none.
 */
function oauth_exchange_code(?callable $idToken): never
{
    $file = getenv('FAKE_OAUTH_STATE_DIR') . '/codes/' . preg_replace('/[^a-f0-9]/', '', $_POST['code'] ?? '') . '.json';
    $config = oauth_state('config.json');

    if (! is_file($file)) {
        oauth_refuse('invalid_grant');
    }

    $record = json_decode((string) file_get_contents($file), true);
    $verifier = $_POST['code_verifier'] ?? '';
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    if (($_POST['client_id'] ?? null) !== $record['client_id'] || ($_POST['client_secret'] ?? null) !== $config['client_secret']) {
        oauth_refuse('invalid_client');
    }

    if (($_POST['redirect_uri'] ?? null) !== $record['redirect_uri']) {
        oauth_refuse('redirect_uri_mismatch');
    }

    if ($record['code_challenge'] !== null && ! hash_equals($record['code_challenge'], $challenge)) {
        oauth_refuse('invalid_grant');
    }

    unlink($file);

    $response = ['access_token' => 'fake-access-' . bin2hex(random_bytes(8)), 'token_type' => 'bearer', 'scope' => 'user:email'];

    if ($idToken !== null) {
        $response['id_token'] = $idToken($record);
    }

    oauth_json($response);
}

/**
 * Require a bearer-ish Authorization header, as GitHub's API does.
 */
function oauth_require_token(): void
{
    if (! isset(getallheaders()['Authorization'])) {
        oauth_refuse('requires_authentication', 401);
    }
}

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$origin = 'http://' . $_SERVER['HTTP_HOST'];
oauth_log_request($method, $path);

// GitHub shape.
if ($method === 'GET' && $path === '/login/oauth/authorize') {
    oauth_authorize_redirect();
}

if ($method === 'POST' && $path === '/login/oauth/access_token') {
    oauth_exchange_code(null);
}

if ($method === 'GET' && $path === '/user') {
    oauth_require_token();
    $identity = oauth_state('identity.json');

    oauth_json([
        'id' => $identity['id'],
        'node_id' => $identity['node_id'],
        'login' => $identity['login'],
        'name' => $identity['name'],
        'avatar_url' => $identity['avatar_url'],
    ]);
}

if ($method === 'GET' && $path === '/user/emails') {
    oauth_require_token();
    $identity = oauth_state('identity.json');

    oauth_json([['email' => $identity['email'], 'primary' => true, 'verified' => $identity['email_verified']]]);
}

// Microsoft shape: the tenant segment is part of every path, the issuer carries the identity's tid.
if ($method === 'GET' && preg_match('#^/microsoft/[^/]+/oauth2/v2\.0/authorize$#', $path)) {
    oauth_authorize_redirect();
}

if ($method === 'POST' && preg_match('#^/microsoft/[^/]+/oauth2/v2\.0/token$#', $path)) {
    oauth_exchange_code(fn (array $record) => oauth_microsoft_id_token($origin, $record));
}

if ($method === 'GET' && preg_match('#^/microsoft/([^/]+)/v2\.0/\.well-known/openid-configuration$#', $path, $tenant)) {
    oauth_json([
        'issuer' => $origin . '/microsoft/' . $tenant[1] . '/v2.0',
        'authorization_endpoint' => $origin . '/microsoft/' . $tenant[1] . '/oauth2/v2.0/authorize',
        'token_endpoint' => $origin . '/microsoft/' . $tenant[1] . '/oauth2/v2.0/token',
        'jwks_uri' => $origin . '/microsoft/discovery/v2.0/keys',
        'id_token_signing_alg_values_supported' => ['RS256'],
    ]);
}

// Apple shape: no PKCE, the id_token is also handed to the browser by the form_post.
if ($method === 'GET' && $path === '/apple/auth/authorize') {
    oauth_authorize_form_post($origin);
}

if ($method === 'POST' && $path === '/apple/auth/token') {
    oauth_exchange_code(fn (array $record) => oauth_apple_id_token($origin, $record));
}

// One signing key serves both shapes.
if ($method === 'GET' && in_array($path, ['/microsoft/discovery/v2.0/keys', '/apple/auth/keys'], true)) {
    oauth_json(oauth_state('jwks.json'));
}

oauth_refuse('not_found', 404);
