<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Resources;

use FlutterSdk\MagicStarter\Http\Resources\SessionResource;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Tests for the enhanced SessionResource, which should include agent and location fields.
 *
 * The `agent` and `location` fields are RED until `SessionAgent` and `SessionLocation`
 * are implemented in `FlutterSdk\MagicStarter\Support\`.
 *
 * Expected failure for agent/location tests:
 * `Class "FlutterSdk\MagicStarter\Support\SessionAgent" not found`
 */
final class SessionResourceTest extends TestCase
{
    /**
     * Build a minimal fake token for the resource.
     */
    private function makeToken(
        string $id = '',
        string $ipAddress = '127.0.0.1',
        string $userAgent = 'TestAgent/1.0',
    ): SessionResourceTestToken {
        $token = new SessionResourceTestToken;
        $token->id = $id ?: Str::uuid()->toString();
        $token->ip_address = $ipAddress;
        $token->user_agent = $userAgent;
        $token->last_used_at = now();
        $token->created_at = now();

        return $token;
    }

    /**
     * Build a minimal fake user whose currentAccessToken() returns the given token.
     */
    private function makeUser(SessionResourceTestToken $currentToken): SessionResourceTestUser
    {
        $user = new SessionResourceTestUser;
        $user->setCurrentToken($currentToken);

        return $user;
    }

    /**
     * Test that the SessionResource includes an `agent` field with the expected sub-keys.
     *
     * RED: Fails until SessionAgent is implemented and SessionResource calls it.
     */
    public function test_session_resource_includes_agent_field(): void
    {
        $token = $this->makeToken(
            userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        );
        $user = $this->makeUser($token);

        $request = Request::create('/sessions');
        $request->setUserResolver(fn () => $user);

        $data = (new SessionResource($token))->toArray($request);

        $this->assertArrayHasKey('agent', $data);
        $this->assertArrayHasKey('browser', $data['agent']);
        $this->assertArrayHasKey('platform', $data['agent']);
        $this->assertArrayHasKey('is_desktop', $data['agent']);
        $this->assertArrayHasKey('is_mobile', $data['agent']);
    }

    /**
     * Test that the SessionResource includes a `location` key (may be null when not configured).
     *
     * RED: Fails until SessionLocation is implemented and SessionResource calls it.
     */
    public function test_session_resource_includes_location_field(): void
    {
        config(['magic-starter.two_factor.geoip_db_path' => null]);

        $token = $this->makeToken();
        $user = $this->makeUser($token);

        $request = Request::create('/sessions');
        $request->setUserResolver(fn () => $user);

        $data = (new SessionResource($token))->toArray($request);

        $this->assertArrayHasKey('location', $data);
        // When geoip_db_path is null, location must be null — no crash.
        $this->assertNull($data['location']);
    }

    /**
     * Test that the SessionResource still includes all existing fields after enhancement.
     *
     * This test verifies no regressions on the original fields:
     * id, ip_address, user_agent, is_current_device, last_used_at.
     */
    public function test_session_resource_preserves_existing_fields(): void
    {
        $token = $this->makeToken(ipAddress: '10.0.0.5', userAgent: 'LegacyAgent/2.0');
        $user = $this->makeUser($token);

        $request = Request::create('/sessions');
        $request->setUserResolver(fn () => $user);

        $data = (new SessionResource($token))->toArray($request);

        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('ip_address', $data);
        $this->assertArrayHasKey('user_agent', $data);
        $this->assertArrayHasKey('is_current_device', $data);
        $this->assertArrayHasKey('last_used_at', $data);

        $this->assertSame($token->id, $data['id']);
        $this->assertSame('10.0.0.5', $data['ip_address']);
        $this->assertSame('LegacyAgent/2.0', $data['user_agent']);
        $this->assertTrue($data['is_current_device']);
    }
}

/**
 * Minimal fake token model for SessionResource tests.
 * Not backed by a database — properties set directly.
 */
final class SessionResourceTestToken extends Model
{
    /** @var bool Disable automatic timestamps to avoid DB writes. */
    public $timestamps = false;

    /** @var string The primary key column. */
    protected $primaryKey = 'id';

    /** @var string The primary key type. */
    protected $keyType = 'string';

    /** @var bool Disable auto-incrementing. */
    public $incrementing = false;

    /** @var array<int, string> Mass-assignable attributes. */
    protected $fillable = [];

    /** @var array<int, string> Cast definitions. */
    protected $casts = [
        'last_used_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}

/**
 * Minimal fake user for SessionResource tests.
 * Provides currentAccessToken() without database setup.
 */
final class SessionResourceTestUser extends Authenticatable
{
    /** @var SessionResourceTestToken|null The active token. */
    private ?SessionResourceTestToken $currentToken = null;

    /**
     * Set the current access token for this fake user.
     */
    public function setCurrentToken(SessionResourceTestToken $token): void
    {
        $this->currentToken = $token;
    }

    /**
     * Return the current fake access token.
     */
    public function currentAccessToken(): ?SessionResourceTestToken
    {
        return $this->currentToken;
    }
}
