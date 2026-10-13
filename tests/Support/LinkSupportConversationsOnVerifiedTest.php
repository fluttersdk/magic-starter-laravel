<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use FlutterSdk\MagicStarter\Audit\Audit;
use FlutterSdk\MagicStarter\Contracts\CreatesUsersFromProvider;
use FlutterSdk\MagicStarter\Contracts\OpensSupportConversations;
use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Social\VerifiedIdentity;
use FlutterSdk\MagicStarter\Support\SupportThreadToken;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\SanctumServiceProvider;

/**
 * Who a public conversation is handed to once its address gets an account.
 *
 * Holding an account with the address is not proof of owning the mailbox: a
 * stranger can register with somebody else's address. So `Registered` links
 * nothing, and only `Verified` (a clicked verification link, or a provider
 * that vouched for the address at a social sign-up) moves a conversation onto
 * the account. The public link stops working at that moment.
 */
class LinkSupportConversationsOnVerifiedTest extends TestCase
{
    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            SanctumServiceProvider::class,
            MagicStarterServiceProvider::class,
        ];
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The social account's refresh token is an encrypted cast.
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('magic-starter.features', [
            Features::support(),
            Features::audit(),
        ]);
        $app['config']->set('auth.providers.users.model', ConcreteUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique()->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->string('current_team_id')->nullable();
            $table->timestamps();
        });

        foreach ([
            'create_support_conversations_table.php',
            'create_support_messages_table.php',
            'add_ai_columns_to_support_tables.php',
            'create_magic_starter_audits_table.php',
            'create_social_accounts_table.php',
        ] as $file) {
            $migration = require __DIR__ . '/../../database/migrations/' . $file;
            $migration->up();
        }
    }

    protected function tearDown(): void
    {
        MagicStarter::reset();

        parent::tearDown();
    }

    public function test_a_registered_address_sees_nothing_until_verified(): void
    {
        $conversation = $this->publicConversation('ada@example.com');
        $user = $this->user('ada@example.com');

        event(new Registered($user));

        $this->actingAs($user, 'sanctum');
        $this->getJson('/support/conversations')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/support/conversations/{$conversation->getKey()}")->assertNotFound();
        $this->assertNull($conversation->refresh()->user_id);

        event(new Verified($user));

        $this->getJson('/support/conversations')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/support/conversations/{$conversation->getKey()}")->assertOk();
    }

    public function test_verified_links_only_unowned_rows_of_that_address_and_revokes_their_links(): void
    {
        $other = $this->user('other@example.com');
        $public = $this->publicConversation('Ada@Example.com');
        $token = SupportThreadToken::mint($public);
        $owned = SupportConversation::create([
            'user_id' => $other->getKey(),
            'email' => 'ada@example.com',
            'subject' => 'Owned elsewhere',
            'source' => SupportConversationSource::IN_APP,
        ]);
        $unrelated = $this->publicConversation('grace@example.com');
        $user = $this->user('ada@example.com');
        $user->forceFill([
            'current_team_id' => '6f1d1c55-4a35-4f0c-9d1f-4a3e5f7b2c10',
        ])->save();

        event(new Verified($user));

        $public->refresh();
        $this->assertSame((string) $user->getKey(), (string) $public->user_id);
        $this->assertNull($public->team_id);
        $this->assertNull($public->thread_token_hash);
        $this->assertNull(SupportThreadToken::resolve($token));
        $this->assertSame((string) $other->getKey(), (string) $owned->refresh()->user_id);
        $this->assertNull($unrelated->refresh()->user_id);
        $this->assertTrue(
            Audit::query()
                ->where('event', 'support.conversation_linked')
                ->where('auditable_id', (string) $public->getKey())
                ->exists(),
        );
        $this->assertSame(1, Audit::query()->where('event', 'support.conversation_linked')->count());
    }

    public function test_the_lookup_matches_the_stored_address_without_a_function_so_its_index_serves_it(): void
    {
        $conversation = $this->publicConversation('Ada@Example.com');
        $user = $this->user('ada@example.com');

        DB::enableQueryLog();
        event(new Verified($user));

        $lookups = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql): bool => str_contains($sql, 'support_conversations') && str_contains($sql, 'email'));

        $this->assertCount(1, $lookups);
        $this->assertStringNotContainsString('lower(', strtolower((string) $lookups->first()));
        $this->assertSame((string) $user->getKey(), (string) $conversation->refresh()->user_id);
    }

    public function test_a_provider_verified_social_sign_up_links(): void
    {
        $conversation = $this->publicConversation('ada@example.com');

        $user = app(CreatesUsersFromProvider::class)->create(
            new VerifiedIdentity('google', 'g-1', 'Ada@Example.com', emailVerified: true),
            Request::create('/'),
        );

        $this->assertSame((string) $user->getAuthIdentifier(), (string) $conversation->refresh()->user_id);
    }

    public function test_an_unverified_social_sign_up_links_nothing(): void
    {
        $conversation = $this->publicConversation('ada@example.com');

        app(CreatesUsersFromProvider::class)->create(
            new VerifiedIdentity('github', 'gh-1', 'ada@example.com', emailVerified: false),
            Request::create('/'),
        );

        $this->assertNull($conversation->refresh()->user_id);
    }

    /**
     * Opened through the public door, which stores the address lower-cased.
     */
    private function publicConversation(string $email): SupportConversation
    {
        return app(OpensSupportConversations::class)->openPublic([
            'email' => $email,
            'subject' => 'Question',
            'body' => 'Hello from the form.',
        ]);
    }

    private function user(string $email): ConcreteUser
    {
        return ConcreteUser::create([
            'name' => 'Ada',
            'email' => $email,
            'password' => 'secret',
        ]);
    }
}
