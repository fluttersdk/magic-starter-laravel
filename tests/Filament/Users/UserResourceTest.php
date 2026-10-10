<?php

namespace FlutterSdk\MagicStarter\Tests\Filament\Users;

use Filament\Actions\Action;
use FlutterSdk\MagicStarter\Contracts\CreatesUsers;
use FlutterSdk\MagicStarter\Contracts\UpdatesUserProfiles;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Filament\Resources\Billing\RelationManagers\BillingRelationManager;
use FlutterSdk\MagicStarter\Filament\Resources\Users\Pages\CreateUser;
use FlutterSdk\MagicStarter\Filament\Resources\Users\Pages\EditUser;
use FlutterSdk\MagicStarter\Filament\Resources\Users\Pages\ListUsers;
use FlutterSdk\MagicStarter\Filament\Resources\Users\RelationManagers\PushDevicesRelationManager;
use FlutterSdk\MagicStarter\Filament\Resources\Users\RelationManagers\SocialAccountsRelationManager;
use FlutterSdk\MagicStarter\Filament\Resources\Users\RelationManagers\TeamsRelationManager;
use FlutterSdk\MagicStarter\Filament\Resources\Users\RelationManagers\TokensRelationManager;
use FlutterSdk\MagicStarter\Filament\Resources\Users\UserResource;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Notifications\VerifyEmailNotification;
use FlutterSdk\MagicStarter\Tests\Filament\FilamentTestCase;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteAdminUser;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Notifications\RoutesNotifications;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * The Users resource: what the form and table carry, and that a save leaves
 * through the package contracts and never through the model.
 */
class UserResourceTest extends FilamentTestCase
{
    public function test_the_edit_schema_has_no_credential_component(): void
    {
        $this->enableAllFeatures();

        $page = Livewire::actingAs($this->staff())->test(EditUser::class, ['record' => $this->member()->getKey()]);

        $page->assertSchemaComponentExists('name', 'form')
            ->assertSchemaComponentExists('email', 'form');

        foreach (['password', 'two_factor_secret', 'two_factor_recovery_codes'] as $field) {
            $page->assertSchemaComponentDoesNotExist($field, 'form');
        }
    }

    public function test_the_create_schema_has_no_credential_component(): void
    {
        $this->enableAllFeatures();

        $page = Livewire::actingAs($this->staff())->test(CreateUser::class);

        foreach (['password', 'two_factor_secret', 'two_factor_recovery_codes'] as $field) {
            $page->assertSchemaComponentDoesNotExist($field, 'form');
        }
    }

    public function test_locale_timezone_and_phone_follow_their_features(): void
    {
        $record = $this->member()->getKey();

        config()->set('magic-starter.features', []);
        Livewire::actingAs($this->staff())->test(EditUser::class, ['record' => $record])
            ->assertSchemaComponentDoesNotExist('locale', 'form')
            ->assertSchemaComponentDoesNotExist('timezone', 'form')
            ->assertSchemaComponentDoesNotExist('phone', 'form');

        config()->set('magic-starter.features', [Features::timezones()]);
        Livewire::actingAs($this->staff())->test(EditUser::class, ['record' => $record])
            ->assertSchemaComponentExists('timezone', 'form')
            ->assertSchemaComponentDoesNotExist('locale', 'form')
            ->assertSchemaComponentDoesNotExist('phone', 'form');

        config()->set('magic-starter.features', [Features::extendedProfile()]);
        Livewire::actingAs($this->staff())->test(EditUser::class, ['record' => $record])
            ->assertSchemaComponentExists('locale', 'form')
            ->assertSchemaComponentExists('timezone', 'form')
            ->assertSchemaComponentExists('phone', 'form');
    }

