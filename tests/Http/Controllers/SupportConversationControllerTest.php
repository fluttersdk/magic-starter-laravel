<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Enums\SupportMessageKind;
use FlutterSdk\MagicStarter\Enums\SupportSenderType;
use FlutterSdk\MagicStarter\Events\SupportConversationNeedsStaff;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Models\SupportMessage;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\SanctumServiceProvider;

/**
 * The signed-in customer's support endpoints, driven through the routes the
 * package registers.
 *
 * The opener is the only account that reads or writes a conversation. Every
 * isolation case asserts the opener's own success beside the intruder's 404,
 * so a route that is simply missing cannot pass it.
 */
class SupportConversationControllerTest extends TestCase
{
    private const TEAM = '6f1d1c55-4a35-4f0c-9d1f-4a3e5f7b2c10';

    private const OTHER_TEAM = '0a9b8c7d-6e5f-4a3b-8c1d-2e3f4a5b6c7d';

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
            $table->string('current_team_id')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->timestamps();
        });

        foreach ([
            'create_support_conversations_table.php',
            'create_support_messages_table.php',
        ] as $file) {
            $migration = require __DIR__ . '/../../../database/migrations/' . $file;
            $migration->up();
        }

        Event::fake([
            SupportConversationNeedsStaff::class,
        ]);
        Notification::fake();
    }

    protected function tearDown(): void
    {
        MagicStarter::reset();

        parent::tearDown();
    }

    public function test_a_teammate_gets_404_on_show_message_and_seen(): void
    {
        $opener = $this->user('opener@example.com', self::TEAM);
        $teammate = $this->user('mate@example.com', self::TEAM);
        $conversation = $this->openAs($opener);

        $this->assertOwnerReachesAndIntruderDoesNot($opener, $teammate, $conversation);
    }

    public function test_a_user_in_another_team_gets_404_on_show_message_and_seen(): void
    {
        $opener = $this->user('opener@example.com', self::TEAM);
        $stranger = $this->user('stranger@example.com', self::OTHER_TEAM);
        $conversation = $this->openAs($opener);

        $this->assertOwnerReachesAndIntruderDoesNot($opener, $stranger, $conversation);
    }

    public function test_the_index_lists_only_the_openers_own_conversations(): void
    {
        $opener = $this->user('opener@example.com', self::TEAM);
        $teammate = $this->user('mate@example.com', self::TEAM);
        $mine = $this->openAs($opener);
        $this->openAs($teammate);
        SupportConversation::create([
            'email' => 'opener@example.com',
            'subject' => 'Public',
            'source' => SupportConversationSource::PUBLIC_FORM,
        ]);

        $this->actingAs($opener, 'sanctum');
        $response = $this->getJson('/support/conversations');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame((string) $mine->getKey(), (string) $response->json('data.0.id'));
    }

    public function test_reads_and_seen_receipts_are_not_held_to_the_write_limit(): void
    {
        $opener = $this->user('opener@example.com', self::TEAM);
        $conversation = $this->openAs($opener);
        $this->actingAs($opener, 'sanctum');

        // A thread open is a show, a seen receipt and a list reread, and the
        // client polls every 30 seconds: ten reads in a minute is ordinary use.
        for ($i = 0; $i < 10; $i++) {
            $this->getJson("/support/conversations/{$conversation->getKey()}")->assertOk();
            $this->postJson("/support/conversations/{$conversation->getKey()}/seen")->assertNoContent();
        }

        // Opening the conversation above was the first of the six writes.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson("/support/conversations/{$conversation->getKey()}/messages", [
                'body' => "Message {$i}",
            ])->assertCreated();
        }

        $this->postJson("/support/conversations/{$conversation->getKey()}/messages", [
            'body' => 'One too many',
        ])->assertTooManyRequests();
    }

    public function test_a_malformed_id_answers_404(): void
    {
        $this->actingAs($this->user('opener@example.com', self::TEAM), 'sanctum');

        $this->getJson('/support/conversations/not-a-key')->assertNotFound();
    }

    public function test_store_takes_owner_and_team_from_the_auth_context_only(): void
    {
        $opener = $this->user('opener@example.com', self::TEAM);
        $other = $this->user('other@example.com', self::OTHER_TEAM);

        $this->actingAs($opener, 'sanctum');
        $response = $this->postJson('/support/conversations', [
            'subject' => 'Checks fail',
            'body' => 'My checks fail.',
            'team_id' => self::OTHER_TEAM,
            'user_id' => $other->getKey(),
            'status' => 'resolved',
            'assignee_id' => $other->getKey(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.subject', 'Checks fail')
            ->assertJsonPath('data.source', 'in_app')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.has_unread', false)
            ->assertJsonPath('data.messages.0.body', 'My checks fail.')
            ->assertJsonPath('data.messages.0.sender_type', 'customer');
        $conversation = SupportConversation::query()->sole();
        $this->assertSame((string) $opener->getKey(), (string) $conversation->user_id);
        $this->assertSame(self::TEAM, (string) $conversation->team_id);
        $this->assertNull($conversation->assignee_id);
        $this->assertSame('opener@example.com', $conversation->email);
        $this->assertSame(1, $conversation->number);
        $this->assertNotNull($conversation->waiting_since);
        Event::assertDispatchedTimes(SupportConversationNeedsStaff::class, 1);
        Event::assertDispatched(
            SupportConversationNeedsStaff::class,
            fn (SupportConversationNeedsStaff $event): bool => $event->reason === 'opened'
                && $event->conversation->is($conversation),
        );
        $this->assertResourceCarriesNoStaffOrTokenFields($response->json('data'));
    }

    public function test_store_accepts_contact_sales_and_refuses_the_public_form_source(): void
    {
        $this->actingAs($this->user('opener@example.com', self::TEAM), 'sanctum');

        $this->postJson('/support/conversations', [
            'subject' => 'Pricing',
            'body' => 'Enterprise plan?',
            'source' => 'contact_sales',
        ])->assertCreated()->assertJsonPath('data.source', 'contact_sales');

        $this->postJson('/support/conversations', [
            'subject' => 'Pricing',
            'body' => 'Enterprise plan?',
            'source' => 'public_form',
        ])->assertUnprocessable()->assertJsonValidationErrors('source');

        $this->assertSame(1, SupportConversation::query()->count());
    }

    public function test_a_body_over_the_limit_is_422_on_store_and_on_message(): void
    {
        $opener = $this->user('opener@example.com', self::TEAM);
        $conversation = $this->openAs($opener);
        $tooLong = str_repeat('a', 51);

        $this->actingAs($opener, 'sanctum');
        $this->postJson('/support/conversations', [
            'subject' => 'Long',
            'body' => $tooLong,
        ])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->postJson("/support/conversations/{$conversation->getKey()}/messages", [
            'body' => $tooLong,
        ])->assertUnprocessable()->assertJsonValidationErrors('body');

        $this->assertSame(1, SupportConversation::query()->count());
        $this->assertSame(1, SupportMessage::query()->count());
    }

    public function test_a_staff_note_never_appears(): void
    {
        $opener = $this->user('opener@example.com', self::TEAM);
        $conversation = $this->openAs($opener);
        $this->staffWrites($conversation, SupportMessageKind::NOTE, 'Internal: refund abuser?');

        $this->actingAs($opener, 'sanctum');
        $show = $this->getJson("/support/conversations/{$conversation->getKey()}");

        $show->assertOk()->assertJsonCount(1, 'data.messages');
        $this->assertStringNotContainsString('Internal', (string) $show->getContent());
        $this->assertFalse($show->json('data.has_unread'));

        $posted = $this->postJson("/support/conversations/{$conversation->getKey()}/messages", [
            'body' => 'Any news?',
        ]);
        $posted->assertCreated();
        $this->assertStringNotContainsString('Internal', (string) $posted->getContent());
        $index = $this->getJson('/support/conversations');
        $this->assertStringNotContainsString('Internal', (string) $index->getContent());
    }

    public function test_a_customer_message_reaches_staff_once_and_reopens_a_snoozed_conversation(): void
    {
        $opener = $this->user('opener@example.com', self::TEAM);
        $conversation = $this->openAs($opener);
        $conversation->forceFill([
            'status' => 'snoozed',
            'waiting_since' => null,
        ])->save();

        $this->actingAs($opener, 'sanctum');
        $response = $this->postJson("/support/conversations/{$conversation->getKey()}/messages", [
            'body' => 'Still broken.',
            'sender_type' => 'staff',
            'kind' => 'note',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.body', 'Still broken.')
            ->assertJsonPath('data.sender_type', 'customer');
        $message = SupportMessage::query()->where('body', 'Still broken.')->sole();
        $this->assertSame(SupportMessageKind::MESSAGE, $message->kind);
        $this->assertSame((string) $opener->getKey(), (string) $message->sender_id);
        $conversation->refresh();
        $this->assertSame('open', $conversation->status->value);
        $this->assertNotNull($conversation->waiting_since);
        $this->assertCount(1, Event::dispatched(
            SupportConversationNeedsStaff::class,
            fn (SupportConversationNeedsStaff $event): bool => $event->reason === 'customer_message'
                && $event->conversation->is($conversation),
        ));
    }

    public function test_has_unread_follows_a_staff_reply_and_seen_clears_it(): void
    {
        $opener = $this->user('opener@example.com', self::TEAM);
        $conversation = $this->openAs($opener);

        Carbon::setTestNow(now()->addMinute());
        $this->staffWrites($conversation, SupportMessageKind::MESSAGE, 'Fixed on our side.');

        $this->actingAs($opener, 'sanctum');
        $this->getJson('/support/conversations')->assertJsonPath('data.0.has_unread', true);
        $this->getJson("/support/conversations/{$conversation->getKey()}")
            ->assertJsonPath('data.has_unread', true)
            ->assertJsonPath('data.messages.1.sender_type', 'staff');

        Carbon::setTestNow(now()->addMinute());
        $this->postJson("/support/conversations/{$conversation->getKey()}/seen")->assertNoContent();

        $this->getJson('/support/conversations')->assertJsonPath('data.0.has_unread', false);
        Carbon::setTestNow();
    }

    public function test_the_routes_are_absent_with_the_feature_off(): void
    {
        $opener = $this->user('opener@example.com', self::TEAM);
        $conversation = $this->openAs($opener);

        config([
            'magic-starter.features' => [],
        ]);
        $this->app['router']->setRoutes(new RouteCollection);
        (new MagicStarterServiceProvider($this->app))->boot();

        $this->actingAs($opener, 'sanctum');
        $this->getJson('/support/conversations')->assertNotFound();
        $this->getJson("/support/conversations/{$conversation->getKey()}")->assertNotFound();
        $this->postJson('/support/public/conversations', [
            'email' => 'visitor@example.com',
            'subject' => 'Hello',
            'body' => 'Hi',
        ])->assertNotFound();
    }

    /**
     * The opener reaches show, message and seen; the intruder gets 404 on all
     * three and changes nothing.
     */
    private function assertOwnerReachesAndIntruderDoesNot(
        ConcreteUser $opener,
        ConcreteUser $intruder,
        SupportConversation $conversation,
    ): void {
        $id = $conversation->getKey();
        $seenBefore = $conversation->opener_seen_at?->toIso8601String();
        Carbon::setTestNow(now()->addHour());

        $this->actingAs($intruder, 'sanctum');
        $this->getJson("/support/conversations/{$id}")->assertNotFound();
        $this->postJson("/support/conversations/{$id}/messages", [
            'body' => 'Let me in.',
        ])->assertNotFound();
        $this->postJson("/support/conversations/{$id}/seen")->assertNotFound();
        $this->getJson('/support/conversations')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(0, SupportMessage::query()->where('body', 'Let me in.')->count());
        $this->assertSame($seenBefore, $conversation->refresh()->opener_seen_at?->toIso8601String());
        Event::assertDispatchedTimes(SupportConversationNeedsStaff::class, 1);

        $this->actingAs($opener, 'sanctum');
        $this->getJson("/support/conversations/{$id}")->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson("/support/conversations/{$id}/messages", [
            'body' => 'Mine.',
        ])->assertCreated();
        $this->postJson("/support/conversations/{$id}/seen")->assertNoContent();
        $this->assertSame(now()->toIso8601String(), $conversation->refresh()->opener_seen_at?->toIso8601String());
        Carbon::setTestNow();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertResourceCarriesNoStaffOrTokenFields(array $data): void
    {
        foreach ([
            'assignee_id',
            'assignee',
            'user_id',
            'team_id',
            'email',
            'thread_token_hash',
            'thread_token_version',
            'thread_token_expires_at',
            'handoff_reason',
            'staff_seen_at',
        ] as $field) {
            $this->assertArrayNotHasKey($field, $data);
        }

        foreach ($data['messages'] as $message) {
            $this->assertSame(
                [
                    'id',
                    'sender_type',
                    'body',
                    'created_at',
                ],
                array_keys($message),
            );
        }
    }

    private function openAs(ConcreteUser $user): SupportConversation
    {
        $this->actingAs($user, 'sanctum');

        $id = $this->postJson('/support/conversations', [
            'subject' => 'Help',
            'body' => 'Hello from ' . $user->email,
        ])->assertCreated()->json('data.id');

        return SupportConversation::query()->findOrFail($id);
    }

    private function staffWrites(SupportConversation $conversation, SupportMessageKind $kind, string $body): void
    {
        $conversation->messages()->create([
            'sender_type' => SupportSenderType::STAFF,
            'kind' => $kind,
            'body' => $body,
        ]);
    }

    private function user(string $email, string $teamId): ConcreteUser
    {
        $user = ConcreteUser::create([
            'name' => Str::before($email, '@'),
            'email' => $email,
            'password' => 'secret',
        ]);
        $user->forceFill([
            'current_team_id' => $teamId,
        ])->save();

        return $user;
    }
}
