<?php

namespace FlutterSdk\MagicStarter\Tests\Filament;

use Filament\Facades\Filament;
use Filament\Panel;
use FlutterSdk\MagicStarter\Audit\Audit;
use FlutterSdk\MagicStarter\Audit\Redactor;
use FlutterSdk\MagicStarter\Console\ReconcileBillingEntitlements;
use FlutterSdk\MagicStarter\Events\AdminActionPerformed;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Filament\MagicStarterPlugin;
use FlutterSdk\MagicStarter\Filament\Pages\Dashboard;
use FlutterSdk\MagicStarter\Filament\Resources\Audits\AuditResource;
use FlutterSdk\MagicStarter\Filament\Resources\Audits\Pages\ListAudits;
use FlutterSdk\MagicStarter\Filament\Resources\Audits\Pages\ViewAudit;
use FlutterSdk\MagicStarter\Filament\Resources\Audits\RelationManagers\AuditsRelationManager;
use FlutterSdk\MagicStarter\Filament\Resources\NewsletterSubscribers\NewsletterSubscriberResource;
use FlutterSdk\MagicStarter\Filament\Resources\NewsletterSubscribers\Pages\ListNewsletterSubscribers;
use FlutterSdk\MagicStarter\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use FlutterSdk\MagicStarter\Filament\Resources\Subscriptions\SubscriptionResource;
use FlutterSdk\MagicStarter\Filament\Widgets\StarterStatsWidget;
use FlutterSdk\MagicStarter\Models\NewsletterSubscriber;
use FlutterSdk\MagicStarter\Models\Subscription;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteAdminUser;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription as CashierSubscription;
use Laravel\Cashier\SubscriptionItem as CashierSubscriptionItem;
use Livewire\Livewire;
use ReflectionMethod;

/**
 * The read-mostly admin surfaces: subscriptions, newsletter subscribers, audits
 * and the dashboard.
 *
 * Every feature is on while the application boots, because a resource's routes
 * are registered at that moment; the "feature off" cases build their own panels
 * the way PluginTest does.
 */
class MiscResourcesTest extends FilamentTestCase
{
    protected const ADMIN_EMAIL = 'ops@example.com';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('magic-starter.features', [
            Features::teams(),
            Features::billing(),
            Features::newsletterSubscription(),
            Features::audit(),
        ]);

