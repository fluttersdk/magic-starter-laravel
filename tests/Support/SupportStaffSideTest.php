<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use Filament\Facades\Filament;
use Filament\FilamentManager;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Panel;
use Filament\PanelRegistry;
use FlutterSdk\MagicStarter\Actions\ChangeSupportConversation;
use FlutterSdk\MagicStarter\Actions\ReplyToSupportConversation;
use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Enums\SupportConversationStatus;
use FlutterSdk\MagicStarter\Enums\SupportMessageKind;
use FlutterSdk\MagicStarter\Enums\SupportPriority;
use FlutterSdk\MagicStarter\Enums\SupportSenderType;
use FlutterSdk\MagicStarter\Events\SupportConversationNeedsStaff;
use FlutterSdk\MagicStarter\Events\SupportConversationUpdated;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Filament\MagicStarterPlugin;
use FlutterSdk\MagicStarter\Listeners\NotifyStaffOfSupportConversation;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Models\SupportMessage;
use FlutterSdk\MagicStarter\Notifications\SupportConversationNeedsStaffNotification;
use FlutterSdk\MagicStarter\Notifications\SupportReplyNotification;
use FlutterSdk\MagicStarter\Support\SupportStaffRecipients;
use FlutterSdk\MagicStarter\Support\SupportThreadToken;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteAdminUser;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\EventFake;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Locks the staff side of the support desk: who counts as staff, what a reply
 * and a note reach, how a public link rotates and dies, and that the realtime
 * and push payloads carry ids and never a message.
 */
