<?php

namespace FlutterSdk\MagicStarter\Tests\Filament\SupportConversations;

use Filament\Notifications\Notification as FilamentNotification;
use Filament\Panel;
use FlutterSdk\MagicStarter\Actions\ChangeSupportConversation;
use FlutterSdk\MagicStarter\Actions\ReplyToSupportConversation;
use FlutterSdk\MagicStarter\Audit\Audit;
use FlutterSdk\MagicStarter\Contracts\AssistsSupportStaff;
use FlutterSdk\MagicStarter\Contracts\ChangesSupportConversations;
use FlutterSdk\MagicStarter\Contracts\DescribesSupportCustomer;
use FlutterSdk\MagicStarter\Contracts\RepliesToSupport;
use FlutterSdk\MagicStarter\Enums\SupportAttachmentOrigin;
use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Enums\SupportConversationStatus;
use FlutterSdk\MagicStarter\Enums\SupportCsatRating;
use FlutterSdk\MagicStarter\Enums\SupportMessageKind;
use FlutterSdk\MagicStarter\Enums\SupportPriority;
use FlutterSdk\MagicStarter\Enums\SupportSenderType;
use FlutterSdk\MagicStarter\Events\AdminActionPerformed;
use FlutterSdk\MagicStarter\Events\SupportConversationNeedsStaff;
use FlutterSdk\MagicStarter\Events\SupportConversationUpdated;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Filament\MagicStarterPlugin;
use FlutterSdk\MagicStarter\Filament\Resources\SupportConversations\Pages\ListSupportConversations;
use FlutterSdk\MagicStarter\Filament\Resources\SupportConversations\Pages\ViewSupportConversation;
use FlutterSdk\MagicStarter\Filament\Resources\SupportConversations\SupportConversationResource;
use FlutterSdk\MagicStarter\Http\Resources\SupportMessageResource;
use FlutterSdk\MagicStarter\Jobs\PrepareSupportStaffAssist;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Models\SupportMessage;
use FlutterSdk\MagicStarter\Models\SupportMessageAttachment;
use FlutterSdk\MagicStarter\Notifications\SupportConversationNeedsStaffNotification;
use FlutterSdk\MagicStarter\Notifications\SupportReplyNotification;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportStaffAssist;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportStaffAssistCache;
use FlutterSdk\MagicStarter\Tests\Filament\FilamentTestCase;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteAdminUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use ReflectionMethod;
use RuntimeException;
use Throwable;

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

    public function test_the_list_shows_and_filters_by_topic(): void
    {
        // A derived subject no longer carries the topic, so the table does.
        $opener = $this->user('opener@example.com');
        $billing = $this->conversation($opener, ['topic' => 'billing']);
        $technical = $this->conversation($opener, ['topic' => 'technical']);

        Livewire::actingAs($this->staff())->test(ListSupportConversations::class)
            ->assertTableColumnFormattedStateSet('topic', (string) __('magic-starter::support.topics.billing'), $billing)
            ->filterTable('topic', 'technical')
            ->assertCanSeeTableRecords([$technical])
            ->assertCanNotSeeTableRecords([$billing]);
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
                ->assertSee('Priority');
        } finally {
            foreach ($this->hostGroups() as $group) {
                @unlink("{$hostLang}/en/{$group}.php");
            }

            @rmdir($hostLang . '/en');
            @rmdir($hostLang);
        }
    }

    public function test_a_bubble_body_is_flush_with_its_element_and_a_note_is_marked_internal(): void
    {
        $conversation = $this->conversation($this->user('opener@example.com'));
        $this->message($conversation, SupportSenderType::CUSTOMER, SupportMessageKind::MESSAGE, "Line one\nLine two");
        $this->message($conversation, SupportSenderType::STAFF, SupportMessageKind::NOTE, 'Offer the annual plan');

        $component = Livewire::actingAs($this->staff())
            ->test(ViewSupportConversation::class, ['record' => $conversation->getKey()]);

        preg_match_all('/class="msl-body"[^>]*>(.*?)<\/div>/s', $component->html(), $bodies);

        $this->assertSame(["Line one\nLine two", 'Offer the annual plan'], $bodies[1]);
        $component->assertSee('Internal note')->assertSee((string) __('magic-starter::admin_support.note_hidden'));
    }

    public function test_the_thread_marks_the_ai_and_draws_an_activity_line(): void
    {
        $conversation = $this->conversation($this->user('opener@example.com'));
        $ai = $this->message($conversation, SupportSenderType::AI, SupportMessageKind::MESSAGE, 'You are on Pro.');
        $ai->update(['meta' => ['confidence' => 0.82]]);
        $this->message($conversation, SupportSenderType::SYSTEM, SupportMessageKind::ACTIVITY, 'Handed off to staff');

        Livewire::actingAs($this->staff())->test(ViewSupportConversation::class, ['record' => $conversation->getKey()])
            ->assertSee('You are on Pro.')
            ->assertSee('confidence 0.82')
            ->assertSee('Handed off to staff');
    }

    public function test_a_message_a_linked_conversation_got_from_a_visitor_carries_a_chip_and_a_signed_in_one_does_not(): void
    {
        $opener = $this->user('opener@example.com');
        $linked = $this->conversation($opener);
        $linked->messages()->create([
            'sender_type' => SupportSenderType::CUSTOMER,
            'sender_id' => null,
            'kind' => SupportMessageKind::MESSAGE,
            'body' => 'Written before the account existed',
        ]);
        $linked->messages()->create([
            'sender_type' => SupportSenderType::CUSTOMER,
            'sender_id' => $opener->getKey(),
            'kind' => SupportMessageKind::MESSAGE,
            'body' => 'Written signed in',
        ]);
        $public = $this->conversation(null, ['email' => 'visitor@example.com']);
        $this->message($public, SupportSenderType::CUSTOMER, SupportMessageKind::MESSAGE, 'Still a visitor');
        $chip = (string) __('magic-starter::admin_support.authors.sent_as_visitor');

        [$visitor, $signedIn] = SupportConversationResource::threadItems($linked);
        [$stillVisitor] = SupportConversationResource::threadItems($public);

        $this->assertSame('Sent as a visitor', $chip);
        $this->assertSame($chip, $visitor['chip']);
        $this->assertNull($signedIn['chip']);
        $this->assertNull($stillVisitor['chip']);
        Livewire::actingAs($this->staff())->test(ViewSupportConversation::class, ['record' => $linked->getKey()])
            ->assertSee($chip);
    }

    public function test_the_panel_and_the_customer_json_name_the_ai_the_same_way_when_the_name_is_blank(): void
    {
        config()->set('magic-starter.support.ai.name', '');
        config()->set('app.name', 'Acme');
        $conversation = $this->conversation($this->user('opener@example.com'));
        $ai = $this->message($conversation, SupportSenderType::AI, SupportMessageKind::MESSAGE, 'You are on Pro.');
        $senderName = new ReflectionMethod(SupportConversationResource::class, 'senderName');

        $this->assertSame('Acme AI', SupportMessageResource::aiName());
        $this->assertSame(SupportMessageResource::aiName(), $senderName->invoke(null, $ai, $conversation, 'ai'));
    }

    public function test_the_details_panel_counts_the_ai_turns_once_per_render(): void
    {
        $conversation = $this->conversation($this->user('opener@example.com'));
        $this->message($conversation, SupportSenderType::AI, SupportMessageKind::MESSAGE, 'You are on Pro.');
        $staff = $this->staff();
        DB::enableQueryLog();

        Livewire::actingAs($staff)->test(ViewSupportConversation::class, ['record' => $conversation->getKey()])
            ->assertSee((string) SupportConversationResource::fieldLabel('ai_turns'));

        $counts = array_filter(
            DB::getQueryLog(),
            static fn (array $query): bool => str_contains($query['query'], 'count(*)')
                && str_contains($query['query'], 'support_messages')
                && str_contains($query['query'], 'sender_type'),
        );
        $this->assertCount(1, $counts);
    }

    public function test_the_thread_section_polls_without_calling_the_model(): void
    {
        $spy = $this->spyAssist();
        $this->app->instance(AssistsSupportStaff::class, $spy);
        $conversation = $this->conversation($this->user('opener@example.com'));

        Livewire::actingAs($this->staff())->test(ViewSupportConversation::class, ['record' => $conversation->getKey()])
            ->assertSeeHtml('wire:poll.10s');

        $this->assertSame(0, $spy->calls);
    }

    public function test_the_details_panel_names_the_topic_the_waiting_time_and_the_handoff(): void
    {
        $conversation = $this->conversation($this->user('opener@example.com'), [
            'topic' => 'sales',
            'ai_failures' => 2,
            'handoff_reason' => 'human_requested',
            'waiting_since' => now()->subMinutes(12),
        ]);

        Livewire::actingAs($this->staff())->test(ViewSupportConversation::class, ['record' => $conversation->getKey()])
            ->assertSee('Sales')
            ->assertSee('waiting 12m')
            ->assertSee((string) __('magic-starter::admin_support.enums.handoff_reason.human_requested'));
    }

    public function test_pending_is_not_a_status_staff_can_pick(): void
    {
        $conversation = $this->conversation($this->user('opener@example.com'));

        Livewire::actingAs($this->staff())->test(ViewSupportConversation::class, ['record' => $conversation->getKey()])
            ->callAction('change', [
                'status' => SupportConversationStatus::PENDING->value,
                'priority' => SupportPriority::HIGH->value,
            ])
            ->assertHasActionErrors(['status']);

        $this->assertSame(SupportConversationStatus::OPEN, $conversation->refresh()->status);
    }

    public function test_the_change_contract_refuses_pending_on_its_own(): void
    {
        $conversation = $this->conversation($this->user('opener@example.com'));

        $this->expectException(ValidationException::class);

        app(ChangesSupportConversations::class)->change($this->staff(), $conversation, [
            'status' => SupportConversationStatus::PENDING->value,
        ]);
    }

    public function test_the_ai_actions_are_absent_while_the_contract_is_unbound(): void
    {
        $conversation = $this->conversation($this->user('opener@example.com'));
        app(SupportStaffAssistCache::class)->put($conversation, new SupportStaffAssist('Hidden summary.', 'Draft.'));

        Livewire::actingAs($this->staff())->test(ViewSupportConversation::class, ['record' => $conversation->getKey()])
            ->assertActionHidden('draft_reply')
            ->assertActionHidden('summarize')
            ->assertDontSee('Hidden summary.');
    }

    public function test_the_summary_card_reads_the_cache_and_never_calls_the_model(): void
    {
        $spy = $this->spyAssist();
        $this->app->instance(AssistsSupportStaff::class, $spy);
        $conversation = $this->conversation($this->user('opener@example.com'));
        app(SupportStaffAssistCache::class)->put(
            $conversation,
            new SupportStaffAssist('Pro customer asks for SSO.', 'Draft'),
        );

        Livewire::actingAs($this->staff())->test(ViewSupportConversation::class, ['record' => $conversation->getKey()])
            ->assertSee('Pro customer asks for SSO.');

        $this->assertSame(0, $spy->calls);
    }

    public function test_draft_reply_opens_prefilled_from_the_cache_without_a_model_call(): void
    {
        Queue::fake();
        $spy = $this->spyAssist();
        $this->app->instance(AssistsSupportStaff::class, $spy);
        $conversation = $this->conversation($this->user('opener@example.com'));
        app(SupportStaffAssistCache::class)->put(
            $conversation,
            new SupportStaffAssist('Summary.', 'Happy to set up a call tomorrow.'),
        );

        Livewire::actingAs($this->staff())->test(ViewSupportConversation::class, ['record' => $conversation->getKey()])
            ->mountAction('draft_reply')
            ->assertActionDataSet(['body' => 'Happy to set up a call tomorrow.']);

        $this->assertSame(0, $spy->calls);
        Queue::assertNothingPushed();
    }

    public function test_draft_reply_with_an_empty_cache_queues_the_job_says_so_and_opens_no_form(): void
    {
        // An empty Draft reply form is Reply under another name; staff who want
        // to write it themselves have Reply.
        Queue::fake();
        $spy = $this->spyAssist(new RuntimeException('provider down'));
        $this->app->instance(AssistsSupportStaff::class, $spy);
        $conversation = $this->conversation($this->user('opener@example.com'));

        Livewire::actingAs($this->staff())->test(ViewSupportConversation::class, ['record' => $conversation->getKey()])
            ->mountAction('draft_reply')
            ->assertActionNotMounted('draft_reply');

        $this->assertSame(0, $spy->calls);
        Queue::assertPushed(
            PrepareSupportStaffAssist::class,
            static fn (PrepareSupportStaffAssist $job): bool => (string) $job->conversationId
                === (string) $conversation->getKey(),
        );
        FilamentNotification::assertNotified((string) __('magic-starter::admin_support.ai.draft_queued'));
    }

    public function test_a_draft_is_sent_only_after_staff_submit_the_reply_form(): void
    {
        Queue::fake();
        Notification::fake();
        $this->app->instance(AssistsSupportStaff::class, $this->spyAssist());
        $conversation = $this->conversation($this->user('opener@example.com'));
        app(SupportStaffAssistCache::class)->put($conversation, new SupportStaffAssist('Summary.', 'Draft text.'));

        Livewire::actingAs($this->staff())->test(ViewSupportConversation::class, ['record' => $conversation->getKey()])
            ->mountAction('draft_reply');

        $this->assertSame(0, SupportMessage::query()->count());

        Livewire::actingAs($this->staff())->test(ViewSupportConversation::class, ['record' => $conversation->getKey()])
            ->callAction('draft_reply', ['body' => 'Edited draft.']);

        $this->assertSame('Edited draft.', SupportMessage::query()->sole()->body);
    }

    public function test_summarize_queues_one_job_per_turn_and_notifies(): void
    {
        Queue::fake();
        $this->app->instance(AssistsSupportStaff::class, $this->spyAssist());
        $conversation = $this->conversation($this->user('opener@example.com'));

        Livewire::actingAs($this->staff())
            ->test(ViewSupportConversation::class, ['record' => $conversation->getKey()])
            ->callAction('summarize')
            ->callAction('summarize');

        Queue::assertPushed(PrepareSupportStaffAssist::class, 1);
        FilamentNotification::assertNotified((string) __('magic-starter::admin_support.ai.summarize_queued'));
    }

    public function test_the_list_filters_by_csat_rating(): void
    {
        $this->enable(Features::supportCsat());
        $opener = $this->user('opener@example.com');
        $up = $this->conversation($opener, ['csat_rating' => SupportCsatRating::UP]);
        $down = $this->conversation($opener, ['csat_rating' => SupportCsatRating::DOWN]);
        $unrated = $this->conversation($opener);

        Livewire::actingAs($this->staff())->test(ListSupportConversations::class)
            ->assertTableColumnExists('csat_rating')
            ->filterTable('csat', 'down')
            ->assertCanSeeTableRecords([$down])
            ->assertCanNotSeeTableRecords([$up, $unrated])
            ->filterTable('csat', 'up')
            ->assertCanSeeTableRecords([$up])
            ->assertCanNotSeeTableRecords([$down, $unrated])
            ->filterTable('csat', 'none')
            ->assertCanSeeTableRecords([$unrated])
            ->assertCanNotSeeTableRecords([$up, $down]);
    }

    public function test_the_view_shows_the_rating_and_its_comment(): void
    {
        $this->enable(Features::supportCsat());
        $conversation = $this->conversation($this->user('opener@example.com'), [
            'status' => SupportConversationStatus::RESOLVED,
            'csat_rating' => SupportCsatRating::DOWN,
            'csat_comment' => 'Took <b>three</b> days.',
            'csat_rated_at' => now(),
        ]);

        Livewire::actingAs($this->staff())->test(ViewSupportConversation::class, ['record' => $conversation->getKey()])
            ->assertSee((string) __('magic-starter::admin_support.csat.down'))
            ->assertSeeHtml('Took &lt;b&gt;three&lt;/b&gt; days.');
    }

    public function test_the_thread_shows_an_image_inline_and_a_pdf_as_an_untrusted_download(): void
    {
        Storage::fake('local');
        $conversation = $this->conversation($this->user('opener@example.com'));
        $message = $this->message($conversation, SupportSenderType::CUSTOMER, SupportMessageKind::MESSAGE, 'See files');
        $image = $this->attachment($message, 'chart.png', 'image/png');
        $pdf = $this->attachment($message, 'invoice.pdf', 'application/pdf');

        Livewire::actingAs($this->staff())->test(ViewSupportConversation::class, ['record' => $conversation->getKey()])
            ->assertSeeHtml('<img src="' . e($this->panelFileUrl($image)) . '"')
            ->assertSeeHtml('href="' . e($this->panelFileUrl($pdf)) . '" download')
            ->assertDontSeeHtml('<img src="' . e($this->panelFileUrl($pdf)) . '"')
            ->assertSee((string) __('magic-starter::admin_support.attachments.untrusted_pdf'))
            ->assertSee('invoice.pdf');
    }

    public function test_the_panel_serves_a_file_with_fixed_headers_to_staff_and_refuses_everyone_else(): void
    {
        Storage::fake('local');
        $opener = $this->user('opener@example.com');
        $conversation = $this->conversation($opener);
        $other = $this->conversation($opener);
        $message = $this->message($conversation, SupportSenderType::CUSTOMER, SupportMessageKind::MESSAGE, 'Files');
        $image = $this->attachment($message, 'chart.png', 'image/png');
        $pdf = $this->attachment($message, 'invoice.pdf', 'application/pdf');
        $staff = $this->staff();

        $served = $this->actingAs($staff)->get($this->panelFileUrl($image));
        $served->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('inline', (string) $served->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $served->headers->get('Cache-Control'));

        $download = $this->actingAs($staff)->get($this->panelFileUrl($pdf));
        $download->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('attachment', (string) $download->headers->get('Content-Disposition'));

        // A file asked for under a conversation it does not belong to is not found.
        $this->actingAs($staff)
            ->get("/admin/magic-starter/support-attachments/{$other->getKey()}/{$image->getKey()}")
            ->assertNotFound();

        // The opener, signed in but outside the panel gate, is refused.
        $this->actingAs($opener)->get($this->panelFileUrl($image))->assertForbidden();
    }

    public function test_the_panel_file_route_sends_a_signed_out_visitor_to_the_login(): void
    {
        Storage::fake('local');
        $conversation = $this->conversation($this->user('opener@example.com'));
        $message = $this->message($conversation, SupportSenderType::CUSTOMER, SupportMessageKind::MESSAGE, 'Files');
        $image = $this->attachment($message, 'chart.png', 'image/png');

        $this->get($this->panelFileUrl($image))->assertRedirect();
    }

    public function test_a_staff_reply_with_a_file_stores_it_through_the_attachment_action(): void
    {
        $this->enable(Features::supportAttachments());
        Storage::fake('local');
        Notification::fake();
        $staff = $this->staff();
        $opener = $this->user('opener@example.com');
        $conversation = $this->conversation($opener);

        Livewire::actingAs($staff)->test(ViewSupportConversation::class, ['record' => $conversation->getKey()])
            ->callAction('reply', [
                'body' => 'Here is the corrected chart.',
                'attachments' => [
                    UploadedFile::fake()->image('wide.png', 3000, 100),
                ],
            ])
            ->assertHasNoActionErrors();

        $message = SupportMessage::query()->where('conversation_id', $conversation->getKey())->sole();
        $attachment = SupportMessageAttachment::query()->sole();

        $this->assertTrue($attachment->message->is($message));
        $this->assertSame(SupportAttachmentOrigin::STAFF, $attachment->origin);
        $this->assertSame('wide.png', $attachment->original_name);
        $this->assertSame('image/png', $attachment->mime);
        // The re-encode capped the long edge, which only the action does.
        $this->assertSame(2048, $attachment->width);
        Storage::disk('local')->assertExists($attachment->path);
        $this->assertStringStartsWith("support/{$conversation->getKey()}/", $attachment->path);
        // A staff file is never counted against the customer's daily allowance.
        $this->assertNull(Cache::get(
            'magic-starter:support-attachments:' . $opener->getKey() . ':' . now()->toDateString() . ':files',
        ));
    }

    public function test_a_staff_file_the_action_refuses_stores_neither_the_reply_nor_the_file(): void
    {
        $this->enable(Features::supportAttachments());
        Storage::fake('local');
        Notification::fake();
        $conversation = $this->conversation($this->user('opener@example.com'));

        Livewire::actingAs($this->staff())
            ->test(ViewSupportConversation::class, ['record' => $conversation->getKey()])
            ->callAction('reply', [
                'body' => 'See attached.',
                'attachments' => [
                    // Within the panel's type and size checks; only the action's header bound refuses it.
                    UploadedFile::fake()->image('tall.png', 8001, 1),
                ],
            ])
            ->assertHasActionErrors(['attachments']);

        $this->assertSame(0, SupportMessage::query()->count());
        $this->assertSame(0, SupportMessageAttachment::query()->count());
    }

    public function test_a_reply_inside_a_wider_transaction_reaches_the_customer_only_once_it_commits(): void
    {
        // The staff reply stores its files after the contract returns, in the same transaction; a push or a
        // realtime nudge sent before that commits would send the app to read a message that is not there yet.
        Notification::fake();
        Event::fake([SupportConversationUpdated::class]);
        $staff = $this->staff();
        $opener = $this->user('opener@example.com');
        $conversation = $this->conversation($opener);

        DB::transaction(function () use ($staff, $conversation): void {
            app(RepliesToSupport::class)->reply($staff, $conversation, 'On it.', false);

            Notification::assertNothingSent();
            Event::assertNotDispatched(SupportConversationUpdated::class);
        });

        Notification::assertSentTo($opener, SupportReplyNotification::class);
        Event::assertDispatched(SupportConversationUpdated::class);
    }

    public function test_a_public_form_conversation_offers_no_upload_control(): void
    {
        $this->enable(Features::supportAttachments());
        $public = $this->conversation(null, [
            'email' => 'visitor@example.com',
            'source' => SupportConversationSource::PUBLIC_FORM,
        ]);
        $inApp = $this->conversation($this->user('opener@example.com'));

        Livewire::actingAs($this->staff())->test(ViewSupportConversation::class, ['record' => $public->getKey()])
            ->mountAction('reply')
            ->assertFormFieldHidden('attachments');

        Livewire::actingAs($this->staff())->test(ViewSupportConversation::class, ['record' => $inApp->getKey()])
            ->mountAction('reply')
            ->assertFormFieldVisible('attachments');
    }

    public function test_a_feedback_conversation_shows_when_it_must_be_answered_and_flags_it_overdue(): void
    {
        config()->set('magic-starter.support.feedback.response_hours', 48);
        Carbon::setTestNow('2026-10-12 10:00:00');
        $opener = $this->user('opener@example.com');
        $feedback = $this->conversation($opener, ['source' => SupportConversationSource::FEEDBACK]);
        $inApp = $this->conversation($opener);
        $respondBy = $feedback->created_at?->copy()->addHours(48);

        Livewire::actingAs($this->staff())->test(ViewSupportConversation::class, ['record' => $feedback->getKey()])
            ->assertSee((string) __('magic-starter::admin_support.fields.respond_by'))
            ->assertSee((string) $respondBy?->format('M j, Y H:i:s'))
            ->assertDontSee((string) __('magic-starter::admin_support.overdue'));

        Carbon::setTestNow('2026-10-14 10:01:00');

        Livewire::actingAs($this->staff())->test(ViewSupportConversation::class, ['record' => $feedback->getKey()])
            ->assertSee((string) __('magic-starter::admin_support.overdue'));

        Livewire::actingAs($this->staff())->test(ViewSupportConversation::class, ['record' => $inApp->getKey()])
            ->assertDontSee((string) __('magic-starter::admin_support.fields.respond_by'));
    }

    public function test_the_customer_context_shows_client_info_the_bound_description_and_the_openers_audits_only(): void
    {
        $this->enable(Features::audit());
        $this->app->instance(DescribesSupportCustomer::class, new class implements DescribesSupportCustomer
        {
            public function describe(SupportConversation $conversation): array
            {
                return [
                    'Plan' => 'Pro <annual>',
                    'Monitors' => 12,
                ];
            }
        });
        $opener = $this->user('opener@example.com');
        $stranger = $this->user('stranger@example.com');
        $conversation = $this->conversation($opener, [
            'client_context' => [
                'app_version' => '4.2.0',
                'platform' => 'ios',
            ],
        ]);
        $userType = $opener->getMorphClass();
        $this->audit('monitor.paused_by_opener', $userType, $opener->getKey(), null, ['paused_reason' => 'x']);
        $this->audit('team.updated_for_opener', null, null, $opener->getKey(), ['plan' => 'pro']);
        $this->audit('monitor.paused_by_stranger', $userType, $stranger->getKey(), $stranger->getKey(), []);
        // Another actor type sharing the opener's key is not the opener.
        $this->audit('token.rotated_by_job', 'job', $opener->getKey(), null, []);

        Livewire::actingAs($this->staff())->test(ViewSupportConversation::class, ['record' => $conversation->getKey()])
            ->assertSee((string) __('magic-starter::admin_support.context.title'))
            ->assertSee('4.2.0')
            ->assertSee('Plan')
            ->assertSeeHtml('Pro &lt;annual&gt;')
            ->assertSee('monitor.paused_by_opener')
            ->assertSee('team.updated_for_opener')
            ->assertSee('paused_reason')
            ->assertDontSee('monitor.paused_by_stranger')
            ->assertDontSee('token.rotated_by_job');
    }

    public function test_the_customer_context_reads_only_the_latest_twenty_audit_rows(): void
    {
        $this->enable(Features::audit());
        $opener = $this->user('opener@example.com');
        $conversation = $this->conversation($opener);

        foreach (range(1, 21) as $minute) {
            Carbon::setTestNow(Carbon::parse('2026-10-12 10:00:00')->addMinutes($minute));
            $this->audit(sprintf('event.number_%02d', $minute), null, null, $opener->getKey(), []);
        }

        Livewire::actingAs($this->staff())->test(ViewSupportConversation::class, ['record' => $conversation->getKey()])
            ->assertSee('event.number_21')
            ->assertSee('event.number_02')
            ->assertDontSee('event.number_01');
    }

    public function test_the_host_description_is_absent_while_the_contract_is_unbound(): void
    {
        $conversation = $this->conversation($this->user('opener@example.com'), [
            'client_context' => [
                'platform' => 'android',
            ],
        ]);

        Livewire::actingAs($this->staff())->test(ViewSupportConversation::class, ['record' => $conversation->getKey()])
            ->assertSee('android')
            ->assertDontSee((string) __('magic-starter::admin_support.context.customer'));
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

    /**
     * A staff assistant that counts every call, and answers or throws.
     */
    private function spyAssist(?Throwable $throws = null): object
    {
        return new class($throws) implements AssistsSupportStaff
        {
            public int $calls = 0;

            public function __construct(private readonly ?Throwable $throws) {}

            public function assist(SupportConversation $conversation): SupportStaffAssist
            {
                $this->calls++;

                throw $this->throws ?? new RuntimeException('not expected');
            }
        };
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

    /**
     * Turn a feature on for this test; the inbox reads its gates per render.
     */
    private function enable(string $feature): void
    {
        config()->set('magic-starter.features', [
            ...config('magic-starter.features', []),
            $feature,
        ]);
    }

    /**
     * A stored file on the fake `local` disk and its row.
     */
    private function attachment(SupportMessage $message, string $name, string $mime): SupportMessageAttachment
    {
        $path = "support/{$message->conversation_id}/" . Str::uuid() . '.' . pathinfo($name, PATHINFO_EXTENSION);
        Storage::disk('local')->put($path, $mime === 'application/pdf' ? "%PDF-1.4\n%%EOF\n" : 'png-bytes');

        return $message->attachments()->create([
            'conversation_id' => $message->conversation_id,
            'origin' => SupportAttachmentOrigin::UPLOAD,
            'disk' => 'local',
            'path' => $path,
            'original_name' => $name,
            'mime' => $mime,
            'size' => 10,
        ]);
    }

    private function panelFileUrl(SupportMessageAttachment $attachment): string
    {
        return url("/admin/magic-starter/support-attachments/{$attachment->conversation_id}/{$attachment->getKey()}");
    }

    /**
     * @param  array<string, mixed>  $newValues
     */
    private function audit(
        string $event,
        ?string $actorType,
        string|int|null $actorId,
        string|int|null $relatedUserId,
        array $newValues,
    ): Audit {
        return Audit::query()->create([
            'event' => $event,
            'auditable_type' => SupportConversation::class,
            'auditable_id' => '1',
            'actor_type' => $actorType,
            'actor_id' => $actorId === null ? null : (string) $actorId,
            'related_user_id' => $relatedUserId === null ? null : (string) $relatedUserId,
            'new_values' => $newValues,
        ]);
    }
}
