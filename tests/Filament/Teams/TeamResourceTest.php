<?php

namespace FlutterSdk\MagicStarter\Tests\Filament\Teams;

use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use FlutterSdk\MagicStarter\Contracts\DeletesTeams;
use FlutterSdk\MagicStarter\Events\AdminActionPerformed;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Filament\Resources\Teams\Pages\EditTeam;
use FlutterSdk\MagicStarter\Filament\Resources\Teams\Pages\ListTeams;
use FlutterSdk\MagicStarter\Filament\Resources\Teams\TeamResource;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\Filament\FilamentTestCase;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteAdminUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * The team resource: registration, the name-only form, the contract-backed save
 * and delete, and the list.
 *
 * Fixture classes are anonymous and built inside methods, never file-level
 * classes: the no-Filament CI job loads this file.
 */
class TeamResourceTest extends FilamentTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('magic-starter.features', [
            Features::teams(),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The billing columns live on the billable table, which this suite's
        // migrations point at users; the resource only reads them off the team.
        Schema::table('teams', function ($table): void {
            $table->string('plan')->nullable();
            $table->string('plan_status')->nullable();
        });

        // ConcreteTeam derives `concrete_team_id` as the invitations foreign key,
        // where an application's `Team` model derives `team_id`.
        Schema::table('team_invitations', function ($table): void {
            $table->renameColumn('team_id', 'concrete_team_id');
        });

        config()->set('magic-starter.admin.emails', ['ops@example.com']);
    }

    public function test_the_resource_is_mounted_on_the_panel_when_teams_are_enabled(): void
    {
        $this->assertContains(TeamResource::class, Filament::getPanel('admin')->getResources());
        $this->assertSame(MagicStarter::teamModel(), TeamResource::getModel());
    }

    public function test_the_resource_has_no_create_page(): void
    {
        $this->assertFalse(TeamResource::hasPage('create'));
        $this->assertTrue(TeamResource::hasPage('edit'));
    }

    public function test_the_table_lists_name_owner_email_personal_flag_and_member_count(): void
    {
        $owner = $this->user('owner@example.com');
        $team = $this->team($owner, ['name' => 'Acme', 'personal_team' => false]);
        $team->users()->attach($owner->getKey(), ['role' => 'owner']);
        $team->users()->attach($this->user('a@example.com')->getKey(), ['role' => 'member']);
        $team->users()->attach($this->user('b@example.com')->getKey(), ['role' => 'member']);

        Livewire::actingAs($this->staff())
            ->test(ListTeams::class)
            ->assertCanSeeTableRecords([$team])
            ->assertTableColumnStateSet('name', 'Acme', $team)
            ->assertTableColumnStateSet('owner.email', 'owner@example.com', $team)
            ->assertTableColumnStateSet('personal_team', false, $team)
            ->assertTableColumnStateSet('users_count', 3, $team);
    }

    public function test_the_plan_columns_follow_the_billing_feature(): void
    {
        $team = $this->team($this->user('owner@example.com'));

        $off = Livewire::actingAs($this->staff())->test(ListTeams::class);
        $off->assertTableColumnHidden('plan')->assertTableColumnHidden('plan_status');

        config()->set('magic-starter.features', [
            Features::teams(),
            Features::billing(),
        ]);

        Livewire::actingAs($this->staff())
            ->test(ListTeams::class)
            ->assertTableColumnVisible('plan')
            ->assertTableColumnVisible('plan_status')
            ->assertCanSeeTableRecords([$team]);
    }

    public function test_the_list_has_no_create_action_and_the_table_no_bulk_actions(): void
    {
        $component = Livewire::actingAs($this->staff())->test(ListTeams::class);

        $component->assertActionDoesNotExist('create');
        $this->assertSame([], $component->instance()->getTable()->getBulkActions());
    }

    public function test_the_form_edits_only_the_team_name(): void
    {
        $team = $this->team($this->user('owner@example.com'));

        Livewire::actingAs($this->staff())
            ->test(EditTeam::class, ['record' => $team->getKey()])
            ->assertFormFieldExists('name')
            ->assertFormFieldDoesNotExist('user_id')
            ->assertFormFieldDoesNotExist('personal_team')
            ->assertFormFieldDoesNotExist('plan')
            ->assertFormFieldDoesNotExist('plan_status');
    }

    public function test_saving_renames_the_team_through_the_contract_and_records_the_action(): void
    {
        Event::fake([AdminActionPerformed::class]);
        $staff = $this->staff();
        $team = $this->team($this->user('owner@example.com'), ['name' => 'Before']);

        Livewire::actingAs($staff)
            ->test(EditTeam::class, ['record' => $team->getKey()])
            ->fillForm(['name' => 'After'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('After', $team->fresh()->name);
        Event::assertDispatched(
            AdminActionPerformed::class,
            static fn (AdminActionPerformed $event): bool => $event->action === 'team.updated'
                && $event->actor->is($staff)
                && $event->subject?->is($team) === true,
        );
    }

    public function test_a_blank_name_is_refused_before_anything_is_written(): void
    {
        $team = $this->team($this->user('owner@example.com'), ['name' => 'Kept']);

        Livewire::actingAs($this->staff())
            ->test(EditTeam::class, ['record' => $team->getKey()])
            ->fillForm(['name' => ''])
            ->call('save')
            ->assertHasFormErrors(['name' => 'required']);

        $this->assertSame('Kept', $team->fresh()->name);
    }

    public function test_a_save_with_injected_owner_and_plan_keys_leaves_both_columns_unchanged(): void
    {
        $owner = $this->user('owner@example.com');
        $intruder = $this->user('intruder@example.com');
        $team = $this->team($owner, ['name' => 'Before']);
        $team->forceFill(['plan' => 'free', 'plan_status' => 'active'])->save();

        Livewire::actingAs($this->staff())
            ->test(EditTeam::class, ['record' => $team->getKey()])
            ->fillForm(['name' => 'After'])
            ->set('data.user_id', $intruder->getKey())
            ->set('data.plan', 'enterprise')
            ->set('data.plan_status', 'canceled')
            ->call('save');

        $team = $team->fresh();

        $this->assertSame('After', $team->name);
        $this->assertSame((string) $owner->getKey(), (string) $team->user_id);
        $this->assertSame('free', $team->plan);
        $this->assertSame('active', $team->plan_status);
    }

    public function test_the_save_hook_passes_only_the_name_to_the_contract(): void
    {
        $owner = $this->user('owner@example.com');
        $team = $this->team($owner, ['name' => 'Before']);
        $team->forceFill(['plan' => 'free'])->save();

        $this->actingAs($staff = $this->staff());

        TeamResource::updateRecordUsing($team, [
            'name' => 'After',
            'user_id' => $this->user('intruder@example.com')->getKey(),
            'plan' => 'enterprise',
        ], $staff);

        $team = $team->fresh();

        $this->assertSame('After', $team->name);
        $this->assertSame((string) $owner->getKey(), (string) $team->user_id);
        $this->assertSame('free', $team->plan);
    }

    public function test_the_header_delete_removes_the_team_through_the_contract(): void
    {
        Event::fake([AdminActionPerformed::class]);
        $owner = $this->user('owner@example.com');
        $team = $this->team($owner);
        $team->users()->attach($owner->getKey(), ['role' => 'owner']);

        Livewire::actingAs($this->staff())
            ->test(EditTeam::class, ['record' => $team->getKey()])
            ->callAction('delete')
            ->assertRedirect(TeamResource::getUrl('index'));

        $this->assertModelMissing($team);
        $this->assertDatabaseMissing('team_user', ['team_id' => $team->getKey()]);
        Event::assertDispatched(
            AdminActionPerformed::class,
            static fn (AdminActionPerformed $event): bool => $event->action === 'team.deleted',
        );
    }

    public function test_a_delete_refusal_notifies_and_keeps_the_team(): void
    {
        Event::fake([AdminActionPerformed::class]);
        $this->app->bind(DeletesTeams::class, fn (): DeletesTeams => new class implements DeletesTeams
        {
            public function delete(Model $team): void
            {
                throw ValidationException::withMessages([
                    'team' => 'A subscription still funds this team.',
                ]);
            }
        });
        $team = $this->team($this->user('owner@example.com'));

        Livewire::actingAs($this->staff())
            ->test(EditTeam::class, ['record' => $team->getKey()])
            ->callAction('delete')
            ->assertNoRedirect();

        Notification::assertNotified('A subscription still funds this team.');
        $this->assertModelExists($team);
        Event::assertNotDispatched(AdminActionPerformed::class);
    }

    private function staff(): ConcreteAdminUser
    {
        return ConcreteAdminUser::query()->where('email', 'ops@example.com')->first() ?? $this->user('ops@example.com');
    }

    private function user(string $email): ConcreteAdminUser
    {
        $user = new ConcreteAdminUser;
        $user->forceFill([
            'name' => ucfirst(strtok($email, '@')),
            'email' => $email,
            'password' => 'secret',
            'email_verified_at' => now(),
        ])->save();

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function team(ConcreteAdminUser $owner, array $attributes = []): Model
    {
        $teamModel = MagicStarter::teamModel();

        return $teamModel::query()->create([
            'user_id' => $owner->getKey(),
            'name' => 'Acme',
            'personal_team' => false,
            ...$attributes,
        ]);
    }
}
