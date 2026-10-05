<?php

namespace FlutterSdk\MagicStarter\Tests\Filament;

use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use FlutterSdk\MagicStarter\Events\AdminActionPerformed;
use FlutterSdk\MagicStarter\Filament\Concerns\WritesThroughContracts;
use FlutterSdk\MagicStarter\Filament\MagicStarterPlugin;
use FlutterSdk\MagicStarter\Filament\Resources\MagicStarterResource;
use FlutterSdk\MagicStarter\Filament\Support\ContractAction;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Social\SocialSignInRefused;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteAdminUser;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The plugin core: registration, the resource base, the page write seam and the
 * contract-action runner every admin write goes through.
 *
 * Fixture resources and pages are anonymous classes built inside methods, never
 * file-level classes: the no-Filament CI job loads this file, and a top-level
 * class extending a Filament class would fatal before the suite could skip.
 */
class PluginTest extends FilamentTestCase
{
    public function test_the_plugin_is_registered_on_the_fixture_panel_under_its_id(): void
    {
        $this->assertTrue(Filament::getPanel('admin')->hasPlugin('magic-starter'));
        $this->assertSame('magic-starter', MagicStarterPlugin::make()->getId());
        $this->assertInstanceOf(MagicStarterPlugin::class, MagicStarterPlugin::get());
    }

    public function test_registering_with_a_user_model_that_is_not_a_filament_user_throws(): void
    {
        MagicStarter::useUserModel(ConcreteUser::class);

        $this->expectException(LogicException::class);

        Panel::make()->id('refused')->plugin(MagicStarterPlugin::make());
    }

    public function test_a_default_resource_is_registered_under_its_feature_gate(): void
    {
        $resource = $this->plainResource();

        config()->set('magic-starter.features', []);
        $off = Panel::make()->id('off')->plugin(MagicStarterPlugin::make()->teamResource($resource));

        config()->set('magic-starter.features', ['teams']);
        $on = Panel::make()->id('on')->plugin(MagicStarterPlugin::make()->teamResource($resource));

        $this->assertNotContains($resource, $off->getResources());
        $this->assertContains($resource, $on->getResources());
    }

    public function test_the_user_resource_is_registered_without_a_feature_gate(): void
    {
        $resource = $this->plainResource();

        config()->set('magic-starter.features', []);
        $panel = Panel::make()->id('users')->plugin(MagicStarterPlugin::make()->userResource($resource));

        $this->assertContains($resource, $panel->getResources());
    }

    public function test_a_resource_key_can_be_overridden_and_a_default_can_be_dropped(): void
    {
        $users = $this->plainResource();
        $subscriptions = $this->recordingResource();

        config()->set('magic-starter.features', ['billing']);
        $panel = Panel::make()->id('custom')->plugin(
            MagicStarterPlugin::make()
                ->userResource($users)
                ->resource('subscriptions', $subscriptions)
                ->withoutResources(['users']),
        );

        $this->assertNotContains($users, $panel->getResources());
        $this->assertContains($subscriptions, $panel->getResources());
    }

    public function test_a_resource_whose_class_does_not_exist_is_skipped(): void
    {
        config()->set('magic-starter.features', ['teams']);

        $panel = Panel::make()->id('bare')->plugin(
            MagicStarterPlugin::make()->teamResource('FlutterSdk\\MagicStarter\\Missing\\TeamResource'),
        );

        $this->assertNotContains('FlutterSdk\\MagicStarter\\Missing\\TeamResource', $panel->getResources());
    }

    public function test_the_navigation_group_reaches_the_resources(): void
    {
        $resource = $this->plainResource();

        $this->assertSame(__('magic-starter::admin.navigation_group'), $resource::getNavigationGroup());

        Filament::setCurrentPanel(
            Panel::make()->id('grouped')->plugin(MagicStarterPlugin::make()->navigationGroup('Ops')),
        );

        $this->assertSame('Ops', $resource::getNavigationGroup());
    }

    public function test_the_resource_base_skips_policies_and_discovery(): void
    {
        $resource = $this->plainResource();

        $this->assertTrue($resource::shouldSkipAuthorization());
        $this->assertFalse($resource::isDiscovered());
        $this->assertTrue($resource::canViewAny());
    }

    public function test_the_write_hooks_refuse_by_default(): void
    {
        $resource = $this->plainResource();
        $actor = $this->actor();

        try {
            $resource::createRecordUsing([], $actor);
            $this->fail('The create hook did not refuse.');
        } catch (LogicException) {
            // The default for a resource without a create page.
        }

        $this->expectException(LogicException::class);

        $resource::updateRecordUsing($actor, [], $actor);
    }

    public function test_an_edit_page_saves_through_the_resource_hook(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor);
        $record = $this->actor();
        $resource = $this->recordingResource();

