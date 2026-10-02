<?php

namespace FlutterSdk\MagicStarter\Tests\Models;

use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\SocialAccount;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Locks what `social_accounts` exists to guarantee: a provider identity belongs
 * to exactly one user, a user holds one identity per provider, and the one
 * secret the table keeps (the Apple refresh token) is never stored in clear.
 *
 * Every constraint is asserted against the LIVE schema built by the shipped
 * migration, because the schema is what the constraint depends on.
 */
class SocialAccountTest extends TestCase
{
    /**
     * The refresh token is encrypted, and Testbench ships no application key.
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'auth.providers.users.model' => ConcreteUser::class,
            'magic-starter.models.user' => ConcreteUser::class,
        ]);

        Schema::enableForeignKeyConstraints();

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('email')->unique()->nullable();
            $table->string('password')->nullable();
            $table->timestamps();
        });

        $this->runMigration('create_social_accounts_table.php');
    }

    public function test_the_same_provider_identity_cannot_belong_to_two_users(): void
    {
        $this->link($this->makeUser('a@example.com'), 'google', 'g-1');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->link($this->makeUser('b@example.com'), 'google', 'g-1');
    }

    public function test_a_user_cannot_link_two_identities_of_one_provider(): void
    {
        $user = $this->makeUser('a@example.com');
        $this->link($user, 'google', 'g-1');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->link($user, 'google', 'g-2');
    }

    public function test_a_user_may_link_one_identity_per_provider(): void
    {
        $user = $this->makeUser('a@example.com');

        $this->link($user, 'google', 'g-1');
        $this->link($user, 'github', 'gh-1');

        $this->assertSame(2, $user->socialAccounts()->count());
    }

    public function test_the_refresh_token_is_encrypted_at_rest(): void
    {
        $account = $this->link($this->makeUser('a@example.com'), 'apple', 'a-1', ['refresh_token' => 'r-plain']);

        $raw = DB::table('social_accounts')->where('id', $account->getKey())->value('refresh_token');

        $this->assertNotNull($raw);
        $this->assertNotSame('r-plain', $raw);
        $this->assertStringNotContainsString('r-plain', (string) $raw);
        $this->assertSame('r-plain', $account->fresh()?->refresh_token);
    }

    public function test_the_refresh_token_is_hidden_from_serialisation(): void
    {
        $account = $this->link($this->makeUser('a@example.com'), 'apple', 'a-1', ['refresh_token' => 'r-plain']);

        $this->assertArrayNotHasKey('refresh_token', $account->toArray());
    }

    public function test_deleting_the_user_cascades_its_social_accounts(): void
    {
        $user = $this->makeUser('a@example.com');
        $this->link($user, 'google', 'g-1');
        $this->link($user, 'github', 'gh-1');

        $user->delete();

        $this->assertSame(0, SocialAccount::query()->count());
    }

    public function test_the_model_resolves_through_magic_starter(): void
    {
        $this->assertSame(SocialAccount::class, MagicStarter::socialAccountModel());
    }

    public function test_has_password_is_false_for_a_null_password(): void
    {
        $this->assertFalse($this->makeUser('a@example.com', null)->hasPassword());
    }

    public function test_has_password_is_false_for_an_empty_password(): void
    {
        $this->assertFalse($this->makeUser('a@example.com', '')->hasPassword());
    }

    public function test_has_password_is_true_for_a_stored_password(): void
    {
        $this->assertTrue($this->makeUser('a@example.com', 'hashed-secret')->hasPassword());
    }

    public function test_the_deletion_columns_migration_adds_both_columns_and_is_idempotent(): void
    {
        $this->assertFalse(Schema::hasColumn('users', 'deletion_scheduled_at'));

        $this->runMigration('add_deletion_columns_to_users_table.php');
        $this->runMigration('add_deletion_columns_to_users_table.php');

        $this->assertTrue(Schema::hasColumn('users', 'deletion_scheduled_at'));
        $this->assertTrue(Schema::hasColumn('users', 'orphaned_at'));
    }

    public function test_the_create_migration_is_a_no_op_on_a_second_run(): void
    {
        $this->runMigration('create_social_accounts_table.php');

        $this->assertTrue(Schema::hasTable('social_accounts'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function link(ConcreteUser $user, string $provider, string $providerUserId, array $attributes = []): SocialAccount
    {
        return SocialAccount::query()->create([
            'user_id' => $user->getKey(),
            'provider' => $provider,
            'provider_user_id' => $providerUserId,
            ...$attributes,
        ]);
    }

    private function makeUser(string $email, ?string $password = null): ConcreteUser
    {
        return ConcreteUser::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Test User',
            'email' => $email,
            'password' => $password,
        ]);
    }

    private function runMigration(string $file): void
    {
        $migration = require __DIR__ . '/../../database/migrations/' . $file;
        $migration->up();
    }
}
