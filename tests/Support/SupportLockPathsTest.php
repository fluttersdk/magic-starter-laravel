<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use Closure;
use FlutterSdk\MagicStarter\Contracts\OpensSupportConversations;
use FlutterSdk\MagicStarter\Contracts\PostsCustomerSupportMessages;
use FlutterSdk\MagicStarter\Contracts\RespondsToSupport;
use FlutterSdk\MagicStarter\Enums\SupportAiPool;
use FlutterSdk\MagicStarter\Events\SupportConversationNeedsStaff;
use FlutterSdk\MagicStarter\Events\SupportConversationUpdated;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Jobs\RespondToSupportConversation;
use FlutterSdk\MagicStarter\Listeners\LinkSupportConversationsOnVerified;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportAnswer;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportTurn;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportUsage;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\SanctumServiceProvider;

/**
 * The support desk's row locks, read off the SQL a real PostgreSQL receives.
 *
 * SQLite ignores `for update`, so a lock that was dropped or never written
 * passes every other test; these run only on `DB_CONNECTION=pgsql` (the CI
 * `pgsql` job) and each asserts the locking select of one path.
 */
class SupportLockPathsTest extends TestCase
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

        $app['config']->set('magic-starter.features', [
            Features::support(),
        ]);
        $app['config']->set('auth.providers.users.model', ConcreteUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Row locks show only on PostgreSQL; run with DB_CONNECTION=pgsql.');
        }

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique()->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->string('current_team_id')->nullable();
            $table->string('locale')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->timestamps();
        });

        foreach ([
            'create_support_conversations_table.php',
            'create_support_messages_table.php',
            'add_ai_columns_to_support_tables.php',
            // The responder reads the trigger message's attachments.
            'create_support_message_attachments_table.php',
        ] as $file) {
            $migration = require __DIR__ . '/../../database/migrations/' . $file;
            $migration->up();
        }

        Queue::fake();
        Notification::fake();
        Event::fake([
            SupportConversationNeedsStaff::class,
            SupportConversationUpdated::class,
        ]);
    }

    protected function tearDown(): void
    {
        MagicStarter::reset();

        parent::tearDown();
    }

    public function test_contact_sales_locks_the_openers_user_row(): void
    {
        $user = $this->customer();

        $this->assertLocks('users', fn () => app(OpensSupportConversations::class)->open($user, [
            'body' => 'Enterprise plan?',
            'source' => 'contact_sales',
        ]));
    }

    public function test_a_customer_message_locks_the_conversation_row(): void
    {
        $user = $this->customer();
        $conversation = app(OpensSupportConversations::class)->open($user, [
            'topic' => 'technical',
            'body' => 'My checks fail.',
        ]);

        $this->assertLocks('support_conversations', fn () => app(PostsCustomerSupportMessages::class)
            ->post($conversation, 'Still failing.', $user));
    }

    public function test_the_responder_locks_the_conversation_row_when_it_writes_the_answer(): void
    {
        $this->app->instance(RespondsToSupport::class, new class implements RespondsToSupport
        {
            public function respond(SupportTurn $turn): SupportAnswer
            {
                return new SupportAnswer('Your plan renews on the 1st.', 0.9, new SupportUsage(300, 100));
            }

            public function pool(SupportConversation $conversation): SupportAiPool
            {
                return SupportAiPool::FREE;
            }
        });
        app(OpensSupportConversations::class)->open($this->customer(), [
            'topic' => 'technical',
            'body' => 'When does my plan renew?',
        ]);
        $job = Queue::pushed(RespondToSupportConversation::class)->sole();

        $this->assertLocks('support_conversations', fn () => $this->app->call([$job, 'handle']));
    }

    public function test_linking_a_verified_address_locks_the_unowned_conversations(): void
    {
        $user = $this->customer();
        app(OpensSupportConversations::class)->openPublic([
            'email' => $user->email,
            'body' => 'Where is my invoice?',
        ]);

        $this->assertLocks('support_conversations', fn () => app(LinkSupportConversationsOnVerified::class)
            ->handle(new Verified($user)));
    }

    /**
     * Run `$action` and assert one of its selects from `$table` ends in `for update`.
     */
    private function assertLocks(string $table, Closure $action): void
    {
        $locking = [];

        DB::listen(function (QueryExecuted $query) use (&$locking, $table): void {
            if (preg_match('/^select .* from "' . $table . '" .* for update$/i', $query->sql) === 1) {
                $locking[] = $query->sql;
            }
        });

        $action();

        $this->assertNotEmpty($locking, "No `for update` select from {$table} ran.");
    }

    private function customer(): ConcreteUser
    {
        return ConcreteUser::create([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'secret',
            'email_verified_at' => now(),
        ]);
    }
}