    public function test_an_edit_save_calls_the_profile_contract_once(): void
    {
        $spy = new class implements UpdatesUserProfiles
        {
            /**
             * @var list<array{0: mixed, 1: array<string, mixed>}>
             */
            public array $calls = [];

            public function update(Authenticatable $user, array $input): void
            {
                $this->calls[] = [$user->getAuthIdentifier(), $input];
            }
        };
        $this->app->instance(UpdatesUserProfiles::class, $spy);
        $member = $this->member();

        Livewire::actingAs($this->staff())->test(EditUser::class, ['record' => $member->getKey()])
            ->fillForm([
                'name' => 'Renamed',
                'email' => 'renamed@example.com',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertCount(1, $spy->calls);
        $this->assertSame($member->getKey(), $spy->calls[0][0]);
        $this->assertSame('Renamed', $spy->calls[0][1]['name']);
        $this->assertSame('renamed@example.com', $spy->calls[0][1]['email']);
        $this->assertArrayNotHasKey('password', $spy->calls[0][1]);
    }

    public function test_an_edit_save_leaves_the_password_hash_untouched(): void
    {
        $member = $this->member();
        $before = $member->getRawOriginal('password');

        Livewire::actingAs($this->staff())->test(EditUser::class, ['record' => $member->getKey()])
            ->fillForm(['name' => 'Renamed'])
            ->call('save')
            ->assertHasNoFormErrors();

        $member->refresh();

        $this->assertSame('Renamed', $member->name);
        $this->assertSame($before, $member->getRawOriginal('password'));
    }

    public function test_changing_the_email_resets_verification_and_resends_it(): void
    {
        config()->set('magic-starter.features', [Features::emailVerification()]);
        Notification::fake();
        $model = $this->notifiableUserModel();
        $member = $model::query()->create([
            'name' => 'Member',
            'email' => 'member@example.com',
            'password' => 'secret',
            'email_verified_at' => Carbon::now(),
        ]);

        Livewire::actingAs($this->staff())->test(EditUser::class, ['record' => $member->getKey()])
            ->fillForm(['email' => 'moved@example.com'])
            ->call('save')
            ->assertHasNoFormErrors();

        $member->refresh();

        $this->assertSame('moved@example.com', $member->email);
        $this->assertNull($member->email_verified_at);
        Notification::assertSentTo($member, VerifyEmailNotification::class);
    }

    public function test_keeping_the_email_keeps_verification(): void
    {
        $member = $this->member();

        Livewire::actingAs($this->staff())->test(EditUser::class, ['record' => $member->getKey()])
            ->fillForm(['name' => 'Renamed'])
            ->call('save');

        $this->assertNotNull($member->refresh()->email_verified_at);
    }

    public function test_a_create_saves_through_the_contract_with_a_password_nobody_sees(): void
    {
        $spy = new class implements CreatesUsers
        {
            /**
             * @var list<array<string, mixed>>
             */
            public array $inputs = [];

            public function create(array $input): Authenticatable
            {
                $this->inputs[] = $input;

                return ConcreteAdminUser::query()->create([
                    'name' => $input['name'],
                    'email' => $input['email'],
                    'password' => $input['password'],
                ]);
            }
        };
        $this->app->instance(CreatesUsers::class, $spy);
        $page = Livewire::actingAs($this->staff())->test(CreateUser::class);

        $page->fillForm([
            'name' => 'Fresh',
            'email' => 'fresh@example.com',
        ])->call('create')->assertHasNoFormErrors();

        $page->assertSchemaComponentDoesNotExist('password', 'form');
        $this->assertCount(1, $spy->inputs);
        $this->assertSame('Fresh', $spy->inputs[0]['name']);
        $this->assertSame(64, strlen($spy->inputs[0]['password']));
    }

    public function test_every_created_user_gets_its_own_random_password(): void
    {
        $staff = $this->staff();

        foreach (['one', 'two'] as $name) {
            Livewire::actingAs($staff)->test(CreateUser::class)
                ->fillForm(['name' => $name, 'email' => "{$name}@example.com"])
                ->call('create')
                ->assertHasNoFormErrors();
        }

        $one = ConcreteAdminUser::query()->where('email', 'one@example.com')->firstOrFail();
        $two = ConcreteAdminUser::query()->where('email', 'two@example.com')->firstOrFail();

        $this->assertNotEmpty($one->getRawOriginal('password'));
        $this->assertNotSame($one->getRawOriginal('password'), $two->getRawOriginal('password'));
    }

    public function test_the_list_shows_the_columns_the_features_allow(): void
    {
        $member = $this->member();
        $staff = $this->staff();

        config()->set('magic-starter.features', []);
        Livewire::actingAs($staff)->test(ListUsers::class)
            ->assertCanSeeTableRecords([$member])
            ->assertTableColumnExists('name')
            ->assertTableColumnExists('email')
            ->assertTableColumnExists('email_verified_at')
            ->assertTableColumnExists('deletion_scheduled_at')
            ->assertTableColumnExists('created_at')
            ->assertTableColumnDoesNotExist('two_factor_confirmed_at')
            ->assertTableColumnDoesNotExist('is_guest')
            ->assertTableColumnDoesNotExist('teams_count');

        $this->enableAllFeatures();
        Livewire::actingAs($staff)->test(ListUsers::class)
            ->assertTableColumnExists('two_factor_confirmed_at')
            ->assertTableColumnExists('is_guest')
            ->assertTableColumnExists('teams_count');
    }

    public function test_the_list_filters_by_verified_scheduled_and_guest(): void
    {
        $this->enableAllFeatures();
        $verified = $this->member('verified@example.com');
        $unverified = $this->member('unverified@example.com', verified: false);
        $scheduled = $this->member('scheduled@example.com');
        $scheduled->forceFill(['deletion_scheduled_at' => Carbon::now()])->save();
        $guest = $this->member('guest@example.com');
        $guest->forceFill(['is_guest' => true])->save();
        $page = Livewire::actingAs($this->staff())->test(ListUsers::class);

        $page->filterTable('verified', false)
            ->assertCanSeeTableRecords([$unverified])
            ->assertCanNotSeeTableRecords([$verified]);

        $page->filterTable('verified', null)->filterTable('scheduled', true)
            ->assertCanSeeTableRecords([$scheduled])
            ->assertCanNotSeeTableRecords([$verified]);

        $page->filterTable('scheduled', null)->filterTable('guest', true)
            ->assertCanSeeTableRecords([$guest])
            ->assertCanNotSeeTableRecords([$verified]);
    }

    public function test_navigation_is_a_url_action_and_nothing_deletes_or_acts_in_bulk(): void
    {
        $member = $this->member();
        $list = Livewire::actingAs($this->staff())->test(ListUsers::class);

        $list->assertActionExists(
            'create',
            static fn (Action $action): bool => $action->getUrl() === UserResource::getUrl('create'),
        )->assertTableActionExists(
            'edit',
            static fn (Action $action): bool => $action->getUrl()
                === UserResource::getUrl('edit', ['record' => $member]),
            $member,
        );

        $table = $list->instance()->getTable();

        $this->assertSame([], $table->getBulkActions());
        $this->assertSame([], $table->getToolbarActions());
        $this->assertNull($table->getAction('delete'));
    }

    public function test_the_relation_managers_follow_their_features(): void
    {
        config()->set('magic-starter.features', []);
        $this->assertSame([], UserResource::getRelations());

        $this->enableAllFeatures();
        $this->assertSame([
            TokensRelationManager::class,
            SocialAccountsRelationManager::class,
            PushDevicesRelationManager::class,
            TeamsRelationManager::class,
        ], UserResource::getRelations());
    }

    /**
     * A config published before `billing.billable` existed bills users, as
     * the rest of the package reads it, so the tab still shows.
     */
    public function test_the_billing_tab_defaults_to_users_when_the_billable_key_is_absent(): void
    {
        config()->set('magic-starter.features', [Features::billing()]);
        config()->set('magic-starter.billing', ['tier_order' => []]);

        $this->assertSame([BillingRelationManager::class], UserResource::getRelations());
    }

    public function test_push_devices_are_hidden_for_a_user_without_the_relation(): void
    {
        $this->assertTrue(PushDevicesRelationManager::canViewForRecord($this->member(), EditUser::class));
        $this->assertFalse(PushDevicesRelationManager::canViewForRecord(new ConcreteUser, EditUser::class));
    }

    public function test_the_resource_resolves_the_configured_user_model(): void
    {
        $this->assertSame(ConcreteAdminUser::class, UserResource::getModel());

        MagicStarter::useUserModel(ConcreteUser::class);

        $this->assertSame(ConcreteUser::class, UserResource::getModel());
    }

    private function enableAllFeatures(): void
    {
        config()->set('magic-starter.features', [
            Features::twoFactorAuthentication(),
            Features::extendedProfile(),
            Features::timezones(),
            Features::socialLogin(),
            Features::onesignal(),
            Features::teams(),
            Features::guestAuth(),
            Features::sessions(),
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

    private function member(string $email = 'member@example.com', bool $verified = true): ConcreteAdminUser
    {
        return ConcreteAdminUser::query()->create([
            'name' => 'Member',
            'email' => $email,
            'password' => 'secret',
            'email_verified_at' => $verified ? Carbon::now() : null,
        ]);
    }

    /**
     * The fixture user cannot notify; a real verification mail needs a user that can.
     *
     * @return class-string<ConcreteAdminUser>
     */
    private function notifiableUserModel(): string
    {
        $model = (new class extends ConcreteAdminUser
        {
            use RoutesNotifications;
        })::class;

        MagicStarter::useUserModel($model);

        return $model;
    }
}
