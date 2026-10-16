<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use FlutterSdk\MagicStarter\Actions\ChangeSupportConversation;
use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Enums\SupportConversationStatus;
use FlutterSdk\MagicStarter\Enums\SupportCsatRating;
use FlutterSdk\MagicStarter\Events\SupportConversationUpdated;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Notifications\SupportCsatInvitationNotification;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteAdminUser;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/**
 * Locks the opt-in mail that asks an account holder to rate a resolved conversation: once per transition to
 * resolved, after the change commits, never to a visitor, never for a rated resolution and never without the
 * `support-csat` feature. A guest is never mailed, a rating opens a new window when the conversation is reopened and
 * resolved again, and a queue that refuses the push never fails the staff change that resolved the conversation.
 */
class SupportCsatInvitationMailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'create_users_table.php',
            'add_guest_and_phone_fields_to_users_table.php',
            'create_support_conversations_table.php',
            'create_support_messages_table.php',
            'add_ai_columns_to_support_tables.php',
            'add_feedback_columns_to_support_conversations_table.php',
        ] as $file) {
            $this->artisan('migrate', [
                '--path' => __DIR__ . '/../../database/migrations/' . $file,
                '--realpath' => true,
            ]);
        }

        config()->set('magic-starter.frontend_url', 'https://app.test');
        config()->set('magic-starter.features', [
            Features::support(),
            Features::supportCsat(),
        ]);
        config()->set('magic-starter.support.mail.csat_invitation', true);

        Notification::fake();
        Event::fake([
            SupportConversationUpdated::class,
        ]);
    }

    public function test_resolving_mails_the_account_holder_one_invitation_to_the_in_app_thread(): void
    {
        $opener = $this->createUser(ConcreteUser::class, 'ada@example.com');
        $conversation = $this->createConversation($opener);

        $this->resolve($conversation);

        Notification::assertSentTo(
            $opener,
            SupportCsatInvitationNotification::class,
            fn (SupportCsatInvitationNotification $notification, array $channels): bool => $channels === ['mail']
                && $notification->toMail($opener)->actionUrl === 'https://app.test/support/' . $conversation->getKey()
                && $notification->toMail($opener)->subject === 'How did we do with request #' . $conversation->number . '?',
        );
        Notification::assertSentTimes(SupportCsatInvitationNotification::class, 1);
    }

    public function test_a_change_that_is_not_a_transition_to_resolved_mails_nothing(): void
    {
        $conversation = $this->createConversation($this->createUser(ConcreteUser::class, 'ada@example.com'));
        $action = app(ChangeSupportConversation::class);

        $this->resolve($conversation);
        $action->change($this->staff(), $conversation, [
            'status' => SupportConversationStatus::RESOLVED->value,
        ]);
        $action->change($this->staff(), $conversation, [
            'priority' => 'high',
        ]);

        Notification::assertSentTimes(SupportCsatInvitationNotification::class, 1);
    }

    public function test_the_invitation_waits_for_the_outer_transaction_and_dies_with_its_rollback(): void
    {
        $conversation = $this->createConversation($this->createUser(ConcreteUser::class, 'ada@example.com'));

        DB::beginTransaction();
        $this->resolve($conversation);
        Notification::assertNothingSent();
        DB::rollBack();

        Notification::assertNothingSent();
    }

    public function test_a_resolution_rated_at_the_moment_it_was_made_mails_nothing(): void
    {
        $this->freezeTime();
        $conversation = $this->createConversation($this->createUser(ConcreteUser::class, 'ada@example.com'));
        $conversation->forceFill([
            'csat_rating' => SupportCsatRating::UP,
            'csat_rated_at' => now(),
        ])->save();

        $this->resolve($conversation);

        Notification::assertNothingSent();
    }

    public function test_a_resolution_after_a_rated_one_is_invited_once(): void
    {
        $conversation = $this->createConversation($this->createUser(ConcreteUser::class, 'ada@example.com'));
        $conversation->forceFill([
            'status' => SupportConversationStatus::RESOLVED,
            'resolved_at' => now(),
            'csat_rating' => SupportCsatRating::UP,
            'csat_rated_at' => now()->addMinute(),
        ])->save();
        $this->travel(1)->hour();
        $action = app(ChangeSupportConversation::class);

        $action->change($this->staff(), $conversation, [
            'status' => SupportConversationStatus::OPEN->value,
        ]);
        $this->travel(1)->hour();
        $this->resolve($conversation);

        Notification::assertSentTimes(SupportCsatInvitationNotification::class, 1);
    }

    public function test_a_guest_with_an_address_gets_no_invitation(): void
    {
        MagicStarter::useUserModel(ConcreteAdminUser::class);

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

            $this->resolve($this->createConversation($guest));
        }

        Notification::assertNothingSent();
    }

    public function test_a_queue_that_refuses_the_invitation_does_not_fail_the_resolution(): void
    {
        $conversation = $this->createConversation($this->createUser(ConcreteUser::class, 'ada@example.com'));
        Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('queue down'));

        $this->resolve($conversation);

        $this->assertSame(SupportConversationStatus::RESOLVED, $conversation->refresh()->status);
    }

    public function test_a_visitor_conversation_mails_nothing(): void
    {
        $conversation = SupportConversation::query()->create([
            'email' => 'visitor@example.com',
            'name' => 'Visitor',
            'subject' => 'Billing question',
            'source' => SupportConversationSource::PUBLIC_FORM,
            'waiting_since' => now(),
        ]);

        $this->resolve($conversation);

        Notification::assertNothingSent();
    }

    public function test_nothing_is_mailed_without_the_csat_feature(): void
    {
        config()->set('magic-starter.features', [
            Features::support(),
        ]);
        $conversation = $this->createConversation($this->createUser(ConcreteUser::class, 'ada@example.com'));

        $this->resolve($conversation);

        Notification::assertNothingSent();
    }

    public function test_nothing_is_mailed_with_the_flag_off(): void
    {
        config()->set('magic-starter.support.mail.csat_invitation', false);
        $conversation = $this->createConversation($this->createUser(ConcreteUser::class, 'ada@example.com'));

        $this->resolve($conversation);

        Notification::assertNothingSent();
    }

    public function test_an_unverified_account_gets_no_invitation_and_a_verified_one_does(): void
    {
        // The opener is read through the conversation's relation, which resolves the configured user model.
        MagicStarter::useUserModel(ConcreteAdminUser::class);
        $unverified = $this->createUser(ConcreteAdminUser::class, 'new@example.com', verified: false);
        $verified = $this->createUser(ConcreteAdminUser::class, 'ada@example.com');

        $this->resolve($this->createConversation($unverified));
        $this->resolve($this->createConversation($verified));

        Notification::assertNotSentTo($unverified, SupportCsatInvitationNotification::class);
        Notification::assertSentTo($verified, SupportCsatInvitationNotification::class);
    }

    public function test_the_turkish_invitation_has_the_turkish_subject(): void
    {
        $opener = $this->createUser(ConcreteUser::class, 'ada@example.com');
        $conversation = $this->createConversation($opener);
        $this->resolve($conversation);
        $this->app->setLocale('tr');

        Notification::assertSentTo(
            $opener,
            SupportCsatInvitationNotification::class,
            fn (SupportCsatInvitationNotification $notification): bool => $notification->toMail($opener)->subject
                === '#' . $conversation->number . ' numaralı talebinizi değerlendirin',
        );
    }

    private function resolve(SupportConversation $conversation): void
    {
        app(ChangeSupportConversation::class)->change($this->staff(), $conversation, [
            'status' => SupportConversationStatus::RESOLVED->value,
        ]);
    }

    private function staff(): ConcreteUser
    {
        return ConcreteUser::query()->firstOrCreate(
            [
                'email' => 'staff@example.com',
            ],
            [
                'name' => 'staff',
                'password' => 'secret',
                'email_verified_at' => now(),
            ],
        );
    }

    private function createConversation(ConcreteUser $opener): SupportConversation
    {
        return SupportConversation::query()->create([
            'user_id' => $opener->getKey(),
            'email' => $opener->getAttribute('email'),
            'subject' => 'Checks fail',
            'source' => SupportConversationSource::IN_APP,
            'waiting_since' => now(),
        ]);
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