        // Billing refuses to boot without a ranking.
        $app['config']->set('magic-starter.billing.tier_order', ['free', 'pro']);
    }

    /**
     * The test that boots the application as a web request rather than the
     * console process PHPUnit is.
     */
    private const WEB_REQUEST_TEST = 'test_reconcile_now_succeeds_in_a_web_request';

    protected function setUp(): void
    {
        // `runningInConsole()` reads this once, before the provider boots, so it
        // has to be in place before the application exists.
        if ($this->name() === self::WEB_REQUEST_TEST) {
            $_SERVER['APP_RUNNING_IN_CONSOLE'] = 'false';
        }

        parent::setUp();

        // The provider wires Cashier while it REGISTERS, which is before the
        // features above are written, so the fixture does what an application
        // with billing on gets from the provider.
        Cashier::useSubscriptionModel(Subscription::class);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['APP_RUNNING_IN_CONSOLE']);

        // Statics on Cashier outlive the application that set them.
        Cashier::useCustomerModel('App\\Models\\User');
        Cashier::useSubscriptionModel(CashierSubscription::class);
        Cashier::useSubscriptionItemModel(CashierSubscriptionItem::class);

        parent::tearDown();
    }

    public function test_each_resource_is_registered_only_behind_its_feature(): void
    {
        $resources = [
            Features::billing() => SubscriptionResource::class,
            Features::newsletterSubscription() => NewsletterSubscriberResource::class,
            Features::audit() => AuditResource::class,
        ];

        foreach ($resources as $feature => $resource) {
            config()->set('magic-starter.features', []);
            $off = Panel::make()->id('off-' . $feature)->plugin(MagicStarterPlugin::make());

            config()->set('magic-starter.features', [$feature]);
            $on = Panel::make()->id('on-' . $feature)->plugin(MagicStarterPlugin::make());

            $this->assertNotContains($resource, $off->getResources(), "[{$feature}] off still mounts [{$resource}].");
            $this->assertContains($resource, $on->getResources(), "[{$feature}] on does not mount [{$resource}].");
        }
    }

    /**
     * A model label is lower case because Filament embeds it mid-sentence
     * ("Create subscription"); the menu shows the navigation label on its own,
     * beside "Users" and "Teams", so it is a title of its own.
     */
    public function test_the_navigation_labels_are_titles_in_every_shipped_locale(): void
    {
        $expected = [
            'en' => [
                SubscriptionResource::class => 'Subscriptions',
                NewsletterSubscriberResource::class => 'Newsletter subscribers',
                AuditResource::class => 'Audit trail',
            ],
            'tr' => [
                SubscriptionResource::class => 'Abonelikler',
                NewsletterSubscriberResource::class => 'Bülten aboneleri',
                AuditResource::class => 'Denetim kayıtları',
            ],
        ];

        foreach ($expected as $locale => $labels) {
            app()->setLocale($locale);

            foreach ($labels as $resource => $label) {
                $this->assertSame($label, $resource::getNavigationLabel(), "[{$locale}] {$resource}");
                $this->assertSame(mb_strtolower($resource::getPluralModelLabel()), $resource::getPluralModelLabel());
            }
        }
    }

    public function test_the_plugin_mounts_the_dashboard_and_its_widget(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertContains(Dashboard::class, $panel->getPages());
        $this->assertContains(StarterStatsWidget::class, $panel->getWidgets());
    }

    // -------------------------------------------------------------------------
    // Subscriptions
    // -------------------------------------------------------------------------

    public function test_the_subscription_list_shows_cashier_rows_and_offers_no_write_action(): void
    {
        $active = $this->subscription('sub_active', 'active');
        $ended = $this->subscription('sub_ended', 'canceled', now()->subDay());

        Livewire::actingAs($this->admin())
            ->test(ListSubscriptions::class)
            ->loadTable()
            ->assertCanSeeTableRecords([$active, $ended])
            ->assertSee('price_pro')
            ->assertActionDoesNotExist('create');

        $this->assertSame(['index'], array_keys(SubscriptionResource::getPages()));
    }

    public function test_reconcile_now_runs_the_command_and_reports_the_exit(): void
    {
        Event::fake([AdminActionPerformed::class]);
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(ListSubscriptions::class)
            ->callAction('reconcile')
            ->assertNotified(__('magic-starter::admin_misc.subscriptions.reconcile.succeeded'));

        Event::assertDispatched(
            AdminActionPerformed::class,
            static fn (AdminActionPerformed $event): bool => $event->action === 'billing.reconciled'
                && $event->actor->is($admin),
        );
    }

    /**
     * PHPUnit runs under the cli SAPI, so every other test boots the provider as
     * a console process; an operator clicks inside an HTTP request.
     */
    public function test_reconcile_now_succeeds_in_a_web_request(): void
    {
        $this->assertFalse($this->app->runningInConsole());

        Event::fake([AdminActionPerformed::class]);

        Livewire::actingAs($this->admin())
            ->test(ListSubscriptions::class)
            ->callAction('reconcile')
            ->assertNotified(__('magic-starter::admin_misc.subscriptions.reconcile.succeeded'));

        Event::assertDispatched(
            AdminActionPerformed::class,
            static fn (AdminActionPerformed $event): bool => $event->action === 'billing.reconciled',
        );
    }

    public function test_a_reconcile_that_could_not_read_a_rail_notifies_danger(): void
    {
        Artisan::command(ReconcileBillingEntitlements::NAME, fn (): int => 1);

        Livewire::actingAs($this->admin())
            ->test(ListSubscriptions::class)
            ->callAction('reconcile')
            ->assertNotified(__('magic-starter::admin_misc.subscriptions.reconcile.failed'));
    }

    // -------------------------------------------------------------------------
    // Newsletter subscribers
    // -------------------------------------------------------------------------

    public function test_a_subscriber_can_be_toggled_inactive_and_active_again(): void
    {
        Event::fake([AdminActionPerformed::class]);
        $subscriber = NewsletterSubscriber::query()->create(['email' => 'reader@example.com', 'source' => 'footer']);

        $list = Livewire::actingAs($this->admin())
            ->test(ListNewsletterSubscribers::class)
            ->assertCanSeeTableRecords([$subscriber]);

        $list->callTableAction('toggleActive', $subscriber);
        $this->assertFalse($subscriber->refresh()->is_active);

        $list->callTableAction('toggleActive', $subscriber);
        $this->assertTrue($subscriber->refresh()->is_active);

        Event::assertDispatchedTimes(AdminActionPerformed::class, 2);
        Event::assertDispatched(
            AdminActionPerformed::class,
            static fn (AdminActionPerformed $event): bool => $event->action === 'newsletter.subscriber_toggled'
                && $event->subject?->is($subscriber) === true,
        );
    }

    public function test_the_export_streams_a_csv_with_the_seeded_email_and_neutralises_formulas(): void
    {
        NewsletterSubscriber::query()->create(['email' => 'reader@example.com', 'source' => 'footer']);
        NewsletterSubscriber::query()->create(['email' => 'second@example.com', 'source' => '=HYPERLINK("x")']);

        $list = Livewire::actingAs($this->admin())
            ->test(ListNewsletterSubscribers::class)
            ->callAction('export')
            ->assertFileDownloaded('newsletter-subscribers-' . now()->format('Y-m-d') . '.csv');

        $csv = base64_decode((string) data_get($list->effects, 'download.content'));

        $this->assertStringContainsString('email,is_active,source,created_at', $csv);
        $this->assertStringContainsString('reader@example.com', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
    }

    // -------------------------------------------------------------------------
    // Audits
    // -------------------------------------------------------------------------

    public function test_the_audit_list_filters_by_event_and_offers_no_write_action(): void
    {
        $owner = $this->admin();
        $updated = $this->audit($owner, 'user.updated');
        $deleted = $this->audit($owner, 'user.deleted');

        Livewire::actingAs($owner)
            ->test(ListAudits::class)
            ->assertCanSeeTableRecords([$updated, $deleted])
            ->filterTable('event', 'user.updated')
            ->assertCanSeeTableRecords([$updated])
            ->assertCanNotSeeTableRecords([$deleted]);

        $this->assertSame(['index', 'view'], array_keys(AuditResource::getPages()));
    }

    public function test_the_audit_view_shows_old_and_new_values_context_and_the_redaction_marker(): void
    {
        // A change the model listener records itself, so the marker on screen is
        // the redactor's and not a value this test wrote.
        $owner = $this->admin();
        $owner->forceFill(['name' => 'Old Name'])->save();
        $owner->forceFill([
            'name' => 'New Name',
            'password' => 'plain-new-password',
        ])->save();

        $audit = Audit::query()
            ->where('event', 'updated')
            ->where('auditable_id', (string) $owner->getKey())
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(Redactor::PLACEHOLDER, $audit->new_values['password']);

        Livewire::actingAs($owner)
            ->test(ViewAudit::class, ['record' => $audit->getKey()])
            ->assertSee('Old Name')
            ->assertSee('New Name')
            ->assertSee((string) $audit->context['ip'])
            ->assertSee(Redactor::PLACEHOLDER)
            ->assertDontSee('plain-new-password');
    }

    public function test_the_audit_title_names_the_subject_of_a_model_event_and_leaves_a_dotted_event_alone(): void
    {
        $owner = $this->admin();

        $this->assertSame(
            'ConcreteAdminUser updated',
            AuditResource::getRecordTitle($this->audit($owner, 'updated')),
        );
        $this->assertSame(
            'admin.user_impersonated',
            AuditResource::getRecordTitle($this->audit($owner, 'admin.user_impersonated')),
        );

        Livewire::actingAs($owner)
            ->test(ViewAudit::class, ['record' => $this->audit($owner, 'created')->getKey()])
            ->assertSee('ConcreteAdminUser created')
            ->assertDontSee('View created');
    }

    public function test_the_audit_names_its_actor_the_system_or_a_deleted_user(): void
    {
        $owner = $this->admin();
        $byUser = $this->audit($owner, 'user.updated');
        $bySystem = $this->audit($owner, 'updated', [
            'actor_type' => null,
            'actor_id' => null,
        ]);
        $byDeletedUser = $this->audit($owner, 'deleted', ['actor_id' => null]);
        // Same key as the user, another actor type: it must not borrow the name.
        $byRobot = $this->audit($owner, 'synced', ['actor_type' => 'App\\Models\\Robot']);

        Livewire::actingAs($owner)
            ->test(ListAudits::class)
            ->assertTableColumnStateSet('actor', 'Ops (' . self::ADMIN_EMAIL . ')', $byUser)
            ->assertTableColumnStateSet('actor', 'System', $bySystem)
            ->assertTableColumnStateSet('actor', 'Deleted user', $byDeletedUser)
            ->assertTableColumnStateSet('actor', 'Robot ' . $owner->getKey(), $byRobot)
            ->assertTableColumnFormattedStateSet('auditable_type', 'ConcreteAdminUser', $byUser);
    }

    public function test_the_audit_list_finds_rows_by_the_acting_users_name(): void
    {
        $owner = $this->admin();
        $other = $this->admin('other@example.com');
        $other->forceFill(['name' => 'Quinn'])->save();

        $mine = $this->audit($owner, 'user.updated');
        $theirs = $this->audit($other, 'user.updated');

        Livewire::actingAs($owner)
            ->test(ListAudits::class)
            ->searchTable('quinn')
            ->assertCanSeeTableRecords([$theirs])
            ->assertCanNotSeeTableRecords([$mine]);
    }

    public function test_the_audit_changes_table_shows_only_the_sides_the_row_recorded(): void
    {
        $owner = $this->admin();
        $created = $this->audit($owner, 'created', [
            'new_values' => [
                'name' => 'Ops',
                'flags' => ['beta' => true],
            ],
        ]);
        $updated = $this->audit($owner, 'updated', [
            'old_values' => [
                'name' => 'Old Name',
                'phone' => '',
            ],
            'new_values' => [
                'name' => 'New Name',
                'phone' => '+905551112233',
                'locale' => 'tr',
            ],
        ]);

        Livewire::actingAs($owner)
            ->test(ViewAudit::class, ['record' => $created->getKey()])
            ->assertSee('After')
            ->assertDontSee('Before')
            ->assertSee('{"beta":true}');

        Livewire::actingAs($owner)
            ->test(ViewAudit::class, ['record' => $updated->getKey()])
            ->assertSeeInOrder(['Before', 'After', 'name', 'Old Name', 'New Name', 'phone', '""', '+905551112233', 'locale', '-', 'tr']);
    }

    public function test_the_audits_relation_manager_scopes_to_its_owner_without_a_relation_on_the_model(): void
    {
        $owner = $this->admin();
        $other = $this->admin('other@example.com');

        $this->assertFalse(method_exists($owner, 'audits'));

        $mine = $this->audit($owner, 'user.updated');
        $theirs = $this->audit($other, 'user.updated');

        Livewire::actingAs($owner)
            ->test(AuditsRelationManager::class, [
                'ownerRecord' => $owner,
                'pageClass' => ViewAudit::class,
            ])
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    // -------------------------------------------------------------------------
    // Dashboard
    // -------------------------------------------------------------------------

    public function test_the_dashboard_sits_on_its_own_path_and_answers_for_an_allowlisted_admin(): void
    {
        config()->set('magic-starter.admin.emails', [self::ADMIN_EMAIL]);

        $this->assertSame('dashboard', Dashboard::getRoutePath(Filament::getPanel('admin')));

        $this->actingAs($this->admin())
            ->get('/admin/dashboard')
            ->assertOk();
    }

    public function test_the_stats_widget_counts_users_teams_scheduled_deletions_and_active_subscriptions(): void
    {
        $leaving = $this->admin('leaving@example.com');
        $leaving->forceFill(['deletion_scheduled_at' => now()->addDays(7)])->save();
        $this->admin();

        ConcreteTeam::query()->create(['name' => 'Acme', 'user_id' => $leaving->getKey(), 'personal_team' => false]);
        $this->subscription('sub_active', 'active', billable: $leaving);
        $this->subscription('sub_ended', 'canceled', now()->subDay(), $leaving);

        $stats = $this->stats();

        $this->assertSame([
            __('magic-starter::admin_misc.dashboard.stats.users') => 2,
            __('magic-starter::admin_misc.dashboard.stats.teams') => 1,
            __('magic-starter::admin_misc.dashboard.stats.scheduled_deletions') => 1,
            __('magic-starter::admin_misc.dashboard.stats.active_subscriptions') => 1,
        ], $stats);
    }

    public function test_the_stats_widget_leaves_out_the_teams_and_billing_figures_when_those_features_are_off(): void
    {
        config()->set('magic-starter.features', []);

        $this->assertSame([
            __('magic-starter::admin_misc.dashboard.stats.users'),
            __('magic-starter::admin_misc.dashboard.stats.scheduled_deletions'),
        ], array_keys($this->stats()));
    }

    /**
     * The widget's figures by label.
     *
     * @return array<string, mixed>
     */
    private function stats(): array
    {
        $widget = new StarterStatsWidget;
        $figures = [];

        foreach ((new ReflectionMethod($widget, 'getStats'))->invoke($widget) as $stat) {
            $figures[(string) $stat->getLabel()] = $stat->getValue();
        }

        return $figures;
    }

    private function admin(string $email = self::ADMIN_EMAIL): ConcreteAdminUser
    {
        $user = new ConcreteAdminUser;
        $user->forceFill([
            'name' => 'Ops',
            'email' => $email,
            'email_verified_at' => now(),
            'password' => 'secret',
        ])->save();

        return $user;
    }

    private function subscription(
        string $stripeId,
        string $status,
        mixed $endsAt = null,
        ?ConcreteAdminUser $billable = null,
    ): Subscription {
        $billable ??= $this->admin(uniqid('billable', true) . '@example.com');

        return Subscription::query()->create([
            $billable->getForeignKey() => $billable->getKey(),
            'type' => 'default',
            'stripe_id' => $stripeId,
            'stripe_status' => $status,
            'stripe_price' => 'price_pro',
            'quantity' => 1,
            'ends_at' => $endsAt,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function audit(ConcreteAdminUser $subject, string $event, array $attributes = []): Audit
    {
        return Audit::query()->create([
            'event' => $event,
            'auditable_type' => $subject->getMorphClass(),
            'auditable_id' => (string) $subject->getKey(),
            'actor_type' => $subject->getMorphClass(),
            'actor_id' => (string) $subject->getKey(),
            ...$attributes,
        ]);
    }
}
