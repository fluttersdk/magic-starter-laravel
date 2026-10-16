<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use FlutterSdk\MagicStarter\Actions\OpenSupportConversation;
use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Events\SupportConversationNeedsStaff;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Notifications\SupportTicketCreatedNotification;
use FlutterSdk\MagicStarter\Support\SupportThreadToken;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteAdminUser;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/**
 * Locks the opt-in mail that confirms a new support conversation: off by default, sent only for a row the in-app
 * or public door just created, to a visitor with a fresh thread link and package copy alone, and to an account
 * holder only when their address is verified. A guest is never mailed, whatever address a profile edit gave it, and a
 * queue that refuses the push after the commit never fails the request that stored the conversation.
 */
class SupportTicketCreatedMailTest extends TestCase
{
    private const THREAD_URL = 'https://app.test/support/thread/{token}';

    private const HOSTILE = '[Verify](https://evil.example)';

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

        config()->set('magic-starter.frontend_url', 'https://app.test');
        config()->set('magic-starter.support.public.thread_url', self::THREAD_URL);
        config()->set('magic-starter.support.mail.ticket_created', true);

        Notification::fake();
        Event::fake([
            SupportConversationNeedsStaff::class,
        ]);
    }

    public function test_nothing_is_mailed_with_the_flag_off(): void
    {
        config()->set('magic-starter.support.mail.ticket_created', false);
        $user = $this->createUser(ConcreteUser::class, 'ada@example.com');

        $this->action()->openPublic($this->publicInput());
        $this->action()->open($user, $this->inAppInput());

        Notification::assertNothingSent();
    }

    public function test_a_published_config_without_the_mail_keys_mails_nothing(): void
    {
        config()->set('magic-starter.support.mail', null);

        $this->action()->openPublic($this->publicInput());

        Notification::assertNothingSent();
    }

    public function test_a_public_open_mails_one_link_that_opens_the_conversation(): void
    {
        $conversation = $this->action()->openPublic($this->publicInput());

        $link = null;
        Notification::assertSentOnDemand(
            SupportTicketCreatedNotification::class,
            function (
                SupportTicketCreatedNotification $notification,
                array $channels,
                AnonymousNotifiable $notifiable,
            ) use (&$link): bool {
                $link = $notification->toMail($notifiable)->actionUrl;

                return $channels === ['mail']
                    && $notifiable->routes === ['mail' => 'visitor@example.com'];
            },
        );
        Notification::assertSentOnDemandTimes(SupportTicketCreatedNotification::class, 1);

        $this->assertIsString($link);
        $this->assertStringStartsWith('https://app.test/support/thread/', $link);
        $token = rawurldecode(substr($link, strlen('https://app.test/support/thread/')));
        $this->assertSame($conversation->getKey(), SupportThreadToken::resolve($token)?->getKey());
    }

    public function test_the_visitor_mail_carries_no_submitter_text(): void
    {
        $conversation = $this->action()->openPublic([
            ...$this->publicInput(),
            'subject' => self::HOSTILE,
            'name' => self::HOSTILE,
            'body' => self::HOSTILE,
        ]);

        $mail = null;
        Notification::assertSentOnDemand(
            SupportTicketCreatedNotification::class,
            function (
                SupportTicketCreatedNotification $notification,
                array $channels,
                AnonymousNotifiable $notifiable,
            ) use (&$mail): bool {
                $mail = $notification->toMail($notifiable);

                return true;
            },
        );

        $this->assertNotNull($mail);
        $html = (string) $mail->render();
        $this->assertStringContainsString('https://app.test/support/thread/', $html);
        $this->assertStringNotContainsString('evil.example', $html);
        $this->assertStringNotContainsString('evil.example', (string) $mail->subject);
        $this->assertSame(
            'We received your support request #' . $conversation->number,
            $mail->subject,
        );
    }

    public function test_no_visitor_mail_without_a_thread_url(): void
    {
        config()->set('magic-starter.support.public.thread_url', null);

        $conversation = $this->action()->openPublic($this->publicInput());

        Notification::assertNothingSent();
        $this->assertNull($conversation->refresh()->thread_token_hash);
    }

    public function test_the_visitor_notification_is_queued_and_encrypted(): void
    {
        $this->action()->openPublic($this->publicInput());

        Notification::assertSentOnDemand(
            SupportTicketCreatedNotification::class,
            fn (SupportTicketCreatedNotification $notification): bool => $notification instanceof ShouldQueue
                && $notification instanceof ShouldBeEncrypted,
        );
    }

    public function test_an_unverified_account_gets_no_mail_and_a_verified_one_gets_the_in_app_link(): void
    {
        $unverified = $this->createUser(ConcreteAdminUser::class, 'new@example.com', verified: false);
        $verified = $this->createUser(ConcreteAdminUser::class, 'ada@example.com');

        $this->action()->open($unverified, $this->inAppInput());
        $conversation = $this->action()->open($verified, $this->inAppInput());

        Notification::assertNotSentTo($unverified, SupportTicketCreatedNotification::class);
        Notification::assertSentTo(
            $verified,
            SupportTicketCreatedNotification::class,
            fn (SupportTicketCreatedNotification $notification, array $channels): bool => $channels === ['mail']
                && $notification->toMail($verified)->actionUrl === 'https://app.test/support/' . $conversation->getKey(),
        );
        Notification::assertSentTimes(SupportTicketCreatedNotification::class, 1);
    }

    public function test_an_account_without_email_verification_is_mailed(): void
    {
        $user = $this->createUser(ConcreteUser::class, 'ada@example.com', verified: false);

        $this->action()->open($user, $this->inAppInput());

        Notification::assertSentTo($user, SupportTicketCreatedNotification::class);
    }

    public function test_a_guest_with_an_address_is_never_mailed(): void
    {
        foreach ([
            ConcreteUser::class,
            ConcreteAdminUser::class,
        ] as $model) {
            $guest = $model::query()->create([
                'name' => 'Guest',
                'email' => 'guest-' . class_basename($model) . '@example.com',
                'password' => 'secret',
                'is_guest' => true,
                'email_verified_at' => null,
            ]);

            $this->action()->open($guest, $this->inAppInput());
        }

        Notification::assertNothingSent();
    }

    public function test_a_queue_that_refuses_the_account_holder_mail_does_not_fail_the_request(): void
    {
        $user = $this->createUser(ConcreteUser::class, 'ada@example.com');
        Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('queue down'));

        $conversation = $this->action()->open($user, $this->inAppInput());

        $this->assertTrue($conversation->exists);
        $this->assertSame(1, SupportConversation::query()->count());
    }

    public function test_a_queue_that_refuses_the_visitor_mail_does_not_fail_the_request(): void
    {
        Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('queue down'));

        $conversation = $this->action()->openPublic($this->publicInput());

        $this->assertTrue($conversation->exists);
        $this->assertSame(1, SupportConversation::query()->count());
    }

    public function test_a_contact_sales_continuation_mails_nothing(): void
    {
        $user = $this->createUser(ConcreteUser::class, 'ada@example.com');
        $input = [
            'source' => SupportConversationSource::CONTACT_SALES->value,
            'body' => 'We need fifty seats.',
        ];

        $first = $this->action()->open($user, $input);
        Notification::assertSentToTimes($user, SupportTicketCreatedNotification::class, 1);

        $second = $this->action()->open($user, $input);

        $this->assertSame($first->getKey(), $second->getKey());
        Notification::assertSentToTimes($user, SupportTicketCreatedNotification::class, 1);
    }

    public function test_a_feedback_conversation_mails_nothing(): void
    {
        $user = $this->createUser(ConcreteUser::class, 'ada@example.com');

        [, $continued] = $this->action()->continueOrCreate(
            $user,
            null,
            [
                'user_id' => $user->getKey(),
                'email' => 'ada@example.com',
                'subject' => 'Feedback: 3/10',
                'source' => SupportConversationSource::FEEDBACK,
            ],
            'Too slow.',
            [],
            SupportConversationSource::FEEDBACK,
        );

        $this->assertFalse($continued);
        $this->assertSame(1, SupportConversation::query()->count());
        Notification::assertNothingSent();
    }

    public function test_a_turkish_open_mails_the_turkish_subject(): void
    {
        $this->app->setLocale('tr');

        $conversation = $this->action()->openPublic($this->publicInput());

        Notification::assertSentOnDemand(
            SupportTicketCreatedNotification::class,
            fn (
                SupportTicketCreatedNotification $notification,
                array $channels,
                AnonymousNotifiable $notifiable,
            ): bool => $notification->locale === 'tr'
                && $notification->toMail($notifiable)->subject
                    === '#' . $conversation->number . ' numaralı destek talebinizi aldık',
        );
    }

    private function action(): OpenSupportConversation
    {
        return app(OpenSupportConversation::class);
    }

    /**
     * @return array<string, string>
     */
    private function publicInput(): array
    {
        return [
            'email' => 'visitor@example.com',
            'name' => 'Visitor',
            'subject' => 'Billing question',
            'body' => 'How do I change my plan?',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function inAppInput(): array
    {
        return [
            'topic' => 'technical',
            'subject' => 'Checks fail',
            'body' => 'My checks fail since this morning.',
        ];
    }

    /**
     * @param  class-string<ConcreteUser>  $model
     */
    private function createUser(string $model, string $email, bool $verified = true): ConcreteUser
    {
        return $model::query()->create([
            'name' => strstr($email, '@', true),
            'email' => $email,
            'password' => 'secret',
            'email_verified_at' => $verified ? now() : null,
        ]);
    }
}
