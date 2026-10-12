<?php

namespace FlutterSdk\MagicStarter\Tests\Filament\SupportConversations;

use Filament\Notifications\Notification as FilamentNotification;
use Filament\Panel;
use FlutterSdk\MagicStarter\Actions\ChangeSupportConversation;
use FlutterSdk\MagicStarter\Actions\ReplyToSupportConversation;
use FlutterSdk\MagicStarter\Contracts\ChangesSupportConversations;
use FlutterSdk\MagicStarter\Contracts\RepliesToSupport;
use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Enums\SupportConversationStatus;
use FlutterSdk\MagicStarter\Enums\SupportMessageKind;
use FlutterSdk\MagicStarter\Enums\SupportPriority;
use FlutterSdk\MagicStarter\Enums\SupportSenderType;
use FlutterSdk\MagicStarter\Events\AdminActionPerformed;
use FlutterSdk\MagicStarter\Events\SupportConversationNeedsStaff;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Filament\MagicStarterPlugin;
use FlutterSdk\MagicStarter\Filament\Resources\SupportConversations\Pages\ListSupportConversations;
use FlutterSdk\MagicStarter\Filament\Resources\SupportConversations\Pages\ViewSupportConversation;
use FlutterSdk\MagicStarter\Filament\Resources\SupportConversations\SupportConversationResource;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Models\SupportMessage;
use FlutterSdk\MagicStarter\Notifications\SupportConversationNeedsStaffNotification;
use FlutterSdk\MagicStarter\Notifications\SupportReplyNotification;
use FlutterSdk\MagicStarter\Tests\Filament\FilamentTestCase;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteAdminUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * The staff inbox: the list, the thread page and the four actions that answer
 * a conversation, each one a package contract run through the panel.
 *
 * The feature is on while the application boots, because a resource's routes
 * are registered at that moment; the "feature off" case builds its own panel
 * the way MiscResourcesTest does.
 */
