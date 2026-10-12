<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Support\SupportThreadToken;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * Locks the public thread link: the plaintext leaves once, only its hash is at
 * rest, and a new mint, a revoke or the expiry each close the old link.
 */
class SupportThreadTokenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'create_users_table.php',
            'create_teams_table.php',
            'create_support_conversations_table.php',
            'create_support_messages_table.php',
        ] as $file) {
            $this->artisan('migrate', [
                '--path' => __DIR__ . '/../../database/migrations/' . $file,
                '--realpath' => true,
            ]);
        }
    }

    public function test_a_minted_token_resolves_to_its_conversation(): void
    {
        $conversation = $this->createConversation();

        $token = SupportThreadToken::mint($conversation);

        $this->assertSame(
            $conversation->getKey(),
            SupportThreadToken::resolve($token)?->getKey(),
        );
    }

    public function test_the_token_is_url_safe_and_carries_forty_random_bytes(): void
    {
        $token = SupportThreadToken::mint($this->createConversation());

        // 40 bytes in unpadded base64url are 54 characters.
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{54}\z/', $token);
    }

    public function test_no_column_holds_the_plaintext(): void
    {
        $conversation = $this->createConversation();

        $token = SupportThreadToken::mint($conversation);

        $row = (array) DB::table('support_conversations')
            ->where('id', $conversation->getKey())
            ->sole();
        $this->assertSame(hash('sha256', $token), $row['thread_token_hash']);
        $this->assertNotSame($token, $row['thread_token_hash']);
        foreach ($row as $column => $value) {
            $this->assertStringNotContainsString(
                $token,
                (string) $value,
                "Column {$column} holds the plaintext token.",
            );
        }
    }

    public function test_a_mint_sets_the_expiry_from_the_configured_ttl(): void
    {
        config()->set('magic-starter.support.public.link_ttl_days', 3);
        // A timestamp column keeps whole seconds.
        $this->freezeSecond();
        $conversation = $this->createConversation();

        SupportThreadToken::mint($conversation);

        $this->assertTrue(now()->addDays(3)->equalTo($conversation->refresh()->thread_token_expires_at));
    }

    public function test_a_tampered_token_does_not_resolve(): void
    {
        $token = SupportThreadToken::mint($this->createConversation());
        $last = $token[-1] === 'A' ? 'B' : 'A';

        $this->assertNull(SupportThreadToken::resolve(substr($token, 0, -1) . $last));
        $this->assertNull(SupportThreadToken::resolve(hash('sha256', $token)));
        $this->assertNull(SupportThreadToken::resolve(''));
    }

    public function test_an_expired_token_does_not_resolve(): void
    {
        $token = SupportThreadToken::mint($this->createConversation());

        $this->travel(15)->days();

        $this->assertNull(SupportThreadToken::resolve($token));
    }

    public function test_a_revoked_token_does_not_resolve(): void
    {
        $conversation = $this->createConversation();
        $token = SupportThreadToken::mint($conversation);
        $version = $conversation->thread_token_version;

        SupportThreadToken::revoke($conversation);

        $this->assertNull(SupportThreadToken::resolve($token));
        $conversation->refresh();
        $this->assertNull($conversation->thread_token_hash);
        $this->assertSame($version + 1, $conversation->thread_token_version);
    }

    public function test_minting_again_invalidates_the_previous_token(): void
    {
        $conversation = $this->createConversation();
        $first = SupportThreadToken::mint($conversation);
        $version = $conversation->thread_token_version;

        $second = SupportThreadToken::mint($conversation);

        $this->assertNotSame($first, $second);
        $this->assertNull(SupportThreadToken::resolve($first));
        $this->assertSame($conversation->getKey(), SupportThreadToken::resolve($second)?->getKey());
        $this->assertSame($version + 1, $conversation->refresh()->thread_token_version);
    }

    private function createConversation(): SupportConversation
    {
        return SupportConversation::create([
            'email' => 'visitor@example.com',
            'name' => 'Visitor',
            'subject' => 'Billing question',
            'source' => SupportConversationSource::PUBLIC_FORM,
        ]);
    }
}
