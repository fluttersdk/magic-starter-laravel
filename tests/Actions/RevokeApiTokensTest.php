<?php

namespace FlutterSdk\MagicStarter\Tests\Actions;

use FlutterSdk\MagicStarter\Contracts\RevokesApiTokens;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;

/**
 * Revoking one token signs out one device; revoking without an id signs the
 * account out everywhere. Another account's tokens are never touched.
 */
final class RevokeApiTokensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuidMorphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });
    }

    public function test_one_token_is_revoked_by_its_id(): void
    {
        $user = RevokeApiTokensTestUser::query()->create(['name' => 'User']);
        $phone = $user->createToken('phone')->accessToken;
        $laptop = $user->createToken('laptop')->accessToken;

        app(RevokesApiTokens::class)->revoke($user, (string) $phone->getKey());

        $this->assertSame(
            [
                (string) $laptop->getKey(),
            ],
            $user->tokens()->pluck('id')->map(fn (mixed $id): string => (string) $id)->all(),
        );
    }

    public function test_every_token_is_revoked_without_an_id(): void
    {
        $user = RevokeApiTokensTestUser::query()->create(['name' => 'User']);
        $other = RevokeApiTokensTestUser::query()->create(['name' => 'Other']);
        $user->createToken('phone');
        $user->createToken('laptop');
        $other->createToken('phone');

        app(RevokesApiTokens::class)->revoke($user);

        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(1, $other->tokens()->count());
    }

    public function test_another_accounts_token_is_not_revoked_by_id(): void
    {
        $user = RevokeApiTokensTestUser::query()->create(['name' => 'User']);
        $other = RevokeApiTokensTestUser::query()->create(['name' => 'Other']);
        $foreign = $other->createToken('phone')->accessToken;

        app(RevokesApiTokens::class)->revoke($user, (string) $foreign->getKey());

        $this->assertSame(1, $other->tokens()->count());
    }
}

/**
 * A user that can hold Sanctum tokens, which `ConcreteUser` cannot.
 */
class RevokeApiTokensTestUser extends ConcreteUser
{
    use HasApiTokens;
}
