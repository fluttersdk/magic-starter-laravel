<?php

namespace FlutterSdk\MagicStarter\Tests\Filament\Teams;

use Filament\Forms\Components\Select;
use Filament\Notifications\Notification as FilamentNotification;
use FlutterSdk\MagicStarter\Events\AdminActionPerformed;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Filament\Resources\Teams\Pages\EditTeam;
use FlutterSdk\MagicStarter\Filament\Resources\Teams\RelationManagers\InvitationsRelationManager;
use FlutterSdk\MagicStarter\Filament\Resources\Teams\RelationManagers\MembersRelationManager;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Notifications\TeamInvitationNotification;
use FlutterSdk\MagicStarter\Tests\Filament\FilamentTestCase;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteAdminUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * The team's members and invitations relation managers.
 *
 * Every write goes through a package contract, so the cases that matter most
 * are the refusals. The owner's row hides the actions that would hurt, and the
 * refusal tests show them anyway to prove the contract is what says no.
 */
class TeamMembersTest extends FilamentTestCase
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

        // ConcreteTeam derives `concrete_team_id` as the invitations foreign key,
        // where an application's `Team` model derives `team_id`.
        Schema::table('team_invitations', function ($table): void {
            $table->renameColumn('team_id', 'concrete_team_id');
        });

        config()->set('magic-starter.admin.emails', ['ops@example.com']);
    }

    public function test_the_members_table_lists_each_member_with_the_stored_role(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        $admin = $this->member($team, 'admin@example.com', 'admin');

        $this->members($team)
            ->assertCanSeeTableRecords([$owner, $admin])
            ->assertTableColumnStateSet('email', 'admin@example.com', $admin)
            ->assertTableColumnStateSet('role', 'admin', $admin)
            ->assertTableColumnStateSet('role', 'owner', $owner);
    }

    public function test_a_member_is_added_by_email_through_the_contract(): void
    {
        Event::fake([AdminActionPerformed::class]);
        [$team] = $this->teamWithOwner();
        $user = $this->user('new@example.com');

        $this->members($team)
            ->callTableAction('add', data: [
                'email' => 'New@Example.com',
                'role' => 'editor',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame('editor', $team->users()->find($user->getKey())->pivot->role);
        Event::assertDispatched(
            AdminActionPerformed::class,
            static fn (AdminActionPerformed $event): bool => $event->action === 'team.member_added',
        );
    }

    public function test_adding_an_unknown_email_notifies_and_attaches_nothing(): void
    {
        [$team] = $this->teamWithOwner();

        $this->members($team)->callTableAction('add', data: [
            'email' => 'nobody@example.com',
            'role' => 'member',
        ]);

        FilamentNotification::assertNotified(__('magic-starter::teams.members.user_not_found'));
        $this->assertSame(1, $team->users()->count());
    }

    public function test_the_role_select_offers_only_the_assignable_roles(): void
    {
        [$team] = $this->teamWithOwner();

        $this->members($team)
            ->mountTableAction('add')
            ->assertFormFieldExists('role', 'mountedActionSchema0', static fn (Select $field): bool => array_keys(
                $field->getOptions(),
            ) === ['admin', 'editor', 'member']);
    }

    public function test_a_members_role_is_changed_through_the_contract(): void
    {
        Event::fake([AdminActionPerformed::class]);
        [$team] = $this->teamWithOwner();
        $member = $this->member($team, 'member@example.com', 'member');

        $this->members($team)
            ->callTableAction('changeRole', $member, data: [
                'role' => 'admin',
            ]);

        $this->assertSame('admin', $this->pivotRole($team, $member));
        Event::assertDispatched(
            AdminActionPerformed::class,
            static fn (AdminActionPerformed $event): bool => $event->action === 'team.member_role_updated',
        );
    }

    public function test_a_member_is_removed_through_the_contract(): void
    {
        Event::fake([AdminActionPerformed::class]);
        [$team] = $this->teamWithOwner();
        $member = $this->member($team, 'member@example.com', 'member');

        $this->members($team)->callTableAction('remove', $member);

        $this->assertNull($this->pivotRole($team, $member));
        Event::assertDispatched(
            AdminActionPerformed::class,
            static fn (AdminActionPerformed $event): bool => $event->action === 'team.member_removed',
        );
    }

    public function test_the_owner_row_offers_no_remove_role_or_transfer_action(): void
    {
        [$team, $owner] = $this->teamWithOwner();

        $this->members($team)
            ->assertTableActionHidden('remove', $owner)
            ->assertTableActionHidden('changeRole', $owner)
            ->assertTableActionHidden('makeOwner', $owner);
    }

    public function test_removing_the_owner_through_the_relation_manager_leaves_the_membership_and_notifies_danger(): void
    {
        Event::fake([AdminActionPerformed::class]);
        [$team, $owner] = $this->teamWithOwner();

        $this->membersWithOwnerActionsShown($team)->callTableAction('remove', $owner);

        FilamentNotification::assertNotified(__('magic-starter::teams.members.owner_not_removable'));
        $this->assertSame('owner', $this->pivotRole($team, $owner));
        Event::assertNotDispatched(AdminActionPerformed::class);
    }

    public function test_changing_the_owners_role_is_refused(): void
    {
        [$team, $owner] = $this->teamWithOwner();

        $this->membersWithOwnerActionsShown($team)->callTableAction('changeRole', $owner, data: [
            'role' => 'member',
        ]);

        FilamentNotification::assertNotified(__('magic-starter::teams.members.owner_role_locked'));
        $this->assertSame('owner', $this->pivotRole($team, $owner));
    }

    public function test_ownership_moves_to_the_chosen_member_and_the_outgoing_owner_becomes_an_admin(): void
    {
        Event::fake([AdminActionPerformed::class]);
        [$team, $owner] = $this->teamWithOwner();
        $successor = $this->member($team, 'successor@example.com', 'editor');

        $this->members($team)->callTableAction('makeOwner', $successor);

        $this->assertSame((string) $successor->getKey(), (string) $team->fresh()->user_id);
        $this->assertSame('owner', $this->pivotRole($team, $successor));
        $this->assertSame('admin', $this->pivotRole($team, $owner));
        Event::assertDispatched(
            AdminActionPerformed::class,
            static fn (AdminActionPerformed $event): bool => $event->action === 'team.ownership_transferred',
        );
    }

    public function test_the_invitations_table_marks_only_expired_invitations(): void
    {
        [$team] = $this->teamWithOwner();
        $live = $this->invitation($team, 'live@example.com', now()->addDay());
        $lapsed = $this->invitation($team, 'lapsed@example.com', now()->subDay());

        $this->invitations($team)
            ->assertCanSeeTableRecords([$live, $lapsed])
            ->assertTableColumnStateSet('status', null, $live)
            ->assertTableColumnStateSet('status', __('magic-starter::admin_teams.invitations.status.expired'), $lapsed);
    }

    public function test_an_invitation_is_sent_through_the_contract(): void
    {
        Notification::fake();
        Event::fake([AdminActionPerformed::class]);
        [$team] = $this->teamWithOwner();

        $this->invitations($team)->callTableAction('invite', data: [
            'email' => 'Guest@Example.com',
            'role' => 'member',
        ]);

        $this->assertSame(['guest@example.com'], $team->invitations()->pluck('email')->all());
        Notification::assertSentOnDemand(TeamInvitationNotification::class);
        Event::assertDispatched(
            AdminActionPerformed::class,
            static fn (AdminActionPerformed $event): bool => $event->action === 'team.invitation_sent',
        );
    }

    public function test_inviting_an_address_that_already_has_an_invitation_notifies_instead_of_crashing(): void
    {
        Notification::fake();
        [$team] = $this->teamWithOwner();
        $this->invitation($team, 'guest@example.com', now()->addDay());

        $this->invitations($team)->callTableAction('invite', data: [
            'email' => 'guest@example.com',
            'role' => 'member',
        ]);

        FilamentNotification::assertNotified(__('magic-starter::teams.invitations.already_sent'));
        $this->assertSame(1, $team->invitations()->count());
    }

    public function test_inviting_an_existing_member_notifies_instead_of_sending(): void
    {
        Notification::fake();
        [$team] = $this->teamWithOwner();
        $this->member($team, 'member@example.com', 'member');

        $this->invitations($team)->callTableAction('invite', data: [
            'email' => 'member@example.com',
            'role' => 'member',
        ]);

        FilamentNotification::assertNotified(__('magic-starter::teams.members.already_a_member'));
        $this->assertSame(0, $team->invitations()->count());
        Notification::assertNothingSent();
    }

    public function test_an_invitation_is_canceled_through_the_contract(): void
    {
        Event::fake([AdminActionPerformed::class]);
        [$team] = $this->teamWithOwner();
        $invitation = $this->invitation($team, 'guest@example.com', now()->addDay());

        $this->invitations($team)->callTableAction('cancel', $invitation);

        $this->assertModelMissing($invitation);
        Event::assertDispatched(
            AdminActionPerformed::class,
            static fn (AdminActionPerformed $event): bool => $event->action === 'team.invitation_cancelled',
        );
    }

    public function test_an_invitation_is_resent_with_a_fresh_expiry_window(): void
    {
        Notification::fake();
        Event::fake([AdminActionPerformed::class]);
        [$team] = $this->teamWithOwner();
        $invitation = $this->invitation($team, 'guest@example.com', now()->subDay());

        $this->invitations($team)->callTableAction('resend', $invitation);

        $this->assertTrue($invitation->fresh()->expires_at->isFuture());
        Notification::assertSentOnDemand(TeamInvitationNotification::class);
        Event::assertDispatched(
            AdminActionPerformed::class,
            static fn (AdminActionPerformed $event): bool => $event->action === 'team.invitation_resent',
        );
    }

    public function test_neither_relation_manager_carries_a_bulk_action(): void
    {
        [$team] = $this->teamWithOwner();

        $this->assertSame([], $this->members($team)->instance()->getTable()->getBulkActions());
        $this->assertSame([], $this->invitations($team)->instance()->getTable()->getBulkActions());
    }

    private function members(Model $team): Testable
    {
        return Livewire::actingAs($this->staff())->test(MembersRelationManager::class, [
            'ownerRecord' => $team,
            'pageClass' => EditTeam::class,
        ]);
    }

    /**
     * The members manager with the owner's row actions shown, so the refusal
     * under test is the contract's rather than the hidden action's.
     */
    private function membersWithOwnerActionsShown(Model $team): Testable
    {
        $manager = new class extends MembersRelationManager
        {
            protected function isOwner(Model $member): bool
            {
                return false;
            }
        };

        return Livewire::actingAs($this->staff())->test($manager::class, [
            'ownerRecord' => $team,
            'pageClass' => EditTeam::class,
        ]);
    }

    private function invitations(Model $team): Testable
    {
        return Livewire::actingAs($this->staff())->test(InvitationsRelationManager::class, [
            'ownerRecord' => $team,
            'pageClass' => EditTeam::class,
        ]);
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
     * @return array{0: Model, 1: ConcreteAdminUser}
     */
    private function teamWithOwner(): array
    {
        $owner = $this->user('owner@example.com');
        $teamModel = MagicStarter::teamModel();
        $team = $teamModel::query()->create([
            'user_id' => $owner->getKey(),
            'name' => 'Acme',
            'personal_team' => false,
        ]);
        $team->users()->attach($owner->getKey(), ['role' => 'owner']);

        return [$team, $owner];
    }

    private function member(Model $team, string $email, string $role): ConcreteAdminUser
    {
        $user = $this->user($email);
        $team->users()->attach($user->getKey(), ['role' => $role]);

        return $user;
    }

    private function invitation(Model $team, string $email, mixed $expiresAt): Model
    {
        return $team->invitations()->create([
            'email' => $email,
            'role' => 'member',
            'token' => Str::random(32),
            'expires_at' => $expiresAt,
        ]);
    }

    private function pivotRole(Model $team, Model $user): ?string
    {
        return $team->users()->find($user->getKey())?->pivot->role;
    }
}