        $page = new class extends EditRecord
        {
            use WritesThroughContracts;

            public static function using(string $resource): void
            {
                self::$resource = $resource;
            }

            /**
             * @param  array<string, mixed>  $data
             */
            public function updateThroughSeam(Model $record, array $data): Model
            {
                return $this->handleRecordUpdate($record, $data);
            }
        };
        $page::using($resource);

        $this->assertSame($record, $page->updateThroughSeam($record, ['name' => 'New']));
        $this->assertSame([['update', $record, ['name' => 'New'], $actor]], $resource::$calls);
    }

    public function test_a_create_page_creates_through_the_resource_hook(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor);
        $resource = $this->recordingResource();

        $page = new class extends CreateRecord
        {
            use WritesThroughContracts;

            public static function using(string $resource): void
            {
                self::$resource = $resource;
            }

            /**
             * @param  array<string, mixed>  $data
             */
            public function createThroughSeam(array $data): Model
            {
                return $this->handleRecordCreation($data);
            }
        };
        $page::using($resource);

        $this->assertTrue($actor->is($page->createThroughSeam(['name' => 'Fresh'])));
        $this->assertSame([['create', ['name' => 'Fresh'], $actor]], $resource::$calls);
    }

    public function test_a_contract_action_dispatches_the_admin_event_on_success(): void
    {
        Event::fake([AdminActionPerformed::class]);
        $actor = $this->actor();
        $this->actingAs($actor);
        $subject = $this->actor();

        $result = ContractAction::run(null, static fn (): string => 'done', 'user.updated', $subject);

        $this->assertSame('done', $result);
        Event::assertDispatched(
            AdminActionPerformed::class,
            static fn (AdminActionPerformed $event): bool => $event->actor === $actor
                && $event->action === 'user.updated'
                && $event->subject === $subject
                && $event->context === [],
        );
    }

    public function test_a_validation_refusal_notifies_and_halts_without_the_admin_event(): void
    {
        Event::fake([AdminActionPerformed::class]);
        $this->actingAs($this->actor());

        try {
            ContractAction::run(
                null,
                static fn () => throw ValidationException::withMessages([
                    'team' => ['You may not remove the team owner.'],
                    'other' => ['A second message the notification does not carry.'],
                ]),
                'team.member_removed',
                null,
            );
            $this->fail('The refusal did not halt.');
        } catch (Halt) {
            // The page or action catches this and stays open.
        }

        Notification::assertNotified('You may not remove the team owner.');
        Event::assertNotDispatched(AdminActionPerformed::class);
    }

    public function test_a_social_refusal_notifies_and_halts(): void
    {
        Event::fake([AdminActionPerformed::class]);
        $this->actingAs($this->actor());

        $refusal = new SocialSignInRefused('social_email_taken');

        try {
            ContractAction::run(null, static fn () => throw $refusal, 'social.disconnected', null);
            $this->fail('The refusal did not halt.');
        } catch (Halt) {
            // The page or action catches this and stays open.
        }

        Notification::assertNotified($refusal->getMessage());
        Event::assertNotDispatched(AdminActionPerformed::class);
    }

    public function test_any_other_failure_propagates(): void
    {
        Event::fake([AdminActionPerformed::class]);
        $this->actingAs($this->actor());

        $this->expectException(LogicException::class);

        try {
            ContractAction::run(null, static fn () => throw new LogicException('Bug.'), 'user.updated', null);
        } finally {
            Event::assertNotDispatched(AdminActionPerformed::class);
        }
    }

    private function actor(): ConcreteAdminUser
    {
        $user = new ConcreteAdminUser;
        $user->forceFill([
            'name' => 'Ops',
            'email' => uniqid('ops', true) . '@example.com',
            'password' => 'secret',
        ])->save();

        return $user;
    }

    /**
     * A resource that overrides nothing beyond its model.
     *
     * @return class-string<MagicStarterResource>
     */
    private function plainResource(): string
    {
        return (new class extends MagicStarterResource
        {
            protected static function resolveModel(): string
            {
                return ConcreteUser::class;
            }
        })::class;
    }

    /**
     * A resource that records every write the page seam routes through it.
     *
     * A distinct class from {@see plainResource()}, so a panel can carry both.
     */
    private function recordingResource(): string
    {
        $resource = new class extends MagicStarterResource
        {
            /**
             * @var list<array<int, mixed>>
             */
            public static array $calls = [];

            protected static function resolveModel(): string
            {
                return ConcreteAdminUser::class;
            }

            public static function updateRecordUsing(Model $record, array $data, Authenticatable $actor): Model
            {
                self::$calls[] = ['update', $record, $data, $actor];

                return $record;
            }

            public static function createRecordUsing(array $data, Authenticatable $actor): Model
            {
                self::$calls[] = ['create', $data, $actor];

                return ConcreteAdminUser::query()->findOrFail($actor->getAuthIdentifier());
            }
        };
        $resource::$calls = [];

        return $resource::class;
    }
}