class SupportConversationResourceTest extends FilamentTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('magic-starter.features', [Features::support()]);
    }

    public function test_a_user_outside_the_panel_gate_cannot_reach_the_resource(): void
    {
        $staff = $this->staff();
        $member = $this->user('member@example.com');

        // The 200 is the control: without it the 403 could come from a route that stopped existing.
        $this->actingAs($staff)->get('/admin/support-conversations')->assertOk();
        $this->actingAs($member)->get('/admin/support-conversations')->assertForbidden();
    }

    public function test_the_resource_is_mounted_only_with_the_support_feature(): void
    {
        config()->set('magic-starter.features', []);
        $off = Panel::make()->id('support-off')->plugin(MagicStarterPlugin::make());

        config()->set('magic-starter.features', [Features::support()]);
        $on = Panel::make()->id('support-on')->plugin(MagicStarterPlugin::make());

        $this->assertNotContains(SupportConversationResource::class, $off->getResources());
        $this->assertContains(SupportConversationResource::class, $on->getResources());
    }

    public function test_the_provider_binds_the_staff_contracts_and_listens_for_the_staff_alert(): void
    {
        $this->assertInstanceOf(ReplyToSupportConversation::class, app(RepliesToSupport::class));
        $this->assertInstanceOf(ChangeSupportConversation::class, app(ChangesSupportConversations::class));

        Notification::fake();
        $staff = $this->staff();
        $conversation = $this->conversation($this->user('opener@example.com'));

        event(new SupportConversationNeedsStaff($conversation, 'opened'));

        Notification::assertSentToTimes($staff, SupportConversationNeedsStaffNotification::class, 1);
    }

    public function test_the_list_hides_resolved_conversations_until_the_filter_is_cleared(): void
    {
        $opener = $this->user('opener@example.com');
        $open = $this->conversation($opener, ['subject' => 'Still open']);
        $snoozed = $this->conversation($opener, [
            'subject' => 'Later',
            'status' => SupportConversationStatus::SNOOZED,
        ]);
        $resolved = $this->conversation($opener, [
            'subject' => 'All done',
            'status' => SupportConversationStatus::RESOLVED,
        ]);

        Livewire::actingAs($this->staff())->test(ListSupportConversations::class)
            ->assertCanSeeTableRecords([$open, $snoozed])
            ->assertCanNotSeeTableRecords([$resolved])
            ->removeTableFilter('status')
            ->assertCanSeeTableRecords([$open, $snoozed, $resolved]);
    }

    public function test_the_list_filters_by_source_and_assignee(): void
    {
        $opener = $this->user('opener@example.com');
        $staff = $this->staff();
        $inApp = $this->conversation($opener, ['assignee_id' => $staff->getKey()]);
        $sales = $this->conversation($opener, ['source' => SupportConversationSource::CONTACT_SALES]);

        Livewire::actingAs($staff)->test(ListSupportConversations::class)
            ->filterTable('source', SupportConversationSource::CONTACT_SALES->value)
            ->assertCanSeeTableRecords([$sales])
            ->assertCanNotSeeTableRecords([$inApp])
            ->removeTableFilter('source')
            ->filterTable('assignee_id', $staff->getKey())
            ->assertCanSeeTableRecords([$inApp])
            ->assertCanNotSeeTableRecords([$sales]);
    }

    public function test_the_navigation_badge_counts_the_open_conversations(): void
    {
        $opener = $this->user('opener@example.com');

        $this->assertNull(SupportConversationResource::getNavigationBadge());

        $this->conversation($opener);
        $this->conversation($opener);
        $this->conversation($opener, ['status' => SupportConversationStatus::RESOLVED]);
        $this->conversation($opener, ['status' => SupportConversationStatus::SNOOZED]);

        $this->assertSame('2', SupportConversationResource::getNavigationBadge());
    }

    public function test_viewing_a_conversation_stamps_staff_seen_at(): void
    {
        $conversation = $this->conversation($this->user('opener@example.com'));
        $this->assertNull($conversation->staff_seen_at);

        Carbon::setTestNow('2026-10-12 10:00:00');
        Livewire::actingAs($this->staff())->test(ViewSupportConversation::class, ['record' => $conversation->getKey()]);

        $this->assertTrue($conversation->refresh()->staff_seen_at?->equalTo(Carbon::now()) ?? false);
    }

    public function test_the_thread_escapes_every_body_and_sets_a_staff_note_apart(): void
    {
        $conversation = $this->conversation($this->user('opener@example.com'));
        $this->message(
            $conversation,
            SupportSenderType::CUSTOMER,
            SupportMessageKind::MESSAGE,
            '<script>alert(1)</script>',
        );
        $this->message(
            $conversation,
            SupportSenderType::STAFF,
            SupportMessageKind::NOTE,
            'Looks like the <b>region</b>',
        );

        Livewire::actingAs($this->staff())->test(ViewSupportConversation::class, ['record' => $conversation->getKey()])
            ->assertSeeHtml('&lt;script&gt;alert(1)&lt;/script&gt;')
            ->assertDontSeeHtml('<script>alert(1)</script>')
            ->assertSeeHtml('Looks like the &lt;b&gt;region&lt;/b&gt;')
            ->assertDontSeeHtml('<b>region</b>')
            ->assertSee('Internal note');
    }

    public function test_reply_stores_a_staff_message_through_the_contract(): void
    {
        Event::fake([AdminActionPerformed::class]);
        Notification::fake();
        $staff = $this->staff();
        $opener = $this->user('opener@example.com');
        $conversation = $this->conversation($opener, ['waiting_since' => now()]);

        Livewire::actingAs($staff)->test(ViewSupportConversation::class, ['record' => $conversation->getKey()])
            ->callAction('reply', ['body' => 'We are on it.'])
            ->assertHasNoActionErrors();

        $message = SupportMessage::query()->where('conversation_id', $conversation->getKey())->sole();

        $this->assertSame('We are on it.', $message->body);
        $this->assertSame(SupportMessageKind::MESSAGE, $message->kind);
        $this->assertSame(SupportSenderType::STAFF, $message->sender_type);
        $this->assertEquals($staff->getKey(), $message->sender_id);
        $this->assertNull($conversation->refresh()->waiting_since);
        Notification::assertSentTo($opener, SupportReplyNotification::class);
        Event::assertDispatched(
            AdminActionPerformed::class,
            static fn (AdminActionPerformed $event): bool => $event->action === 'support.replied'
                && $event->actor->is($staff)
                && $event->subject?->is($conversation) === true,
        );
    }

    public function test_reply_calls_the_bound_contract_with_the_acting_staff_user(): void
    {
        $spy = new class implements RepliesToSupport
        {
            /**
             * @var list<array{0: mixed, 1: mixed, 2: string, 3: bool}>
             */
            public array $calls = [];

            public function reply(
                Authenticatable $staff,
                SupportConversation $c,
                string $body,
                bool $note,
            ): SupportMessage {
                $this->calls[] = [$staff->getAuthIdentifier(), $c->getKey(), $body, $note];

                return new SupportMessage;
            }
        };
        $this->app->instance(RepliesToSupport::class, $spy);
        $staff = $this->staff();
        $conversation = $this->conversation($this->user('opener@example.com'));

        Livewire::actingAs($staff)->test(ViewSupportConversation::class, ['record' => $conversation->getKey()])
            ->callAction('reply', ['body' => 'Hello']);

        $this->assertSame([[$staff->getKey(), $conversation->getKey(), 'Hello', false]], $spy->calls);
    }

    public function test_a_reply_the_contract_refuses_reads_as_a_notification_and_stores_nothing(): void
    {
        Event::fake([AdminActionPerformed::class]);
        config()->set('magic-starter.support.message_max_length', 5);
        $conversation = $this->conversation($this->user('opener@example.com'));

        Livewire::actingAs($this->staff())->test(ViewSupportConversation::class, ['record' => $conversation->getKey()])
            ->callAction('reply', ['body' => 'far too long']);

        FilamentNotification::assertNotified(
            FilamentNotification::make()->danger()->title(
                (string) __('validation.max.string', ['attribute' => 'body', 'max' => 5]),
            ),
        );
        $this->assertSame(0, SupportMessage::query()->count());
        Event::assertNotDispatched(AdminActionPerformed::class);
    }

    public function test_add_note_stores_a_note_and_tells_nobody(): void
    {
        Event::fake([AdminActionPerformed::class]);
        Notification::fake();
        $staff = $this->staff();
        $conversation = $this->conversation($this->user('opener@example.com'), ['waiting_since' => now()]);

        Livewire::actingAs($staff)->test(ViewSupportConversation::class, ['record' => $conversation->getKey()])
            ->callAction('add_note', ['body' => 'Check the region first.'])
            ->assertHasNoActionErrors();

        $message = SupportMessage::query()->where('conversation_id', $conversation->getKey())->sole();

        $this->assertSame(SupportMessageKind::NOTE, $message->kind);
        $this->assertSame('Check the region first.', $message->body);
        $this->assertNotNull($conversation->refresh()->waiting_since);
        $this->assertSame(0, $conversation->messages()->customerVisible()->count());
        Notification::assertNothingSent();
        Event::assertDispatched(
            AdminActionPerformed::class,
            static fn (AdminActionPerformed $event): bool => $event->action === 'support.note_added',
        );
    }

    public function test_assign_offers_staff_only_and_sets_the_assignee(): void
    {
        Event::fake([AdminActionPerformed::class]);
        $staff = $this->staff();
        $conversation = $this->conversation($this->user('opener@example.com'));

        Livewire::actingAs($staff)->test(ViewSupportConversation::class, ['record' => $conversation->getKey()])
            ->callAction('assign', ['assignee_id' => $staff->getKey()])
            ->assertHasNoActionErrors();

        $this->assertEquals($staff->getKey(), $conversation->refresh()->assignee_id);
        Event::assertDispatched(
            AdminActionPerformed::class,
            static fn (AdminActionPerformed $event): bool => $event->action === 'support.assigned',
        );
    }

    public function test_assigning_a_non_staff_user_is_refused_and_changes_nothing(): void
    {
        Event::fake([AdminActionPerformed::class]);
        $conversation = $this->conversation($this->user('opener@example.com'));
        $outsider = $this->user('outsider@example.com');

        Livewire::actingAs($this->staff())->test(ViewSupportConversation::class, ['record' => $conversation->getKey()])
            ->callAction('assign', ['assignee_id' => $outsider->getKey()])
            ->assertHasActionErrors(['assignee_id']);

        $this->assertNull($conversation->refresh()->assignee_id);
        Event::assertNotDispatched(AdminActionPerformed::class);
    }

    public function test_a_contract_refusal_of_the_assignee_shows_as_a_danger_notification(): void
    {
        Event::fake([AdminActionPerformed::class]);
        $refusing = new class implements ChangesSupportConversations
        {
            public function change(
                Authenticatable $staff,
                SupportConversation $c,
                array $changes,
            ): SupportConversation {
                throw ValidationException::withMessages([
                    'assignee_id' => 'The assignee must be a support staff member.',
                ]);
            }
        };
        $this->app->instance(ChangesSupportConversations::class, $refusing);
        $staff = $this->staff();
        $conversation = $this->conversation($this->user('opener@example.com'));

        Livewire::actingAs($staff)->test(ViewSupportConversation::class, ['record' => $conversation->getKey()])
            ->callAction('assign', ['assignee_id' => $staff->getKey()]);

        FilamentNotification::assertNotified(
            FilamentNotification::make()->danger()->title('The assignee must be a support staff member.'),
        );
        Event::assertNotDispatched(AdminActionPerformed::class);
    }

    public function test_change_sets_status_and_priority_and_resolving_stamps_the_conversation(): void
    {
        Event::fake([AdminActionPerformed::class]);
        $conversation = $this->conversation($this->user('opener@example.com'));

        Livewire::actingAs($this->staff())->test(ViewSupportConversation::class, ['record' => $conversation->getKey()])
            ->callAction('change', [
                'status' => SupportConversationStatus::RESOLVED->value,
                'priority' => SupportPriority::HIGH->value,
            ])
            ->assertHasNoActionErrors();

        $conversation->refresh();

        $this->assertSame(SupportConversationStatus::RESOLVED, $conversation->status);
        $this->assertSame(SupportPriority::HIGH, $conversation->priority);
        $this->assertNotNull($conversation->resolved_at);
        Event::assertDispatched(
            AdminActionPerformed::class,
            static fn (AdminActionPerformed $event): bool => $event->action === 'support.changed',
        );
    }

    public function test_a_host_group_file_cannot_hijack_a_label_of_the_inbox(): void
    {
        $staff = $this->staff();
        $conversation = $this->conversation(null, [
            'name' => 'Ada',
            'email' => 'ada@example.com',
        ]);
        $this->message($conversation, SupportSenderType::CUSTOMER, SupportMessageKind::MESSAGE, 'Hello');
        $this->message($conversation, SupportSenderType::STAFF, SupportMessageKind::NOTE, 'Check the region');

        // A dot-less key with no JSON line is a group name to the translator, so an application that owns a
        // `lang/en/status.php` used to turn the Status label into an array and break the page.
        $hostLang = sys_get_temp_dir() . '/magic-starter-host-lang-' . uniqid();
        mkdir($hostLang . '/en', 0777, true);

        foreach ($this->hostGroups() as $group) {
            file_put_contents("{$hostLang}/en/{$group}.php", "<?php return ['hijacked' => 'array'];\n");
        }

        try {
            app('translator')->addPath($hostLang);

            $this->actingAs($staff)->get('/admin/support-conversations')->assertOk()->assertSee('Support inbox');
            $this->actingAs($staff)->get('/admin/support-conversations/' . $conversation->getKey())
                ->assertOk()
                ->assertSee('Internal note')
                ->assertSee('Customer');
        } finally {
            foreach ($this->hostGroups() as $group) {
                @unlink("{$hostLang}/en/{$group}.php");
            }

            @rmdir($hostLang . '/en');
            @rmdir($hostLang);
        }
    }

    public function test_the_turkish_inbox_file_has_the_keys_of_the_english_one(): void
    {
        $english = require __DIR__ . '/../../../lang/en/admin_support.php';
        $turkish = require __DIR__ . '/../../../lang/tr/admin_support.php';

        $this->assertSame(array_keys(Arr::dot($english)), array_keys(Arr::dot($turkish)));
    }

    /**
     * Group names an application's own lang directory is likely to carry, each one a former bare label key.
     *
     * @return list<string>
     */
    private function hostGroups(): array
    {
        return [
            'status',
            'priority',
            'source',
            'assignee',
            'opener',
            'number',
            'subject',
            'team',
            'conversation',
            'conversations',
            'visitor',
            'customer',
            'staff',
            'activity',
            'system',
            'reply',
            'note',
            'open',
            'pending',
            'snoozed',
            'resolved',
            'low',
            'normal',
            'high',
            'urgent',
            'feedback',
        ];
    }

    private function staff(): ConcreteAdminUser
    {
        config()->set('magic-starter.admin.emails', ['staff@example.com']);

        return ConcreteAdminUser::query()->firstOrCreate(['email' => 'staff@example.com'], [
            'name' => 'Staff',
            'password' => 'secret',
            'email_verified_at' => Carbon::now(),
        ]);
    }

    private function user(string $email): ConcreteAdminUser
    {
        return ConcreteAdminUser::query()->create([
            'name' => strstr($email, '@', true),
            'email' => $email,
            'password' => 'secret',
            'email_verified_at' => Carbon::now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function conversation(?ConcreteAdminUser $opener, array $attributes = []): SupportConversation
    {
        return SupportConversation::query()->create([
            'user_id' => $opener?->getKey(),
            'email' => $opener?->email,
            'subject' => 'Checks fail',
            'source' => SupportConversationSource::IN_APP,
            'last_activity_at' => now(),
            ...$attributes,
        ]);
    }

    private function message(
        SupportConversation $conversation,
        SupportSenderType $sender,
        SupportMessageKind $kind,
        string $body,
    ): SupportMessage {
        return $conversation->messages()->create([
            'sender_type' => $sender,
            'kind' => $kind,
            'body' => $body,
        ]);
    }
}
