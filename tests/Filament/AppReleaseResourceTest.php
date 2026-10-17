<?php

namespace FlutterSdk\MagicStarter\Tests\Filament;

use Filament\Notifications\Notification as FilamentNotification;
use Filament\Panel;
use FlutterSdk\MagicStarter\Enums\ReleaseStatus;
use FlutterSdk\MagicStarter\Events\AdminActionPerformed;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Filament\MagicStarterPlugin;
use FlutterSdk\MagicStarter\Filament\Resources\AppReleases\AppReleaseResource;
use FlutterSdk\MagicStarter\Filament\Resources\AppReleases\Pages\CreateAppRelease;
use FlutterSdk\MagicStarter\Filament\Resources\AppReleases\Pages\EditAppRelease;
use FlutterSdk\MagicStarter\Filament\Resources\AppReleases\Pages\ListAppReleases;
use FlutterSdk\MagicStarter\Models\AppRelease;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteAdminUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

/**
 * Staff curate app releases in the panel; clients read the published ones from the public feed.
 *
 * The releases feature is on while the application boots, because both the resource's
 * routes and the package's `GET releases` route are registered at that moment; the
 * "feature off" case builds its own panel the way PluginTest does.
 */
class AppReleaseResourceTest extends FilamentTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('magic-starter.route_prefix', 'api/v1');
        $app['config']->set('magic-starter.features', [Features::releases()]);
    }

    public function test_the_resource_is_mounted_only_with_the_releases_feature(): void
    {
        config()->set('magic-starter.features', []);
        $off = Panel::make()->id('releases-off')->plugin(MagicStarterPlugin::make());

        config()->set('magic-starter.features', [Features::releases()]);
        $on = Panel::make()->id('releases-on')->plugin(MagicStarterPlugin::make());

        $this->assertNotContains(AppReleaseResource::class, $off->getResources());
        $this->assertContains(AppReleaseResource::class, $on->getResources());
    }

    public function test_the_resource_enumerates_index_create_and_edit(): void
    {
        $this->assertSame(['index', 'create', 'edit'], array_keys(AppReleaseResource::getPages()));
    }

    public function test_a_user_outside_the_panel_gate_cannot_reach_the_list(): void
    {
        $this->actingAs($this->staff())->get('/admin/app-releases')->assertOk();
        $this->actingAs($this->user('member@example.com'))->get('/admin/app-releases')->assertForbidden();
    }

    public function test_the_navigation_labels_resolve_in_english_and_turkish(): void
    {
        app()->setLocale('en');
        $this->assertSame('Releases', AppReleaseResource::getNavigationLabel());
        $this->assertSame('release', AppReleaseResource::getModelLabel());

        app()->setLocale('tr');
        $this->assertSame('Sürümler', AppReleaseResource::getNavigationLabel());
        $this->assertSame('sürüm', AppReleaseResource::getModelLabel());
    }

    public function test_the_list_is_newest_first_and_offers_no_bulk_action(): void
    {
        $older = $this->release('1.0.0', now()->subDays(2));
        $newer = $this->release('1.1.0', now()->subDay());

        $list = Livewire::actingAs($this->staff())->test(ListAppReleases::class)
            ->assertCanSeeTableRecords([$newer, $older], inOrder: true);

        $this->assertSame([], $list->instance()->getTable()->getBulkActions());
    }

    public function test_creating_with_english_only_stores_a_list_and_the_feed_fills_turkish_from_it(): void
    {
        Event::fake([AdminActionPerformed::class]);

        Livewire::actingAs($this->staff())->test(CreateAppRelease::class)
            ->fillForm([
                'version' => '1.2.0',
                'status' => ReleaseStatus::PUBLISHED->value,
                'ios_build' => 42,
                'items' => [
                    ['type' => 'new', 'text' => ['en' => 'Dark mode']],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $raw = (string) DB::table('app_releases')->where('version', '1.2.0')->value('items');

        $this->assertStringContainsString('"type":"new"', $raw);
        $this->assertTrue(array_is_list(json_decode($raw, true)));
        $this->assertSame([['type' => 'new', 'text' => ['en' => 'Dark mode']]], json_decode($raw, true));

        Event::assertDispatched(
            AdminActionPerformed::class,
            static fn (AdminActionPerformed $event): bool => $event->action === 'release.created',
        );

        $this->getJson('/api/v1/releases')
            ->assertOk()
            ->assertJsonPath('data.0.version', '1.2.0')
            ->assertJsonPath('data.0.items.0.text.en', 'Dark mode')
            ->assertJsonPath('data.0.items.0.text.tr', 'Dark mode');
    }

    public function test_a_duplicate_version_is_refused_without_writing(): void
    {
        $this->release('1.0.0', now()->subDay());

        Livewire::actingAs($this->staff())->test(CreateAppRelease::class)
            ->fillForm([
                'version' => '1.0.0',
                'status' => ReleaseStatus::DRAFT->value,
                'items' => [
                    ['type' => 'fixed', 'text' => ['en' => 'A crash']],
                ],
            ])
            ->call('create');

        FilamentNotification::assertNotified(
            FilamentNotification::make()->danger()->title(
                (string) __('validation.unique', ['attribute' => 'version']),
            ),
        );
        $this->assertSame(1, AppRelease::query()->count());
    }

    public function test_the_edit_form_hydrates_the_items_and_saves_a_reorder_as_a_list(): void
    {
        Event::fake([AdminActionPerformed::class]);

        $release = $this->release('1.0.0', now()->subDay(), [
            ['type' => 'new', 'text' => ['en' => 'First', 'tr' => 'Birinci']],
            ['type' => 'fixed', 'text' => ['en' => 'Second']],
        ]);

        $edit = Livewire::actingAs($this->staff())->test(EditAppRelease::class, ['record' => $release->getKey()])
            ->assertFormSet(static function (array $state): void {
                $items = array_values($state['items']);

                self::assertSame('new', $items[0]['type']);
                self::assertSame(['en' => 'First', 'tr' => 'Birinci'], $items[0]['text']);
                self::assertSame('fixed', $items[1]['type']);
            });

        // The state a drag-and-drop reorder leaves: the same keyed items in a new order.
        $edit->set('data.items', array_reverse($edit->get('data.items'), preserve_keys: true))
            ->call('save')
            ->assertHasNoFormErrors();

        $stored = $release->refresh()->items;

        $this->assertTrue(array_is_list($stored));
        $this->assertSame(['fixed', 'new'], array_column($stored, 'type'));
        $this->assertSame('First', $stored[1]['text']['en']);

        Event::assertDispatched(
            AdminActionPerformed::class,
            static fn (AdminActionPerformed $event): bool => $event->action === 'release.updated'
                && $event->subject?->is($release) === true,
        );
    }

    public function test_the_header_action_deletes_through_the_contract_and_the_feed_drops_the_row(): void
    {
        Event::fake([AdminActionPerformed::class]);

        $release = $this->release('1.0.0', now()->subDay());
        $this->getJson('/api/v1/releases')->assertJsonCount(1, 'data');

        Livewire::actingAs($this->staff())->test(EditAppRelease::class, ['record' => $release->getKey()])
            ->callAction('delete');

        $this->assertNull(AppRelease::query()->find($release->getKey()));
        $this->getJson('/api/v1/releases')->assertOk()->assertJsonCount(0, 'data');

        Event::assertDispatched(
            AdminActionPerformed::class,
            static fn (AdminActionPerformed $event): bool => $event->action === 'release.deleted',
        );
    }

    /**
     * @param  list<array{type: string, text: array<string, string>}>|null  $items
     */
    private function release(string $version, Carbon $publishedAt, ?array $items = null): AppRelease
    {
        return AppRelease::query()->create([
            'version' => $version,
            'status' => ReleaseStatus::PUBLISHED,
            'published_at' => $publishedAt,
            'items' => $items ?? [['type' => 'new', 'text' => ['en' => 'Something']]],
        ]);
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
}