class SupportStaffSideTest extends TestCase
{
    private const THREAD_URL = 'https://app.test/support/thread/{token}';

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'create_users_table.php',
            'add_guest_and_phone_fields_to_users_table.php',
            'create_support_conversations_table.php',
            'create_support_messages_table.php',
            'add_ai_columns_to_support_tables.php',
        ] as $file) {
            $this->artisan('migrate', [
                '--path' => __DIR__ . '/../../database/migrations/' . $file,
                '--realpath' => true,
            ]);
        }

        config()->set('magic-starter.admin.emails', [
            'staff@example.com',
            ' Unverified@Example.com ',
            'guest@example.com',
        ]);
        config()->set('magic-starter.features', [
            Features::onesignal(),
        ]);
        config()->set('magic-starter.support.public.thread_url', self::THREAD_URL);

        Notification::fake();
        Event::fake([
            SupportConversationUpdated::class,
        ]);
    }

    public function test_only_an_allowlisted_verified_non_guest_is_a_recipient(): void
    {
        $staff = $this->createUser('staff@example.com');
        $unverified = $this->createUser('unverified@example.com', verified: false);
        $guest = $this->createUser('guest@example.com', guest: true);
        $outsider = $this->createUser('outsider@example.com');

        $recipients = new SupportStaffRecipients;

        $this->assertSame(
            [
                $staff->getKey(),
            ],
            $recipients->all()->map->getKey()->all(),
        );
        $this->assertTrue($recipients->includes($staff));
        $this->assertFalse($recipients->includes($unverified));
        $this->assertFalse($recipients->includes($guest));
        $this->assertFalse($recipients->includes($outsider));
    }

    public function test_a_panel_carrying_the_plugin_must_admit_the_recipient(): void
    {
        if (! class_exists(Panel::class)) {
            $this->markTestSkipped('filament/filament is not installed.');
        }

        // 1. The allowlisted, verified staff member is refused by the panel's own rule.
        MagicStarter::useUserModel(ConcreteAdminUser::class);
        $staff = ConcreteAdminUser::query()->findOrFail($this->createUser('staff@example.com')->getKey());
        $this->app->singleton('filament', fn (): FilamentManager => new FilamentManager);
        $this->app->singleton(PanelRegistry::class, fn (): PanelRegistry => new PanelRegistry);
        Filament::clearResolvedInstances();
        // Filed straight into the registry: `register()` boots Livewire, which this case does not load.
        app(PanelRegistry::class)->panels['admin'] = Panel::make()
            ->id('admin')
            ->plugin(MagicStarterPlugin::make()->authorizeUsing(fn (): bool => false));

        // 2. The panel gate is part of what makes a recipient.
        $recipients = new SupportStaffRecipients;

        $this->assertFalse($recipients->includes($staff));
        $this->assertCount(0, $recipients->all());
    }

    public function test_assigning_a_user_who_is_not_staff_throws(): void
    {
        $staff = $this->createUser('staff@example.com');
        $outsider = $this->createUser('outsider@example.com');
        $conversation = $this->createInAppConversation($this->createUser('ada@example.com'));

        try {
            (new ChangeSupportConversation(new SupportStaffRecipients))->change($staff, $conversation, [
                'assignee_id' => $outsider->getKey(),
            ]);
            $this->fail('A non-staff assignee was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assignee_id', $exception->errors());
        }

        $this->assertNull($conversation->refresh()->assignee_id);
        Event::assertNotDispatched(SupportConversationUpdated::class);
    }

    public function test_a_change_applies_status_priority_and_assignee_and_broadcasts_to_the_opener(): void
    {
        $staff = $this->createUser('staff@example.com');
        $opener = $this->createUser('ada@example.com');
        $conversation = $this->createInAppConversation($opener);

        $changed = (new ChangeSupportConversation(new SupportStaffRecipients))->change($staff, $conversation, [
            'status' => 'snoozed',
            'priority' => 'urgent',
            'assignee_id' => $staff->getKey(),
            'user_id' => $staff->getKey(),
        ]);

        $changed->refresh();
        $this->assertSame(SupportConversationStatus::SNOOZED, $changed->status);
        $this->assertSame(SupportPriority::URGENT, $changed->priority);
        $this->assertEquals($staff->getKey(), $changed->assignee_id);
        $this->assertEquals($opener->getKey(), $changed->user_id);
        Event::assertDispatched(
            SupportConversationUpdated::class,
            fn (SupportConversationUpdated $event): bool => array_keys($event->broadcastWith()) === [
                'conversation_id',
                'event_id',
            ] && $event->broadcastWith()['conversation_id'] === $conversation->getKey(),
        );
    }

    public function test_two_updates_to_one_conversation_never_broadcast_the_same_payload(): void
    {
        // magic's Reverb driver drops a frame whose channel, event and payload
        // it has already seen, so a payload of the id alone would deliver only
        // a conversation's first update.
        $conversation = $this->createInAppConversation($this->createUser('ada@example.com'));

        $first = new SupportConversationUpdated($conversation);
        $second = new SupportConversationUpdated($conversation);

        $this->assertSame($first->broadcastWith()['conversation_id'], $second->broadcastWith()['conversation_id']);
        $this->assertNotSame($first->broadcastWith(), $second->broadcastWith());
        $this->assertSame($first->broadcastWith(), $first->broadcastWith());
    }

    public function test_a_note_reaches_no_customer_channel_and_no_broadcast(): void
    {
        $staff = $this->createUser('staff@example.com');
        $inApp = $this->createInAppConversation($this->createUser('ada@example.com'));
        $public = $this->createPublicConversation();
        $token = SupportThreadToken::mint($public);
        $action = new ReplyToSupportConversation;

        $note = $action->reply($staff, $inApp, 'Customer is on the legacy plan.', true);
        $action->reply($staff, $public, 'Looks like spam.', true);

        $this->assertSame(SupportMessageKind::NOTE, $note->kind);
        $this->assertSame(SupportSenderType::STAFF, $note->sender_type);
        $this->assertNull($inApp->refresh()->first_reply_at);
        $this->assertNotNull($inApp->waiting_since);
        $this->assertSame($public->getKey(), SupportThreadToken::resolve($token)?->getKey());
        Notification::assertNothingSent();
        Event::assertNotDispatched(SupportConversationUpdated::class);
    }

    public function test_an_in_app_reply_reaches_the_opener_by_database_and_push_and_broadcasts(): void
    {
        $staff = $this->createUser('staff@example.com');
        $opener = $this->createUser('ada@example.com');
        $conversation = $this->createInAppConversation($opener);

        $message = (new ReplyToSupportConversation)->reply($staff, $conversation, 'We fixed it.', false);

        // 1. The reply is recorded and answers the wait.
        $conversation->refresh();
        $this->assertSame(SupportMessageKind::MESSAGE, $message->kind);
        $this->assertEquals($staff->getKey(), $message->sender_id);
        $this->assertNotNull($conversation->first_reply_at);
        $this->assertNull($conversation->waiting_since);

        // 2. The opener alone is told, in app (live too) and by push, never by mail.
        Notification::assertSentTo(
            $opener,
            SupportReplyNotification::class,
            fn (SupportReplyNotification $notification, array $channels): bool => $channels === [
                'database',
                'onesignal',
                'broadcast',
            ],
        );
        Notification::assertSentTimes(SupportReplyNotification::class, 1);
        Event::assertDispatched(SupportConversationUpdated::class);
    }

    public function test_a_second_reply_keeps_the_first_reply_time(): void
    {
        $staff = $this->createUser('staff@example.com');
        $conversation = $this->createInAppConversation($this->createUser('ada@example.com'));
        $action = new ReplyToSupportConversation;

        $action->reply($staff, $conversation, 'First.', false);
        $first = $conversation->refresh()->first_reply_at;
        $this->travel(5)->minutes();
        $action->reply($staff, $conversation, 'Second.', false);

        $this->assertTrue($first?->equalTo($conversation->refresh()->first_reply_at));
    }

    public function test_a_public_reply_mails_a_link_whose_token_resolves_while_the_previous_one_does_not(): void
    {
        $staff = $this->createUser('staff@example.com');
        $conversation = $this->createPublicConversation();
        $previous = SupportThreadToken::mint($conversation);

        (new ReplyToSupportConversation)->reply($staff, $conversation, 'Here is the answer.', false);

        $mailed = null;
        Notification::assertSentOnDemand(
            SupportReplyNotification::class,
            function (SupportReplyNotification $notification, array $channels, AnonymousNotifiable $notifiable) use (
                &$mailed,
            ): bool {
                $mail = $notification->toMail($notifiable);
                $mailed = $mail->actionUrl;

                return $channels === ['mail']
                    && $notifiable->routes === ['mail' => 'visitor@example.com']
                    && in_array('Here is the answer.', $mail->introLines, true);
            },
        );
        $this->assertIsString($mailed);
        $this->assertStringStartsWith('https://app.test/support/thread/', $mailed);
        $token = substr($mailed, strlen('https://app.test/support/thread/'));
        $this->assertSame($conversation->getKey(), SupportThreadToken::resolve($token)?->getKey());
        $this->assertNull(SupportThreadToken::resolve($previous));
        Event::assertNotDispatched(SupportConversationUpdated::class);
    }

    public function test_the_queued_reply_job_is_encrypted_because_it_carries_the_live_token(): void
    {
        $notification = new SupportReplyNotification(new SupportMessage, 'https://app.test/support/thread/secret');
        $job = new SendQueuedNotifications(new AnonymousNotifiable, $notification, ['mail']);

        $this->assertTrue($job->shouldBeEncrypted);
    }

    public function test_a_public_reply_without_a_thread_url_is_refused_before_anything_is_written(): void
    {
        config()->set('magic-starter.support.public.thread_url', null);
        $staff = $this->createUser('staff@example.com');
        $conversation = $this->createPublicConversation();

        try {
            (new ReplyToSupportConversation)->reply($staff, $conversation, 'Lost answer.', false);
            $this->fail('A public reply went out without a link to follow.');
        } catch (ValidationException $exception) {
            // A refusal the panel's ContractAction turns into a notification, not a 500.
            $this->assertSame(
                [__('magic-starter::admin_support.refusals.thread_url_missing')],
                $exception->errors()['body'],
            );
        }

        $this->assertSame(0, $conversation->messages()->count());
        Notification::assertNothingSent();
    }

    public function test_resolving_revokes_the_public_token(): void
    {
        $staff = $this->createUser('staff@example.com');
        $conversation = $this->createPublicConversation();
        $token = SupportThreadToken::mint($conversation);

        (new ChangeSupportConversation(new SupportStaffRecipients))->change($staff, $conversation, [
            'status' => SupportConversationStatus::RESOLVED->value,
        ]);

        $conversation->refresh();
        $this->assertSame(SupportConversationStatus::RESOLVED, $conversation->status);
        $this->assertNotNull($conversation->resolved_at);
        $this->assertNull($conversation->thread_token_hash);
        $this->assertNull(SupportThreadToken::resolve($token));
    }

    public function test_the_broadcast_is_named_and_addressed_to_the_opener_with_only_the_conversation_and_event_ids(): void
    {
        $opener = $this->createUser('ada@example.com');
        $conversation = $this->createInAppConversation($opener);

        $event = new SupportConversationUpdated($conversation);

        $this->assertSame('support.conversation.updated', $event->broadcastAs());
        $this->assertSame(
            [
                'conversation_id' => $conversation->getKey(),
                'event_id' => $event->eventId,
            ],
            $event->broadcastWith(),
        );
        $this->assertTrue(Str::isUlid($event->eventId));
        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertSame('private-App.Models.User.' . $opener->getKey(), $channels[0]->name);
    }

    public function test_a_broadcast_outage_never_fails_the_write(): void
    {
        // 1. A real dispatch this time, through a broadcaster that always throws.
        /** @var EventFake $fake */
        $fake = Event::getFacadeRoot();
        Event::swap($fake->dispatcher);
        $this->app->make(BroadcastManager::class)->extend('down', fn (): Broadcaster => new class implements Broadcaster
        {
            public function auth($request): mixed
            {
                return null;
            }

            public function validAuthenticationResponse($request, $result): mixed
            {
                return null;
            }

            public function broadcast(array $channels, $event, array $payload = []): void
            {
                throw new RuntimeException('Reverb is down.');
            }
        });
        config()->set('broadcasting.default', 'down');
        config()->set('queue.default', 'sync');
        $staff = $this->createUser('staff@example.com');
        $conversation = $this->createInAppConversation($this->createUser('ada@example.com'));

        // 2. The reply lands regardless.
        $message = (new ReplyToSupportConversation)->reply($staff, $conversation, 'Still delivered.', false);

        $this->assertTrue($message->exists);
        $this->assertNotNull($conversation->refresh()->first_reply_at);
    }

    public function test_the_opener_push_carries_ids_and_no_message_body(): void
    {
        $opener = $this->createUser('ada@example.com');
        $conversation = $this->createInAppConversation($opener);
        $message = $conversation->messages()->create([
            'sender_type' => SupportSenderType::STAFF,
            'kind' => SupportMessageKind::MESSAGE,
            'body' => 'Secret reply text.',
        ]);

        $notification = new SupportReplyNotification($message);
        $push = $notification->toOneSignal($opener);
        $database = $notification->toArray($opener);

        $this->assertSame(
            [
                'conversation_id' => $conversation->getKey(),
                'message_id' => $message->getKey(),
                'action_url' => '/support/' . $conversation->getKey(),
            ],
            (array) $push->getData(),
        );
        $this->assertStringNotContainsString('Secret reply text.', json_encode($push, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('Secret reply text.', json_encode($database, JSON_THROW_ON_ERROR));
        $this->assertSame($conversation->getKey(), $database['conversation_id']);
        $this->assertStringEndsWith((string) $conversation->getKey(), $database['action_url']);
    }

    public function test_the_reply_action_url_follows_the_configured_client_thread_path(): void
    {
        config()->set('magic-starter.support.client_thread_path', '/help/threads/{id}/view');
        $opener = $this->createUser('ada@example.com');
        $conversation = $this->createInAppConversation($opener);
        $message = $conversation->messages()->create([
            'sender_type' => SupportSenderType::STAFF,
            'kind' => SupportMessageKind::MESSAGE,
            'body' => 'Done.',
        ]);

        $notification = new SupportReplyNotification($message);

        $this->assertSame(
            '/help/threads/' . $conversation->getKey() . '/view',
            $notification->toArray($opener)['action_url'],
        );
        $this->assertSame(
            '/help/threads/' . $conversation->getKey() . '/view',
            $notification->toOneSignal($opener)->getData()->action_url,
        );
    }

    public function test_the_listener_tells_every_staff_recipient_with_ids_number_and_subject_only(): void
    {
        $staff = $this->createUser('staff@example.com');
        $this->createUser('unverified@example.com', verified: false);
        $conversation = $this->createInAppConversation($this->createUser('ada@example.com'));
        $conversation->messages()->create([
            'sender_type' => SupportSenderType::CUSTOMER,
            'kind' => SupportMessageKind::MESSAGE,
            'body' => 'My private customer words.',
        ]);

        (new NotifyStaffOfSupportConversation(new SupportStaffRecipients))->handle(
            new SupportConversationNeedsStaff($conversation, 'opened'),
        );

        Notification::assertSentTimes(SupportConversationNeedsStaffNotification::class, 1);
        Notification::assertSentTo(
            $staff,
            SupportConversationNeedsStaffNotification::class,
            function (SupportConversationNeedsStaffNotification $notification, array $channels) use (
                $staff,
                $conversation,
            ): bool {
                $push = $notification->toOneSignal($staff);
                $database = $notification->toDatabase($staff);
                $rendered = json_encode([
                    $push,
                    $database,
                    $notification->toMail($staff)->toArray(),
                ], JSON_THROW_ON_ERROR);

                $this->assertSame(
                    [
                        'conversation_id' => $conversation->getKey(),
                        'number' => $conversation->number,
                    ],
                    (array) $push->getData(),
                );
                $this->assertStringNotContainsString('Checks fail', json_encode($push, JSON_THROW_ON_ERROR));
                $this->assertStringNotContainsString('My private customer words.', $rendered);
                $this->assertSame($conversation->getKey(), $database['conversation_id']);
                $this->assertSame('Checks fail', $database['subject']);

                if (class_exists(FilamentNotification::class)) {
                    $this->assertSame('filament', $database['format']);
                }

                return $channels === [
                    'database',
                    'mail',
                    'onesignal',
                    'broadcast',
                ];
            },
        );
    }

    public function test_a_customer_written_subject_reaches_staff_as_text_never_as_markup(): void
    {
        // Filament renders a database body as sanitised HTML that keeps `style`, and mail lines are Markdown,
        // so a raw subject could cover the panel with a phishing link or plant a link and a beacon in the mail.
        $staff = $this->createUser('staff@example.com');
        $conversation = $this->createInAppConversation($this->createUser('ada@example.com'));
        $hostile = '<a href="https://evil.example" style="position:fixed">Sign in</a> [here](https://evil.example)';
        $conversation->forceFill(['subject' => $hostile])->save();
        $notification = new SupportConversationNeedsStaffNotification($conversation, 'opened');

        $database = $notification->toDatabase($staff);
        $mail = $notification->toMail($staff);

        $this->assertSame($hostile, $database['subject']);

        // Only Filament renders the body as HTML; without it the body is plain data and stays the raw text.
        if (class_exists(FilamentNotification::class)) {
            $this->assertStringNotContainsString('<a href', (string) $database['body']);
        } else {
            $this->assertSame($hostile, $database['body']);
        }

        $this->assertStringNotContainsString('evil.example', json_encode([
            $mail->introLines,
            $mail->outroLines,
        ], JSON_THROW_ON_ERROR));
    }

    public function test_the_listener_skips_push_when_onesignal_is_off(): void
    {
        config()->set('magic-starter.features', []);
        $staff = $this->createUser('staff@example.com');
        $conversation = $this->createInAppConversation($this->createUser('ada@example.com'));

        (new NotifyStaffOfSupportConversation(new SupportStaffRecipients))->handle(
            new SupportConversationNeedsStaff($conversation, 'customer_message'),
        );

        Notification::assertSentTo(
            $staff,
            SupportConversationNeedsStaffNotification::class,
            fn (SupportConversationNeedsStaffNotification $notification, array $channels): bool => $channels === [
                'database',
                'mail',
                'broadcast',
            ],
        );
    }

    public function test_the_in_app_rows_are_broadcast_live_in_the_shape_the_api_lists_them(): void
    {
        // magic_notifications' bell listens for `notification.created` and reads
        // the frame as a `GET /notifications` row; without the broadcast the bell
        // stayed stale until a reload, since a live socket stands the poll down.
        $staff = $this->createUser('staff@example.com');
        $opener = $this->createUser('ada@example.com');
        $conversation = $this->createInAppConversation($opener);
        $message = $conversation->messages()->create([
            'sender_type' => SupportSenderType::STAFF,
            'kind' => SupportMessageKind::MESSAGE,
            'body' => 'Secret reply text.',
        ]);

        $reply = new SupportReplyNotification($message);
        $alert = new SupportConversationNeedsStaffNotification($conversation, 'new');

        foreach ([
            [$reply, $opener, 'SupportReplyNotification', $reply->toArray($opener)],
            [$alert, $staff, 'SupportConversationNeedsStaffNotification', $alert->toDatabase($staff)],
        ] as [$notification, $notifiable, $type, $row]) {
            $notification->id = (string) Str::uuid();
            $frame = $notification->toBroadcast($notifiable)->data;

            $this->assertSame('notification.created', $notification->broadcastAs());
            $this->assertSame($notification->id, $frame['id']);
            $this->assertSame($type, $frame['type']);
            $this->assertSame($row, $frame['data']);
            $this->assertNull($frame['read_at']);
            $this->assertIsString($frame['created_at']);
            $this->assertStringNotContainsString('Secret reply text.', (string) json_encode($frame));
        }
    }

    private function createUser(string $email, bool $verified = true, bool $guest = false): ConcreteUser
    {
        return ConcreteUser::create([
            'name' => strstr($email, '@', true),
            'email' => $email,
            'password' => 'secret',
            'email_verified_at' => $verified ? now() : null,
            'is_guest' => $guest,
        ]);
    }

    private function createInAppConversation(ConcreteUser $opener): SupportConversation
    {
        return SupportConversation::create([
            'user_id' => $opener->getKey(),
            'email' => $opener->getAttribute('email'),
            'subject' => 'Checks fail',
            'source' => SupportConversationSource::IN_APP,
            'waiting_since' => now(),
        ]);
    }

    private function createPublicConversation(): SupportConversation
    {
        return SupportConversation::create([
            'email' => 'visitor@example.com',
            'name' => 'Visitor',
            'subject' => 'Billing question',
            'source' => SupportConversationSource::PUBLIC_FORM,
            'waiting_since' => now(),
        ]);
    }
}
