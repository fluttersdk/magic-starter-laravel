<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use Closure;
use FlutterSdk\MagicStarter\Audit\Audit;
use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Enums\SupportMessageKind;
use FlutterSdk\MagicStarter\Enums\SupportSenderType;
use FlutterSdk\MagicStarter\Events\SupportConversationNeedsStaff;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Models\SupportMessage;
use FlutterSdk\MagicStarter\Support\SupportThreadToken;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\SanctumServiceProvider;

/**
 * The public support door: a visitor with no account writes in, and follows the
 * thread through a mailed link whose token is the only credential.
 *
 * Every submission is a new conversation keyed by the address given, nothing is
 * mailed back on submit, and a token that is unknown, replaced, revoked or
 * expired is indistinguishable from a missing page.
 */
class PublicSupportThreadControllerTest extends TestCase
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
            Features::audit(),
        ]);
        $app['config']->set('auth.providers.users.model', ConcreteUser::class);
        $app['config']->set('magic-starter.support.message_max_length', 50);
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
            $table->timestamps();
        });

        foreach ([
            'create_support_conversations_table.php',
            'create_support_messages_table.php',
            'add_ai_columns_to_support_tables.php',
            'create_magic_starter_audits_table.php',
        ] as $file) {
            $migration = require __DIR__ . '/../../../database/migrations/' . $file;
            $migration->up();
        }

        Event::fake([
            SupportConversationNeedsStaff::class,
        ]);
        Notification::fake();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        MagicStarter::reset();
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_two_submissions_with_one_email_make_two_conversations_and_mail_nothing(): void
    {
        $first = $this->submit();
        $second = $this->submit([
            'email' => 'Visitor@Example.com',
            'subject' => 'Second question',
        ]);

        $first->assertCreated();
        $second->assertCreated();
        $this->assertSame(2, SupportConversation::query()->count());
        $this->assertNotSame($first->json('data.id'), $second->json('data.id'));
        foreach (SupportConversation::query()->get() as $conversation) {
            $this->assertNull($conversation->user_id);
            $this->assertNull($conversation->team_id);
            $this->assertSame('visitor@example.com', $conversation->email);
            $this->assertSame(SupportConversationSource::PUBLIC_FORM, $conversation->source);
            $this->assertNull($conversation->thread_token_hash);
        }
        foreach ([
            $first,
            $second,
        ] as $response) {
            $this->assertStringNotContainsString('token', (string) $response->getContent());
            $this->assertStringNotContainsString('visitor@example.com', (string) $response->getContent());
        }
        $message = SupportMessage::query()->where('body', 'I have a question.')->firstOrFail();
        $this->assertSame(SupportSenderType::CUSTOMER, $message->sender_type);
        $this->assertNull($message->sender_id);
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
        Event::assertDispatchedTimes(SupportConversationNeedsStaff::class, 2);
        Event::assertDispatched(
            SupportConversationNeedsStaff::class,
            fn (SupportConversationNeedsStaff $event): bool => $event->reason === 'opened',
        );
    }

    public function test_owner_fields_in_the_input_are_ignored(): void
    {
        $user = ConcreteUser::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
        ]);

        $this->submit([
            'user_id' => $user->getKey(),
            'team_id' => '6f1d1c55-4a35-4f0c-9d1f-4a3e5f7b2c10',
            'status' => 'resolved',
            'assignee_id' => $user->getKey(),
            'source' => 'contact_sales',
        ])->assertCreated()->assertJsonPath('data.source', 'contact_sales');

        $conversation = SupportConversation::query()->sole();
        $this->assertNull($conversation->user_id);
        $this->assertNull($conversation->team_id);
        $this->assertNull($conversation->assignee_id);
        $this->assertSame('open', $conversation->status->value);
    }

    public function test_the_in_app_source_and_an_oversized_body_are_refused(): void
    {
        $this->submit([
            'source' => 'in_app',
        ])->assertUnprocessable()->assertJsonValidationErrors('source');
        $this->submit([
            'body' => str_repeat('a', 51),
        ])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->submit([
            'email' => 'not-an-address',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertSame(0, SupportConversation::query()->count());
    }

    public function test_an_extra_rule_class_is_resolved_from_the_container_and_enforced(): void
    {
        config([
            'magic-starter.support.public.extra_rules' => [
                'body' => [
                    PublicSupportBlocklistRule::class,
                ],
            ],
        ]);
        $this->app->bind(
            PublicSupportBlocklistRule::class,
            fn (): PublicSupportBlocklistRule => new PublicSupportBlocklistRule('casino'),
        );

        $this->submit([
            'body' => 'Best casino offers',
        ])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->submit()->assertCreated();

        $this->assertSame(1, SupportConversation::query()->count());
    }

    public function test_a_live_token_shows_the_visible_thread_without_a_referrer(): void
    {
        [$conversation, $token] = $this->threadWithToken();
        Carbon::setTestNow(now()->addMinute());
        $conversation->messages()->create([
            'sender_type' => SupportSenderType::STAFF,
            'kind' => SupportMessageKind::NOTE,
            'body' => 'Internal: looks like spam',
        ]);
        $conversation->messages()->create([
            'sender_type' => SupportSenderType::STAFF,
            'kind' => SupportMessageKind::MESSAGE,
            'body' => 'Happy to help.',
        ]);

        $response = $this->getJson("/support/public/thread/{$token}");

        $response->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertJsonPath('data.id', $conversation->getKey())
            ->assertJsonPath('data.has_unread', true)
            ->assertJsonCount(2, 'data.messages');
        $this->assertStringNotContainsString('Internal', (string) $response->getContent());
        $this->assertStringNotContainsString($token, (string) $response->getContent());
    }

    public function test_a_reply_through_a_live_token_reaches_staff_and_audits_without_the_token(): void
    {
        [$conversation, $token] = $this->threadWithToken();
        // Staff answered, so the visitor's next line opens a new wait and alerts once.
        $conversation->forceFill(['waiting_since' => null])->save();

        $response = $this->postJson("/support/public/thread/{$token}", [
            'body' => 'Any update?',
        ]);

        $response->assertCreated()
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertJsonPath('data.body', 'Any update?')
            ->assertJsonPath('data.sender_type', 'customer');
        $message = SupportMessage::query()->where('body', 'Any update?')->sole();
        $this->assertNull($message->sender_id);
        $this->assertSame((string) $conversation->getKey(), (string) $message->conversation_id);
        Event::assertDispatched(
            SupportConversationNeedsStaff::class,
            fn (SupportConversationNeedsStaff $event): bool => $event->reason === 'customer_message'
                && $event->conversation->is($conversation),
        );
        $this->assertTrue(Audit::query()->where('event', 'support.customer_message')->exists());
        foreach (Audit::query()->get() as $audit) {
            $this->assertStringNotContainsString($token, (string) json_encode($audit->getAttributes()));
        }
    }

    public function test_bad_expired_and_revoked_tokens_answer_404(): void
    {
        [$conversation, $token] = $this->threadWithToken();

        $this->assertThreadIsGone('not-a-real-token');

        Carbon::setTestNow(now()->addDays(15));
        $this->assertThreadIsGone($token);
        Carbon::setTestNow();

        $replaced = $token;
        $token = SupportThreadToken::mint($conversation);
        $this->assertThreadIsGone($replaced);
        $this->getJson("/support/public/thread/{$token}")->assertOk();

        SupportThreadToken::revoke($conversation);
        $this->assertThreadIsGone($token);

        $this->assertSame(1, SupportMessage::query()->count());
    }

    public function test_the_public_store_is_limited_per_address_and_per_ip(): void
    {
        foreach (range(1, 3) as $attempt) {
            $this->withServerVariables([
                'REMOTE_ADDR' => "10.0.0.{$attempt}",
            ])->postJson('/support/public/conversations', $this->payload())->assertCreated();
        }

        $this->withServerVariables([
            'REMOTE_ADDR' => '10.0.0.9',
        ])->postJson('/support/public/conversations', $this->payload())->assertTooManyRequests();
        $this->withServerVariables([
            'REMOTE_ADDR' => '10.0.0.9',
        ])->postJson('/support/public/conversations', $this->payload([
            'email' => 'someone-else@example.com',
        ]))->assertCreated();

        $this->assertSame(4, SupportConversation::query()->count());
    }

    private function assertThreadIsGone(string $token): void
    {
        $this->getJson("/support/public/thread/{$token}")->assertNotFound();
        $this->postJson("/support/public/thread/{$token}", [
            'body' => 'Hello?',
        ])->assertNotFound();
    }

    /**
     * @return array{0: SupportConversation, 1: string}
     */
    private function threadWithToken(): array
    {
        $this->submit()->assertCreated();
        $conversation = SupportConversation::query()->sole();

        return [
            $conversation,
            SupportThreadToken::mint($conversation),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<\Illuminate\Http\JsonResponse>
     */
    private function submit(array $overrides = []): TestResponse
    {
        return $this->postJson('/support/public/conversations', $this->payload($overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'email' => 'visitor@example.com',
            'name' => 'Visitor',
            'subject' => 'Question',
            'body' => 'I have a question.',
            ...$overrides,
        ];
    }
}

/**
 * A blocklist an adopter registers through `support.public.extra_rules`; its
 * word arrives through the container to prove the class is resolved there.
 */
class PublicSupportBlocklistRule implements ValidationRule
{
    public function __construct(
        private string $word,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (str_contains(strtolower((string) $value), $this->word)) {
            $fail('The :attribute is not accepted.');
        }
    }
}
