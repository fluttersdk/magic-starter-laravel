<?php

namespace FlutterSdk\MagicStarter\Tests\Events;

use ArrayObject;
use Carbon\CarbonInterface;
use FlutterSdk\MagicStarter\Actions\AcceptTeamInvitation;
use FlutterSdk\MagicStarter\Actions\AddTeamMember;
use FlutterSdk\MagicStarter\Actions\DeleteTeam;
use FlutterSdk\MagicStarter\Actions\RemoveTeamMember;
use FlutterSdk\MagicStarter\Actions\TransferTeamOwnership;
use FlutterSdk\MagicStarter\Actions\TrashTeam;
use FlutterSdk\MagicStarter\Contracts\DeletesTeams;
use FlutterSdk\MagicStarter\Contracts\RemovesTeamMembers;
use FlutterSdk\MagicStarter\Contracts\TransfersTeamOwnership;
use FlutterSdk\MagicStarter\Contracts\TrashesTeams;
use FlutterSdk\MagicStarter\Enums\Role;
use FlutterSdk\MagicStarter\Events\TeamDeleted;
use FlutterSdk\MagicStarter\Events\TeamMemberJoined;
use FlutterSdk\MagicStarter\Events\TeamMemberRemoved;
use FlutterSdk\MagicStarter\Events\TeamOwnershipTransferred;
use FlutterSdk\MagicStarter\Events\TeamRestored;
use FlutterSdk\MagicStarter\Events\TeamTrashed;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\Fixtures\BuildsRestorableTeamSchema;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeamInvitation;
use FlutterSdk\MagicStarter\Tests\Fixtures\RestorableTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\RestorableTeamUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;

/**
 * The team lifecycle events a host listens to so it can tell people when a
 * member joins, a household is deleted or ownership passes.
 *
 * Each event fires once, from the default action, after the change committed,
 * and an action that refuses fires nothing. The payload is keys and plain
 * scalars, so a queued listener holds no model and no personal data.
 */
final class TeamLifecycleEventsTest extends TestCase
{
    use BuildsRestorableTeamSchema;

    private const EVENTS = [
        TeamMemberJoined::class,
        TeamMemberRemoved::class,
        TeamDeleted::class,
        TeamTrashed::class,
        TeamRestored::class,
        TeamOwnershipTransferred::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildRestorableTeamSchema();

        // `AcceptTeamInvitation` reads the invitation's `team_id`; the shared schema keys on the model's own name.
        Schema::table('team_invitations', function (Blueprint $table): void {
            $table->uuid('team_id')->nullable();
        });

        Storage::fake('public');
        Carbon::setTestNow('2026-10-29 12:00:00');

        // Only the lifecycle events: the models' own events still generate keys.
        Event::fake(self::EVENTS);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // Joined.

    public function test_adding_a_member_dispatches_joined_once_with_the_ids(): void
    {
        $owner = $this->makeUser();
        $newcomer = $this->makeUser('Newcomer');
        $team = $this->makeTeam($owner);

        (new AddTeamMember)->add($owner, $team, 'newcomer@example.test', 'member');

        $this->assertEquals([
            new TeamMemberJoined(
                teamId: (string) $team->getKey(),
                userId: (string) $newcomer->getKey(),
                actorId: (string) $owner->getKey(),
            ),
        ], $this->dispatched(TeamMemberJoined::class));
    }

    public function test_adding_an_unknown_email_dispatches_nothing(): void
    {
        $owner = $this->makeUser();
        $team = $this->makeTeam($owner);

        $this->assertRefused(fn () => (new AddTeamMember)->add($owner, $team, 'nobody@example.test', 'member'));

        Event::assertNotDispatched(TeamMemberJoined::class);
    }

    public function test_adding_someone_who_already_belongs_dispatches_nothing(): void
    {
        $owner = $this->makeUser();
        $member = $this->makeUser('Member');
        $team = $this->makeTeam($owner);
        $this->addMember($team, $member);

        $this->assertRefused(fn () => (new AddTeamMember)->add($owner, $team, 'member@example.test', 'member'));

        Event::assertNotDispatched(TeamMemberJoined::class);
    }

    public function test_accepting_an_invitation_dispatches_joined_once_with_the_joiner_as_the_actor(): void
    {
        $owner = $this->makeUser();
        $invitee = $this->makeUser('Invitee');
        $team = $this->makeTeam($owner);
        $invitation = $this->invite($team, $invitee);

        (new AcceptTeamInvitation)->accept($invitee, $invitation);

        $this->assertEquals([
            new TeamMemberJoined(
                teamId: (string) $team->getKey(),
                userId: (string) $invitee->getKey(),
                actorId: (string) $invitee->getKey(),
            ),
        ], $this->dispatched(TeamMemberJoined::class));
    }

    public function test_accepting_when_already_a_member_dispatches_nothing(): void
    {
        $owner = $this->makeUser();
        $member = $this->makeUser('Member');
        $team = $this->makeTeam($owner);
        $this->addMember($team, $member);
        $invitation = $this->invite($team, $member);

        $this->assertRefused(fn () => (new AcceptTeamInvitation)->accept($member, $invitation));

        Event::assertNotDispatched(TeamMemberJoined::class);
    }

    public function test_accepting_an_expired_invitation_dispatches_nothing(): void
    {
        $owner = $this->makeUser();
        $invitee = $this->makeUser('Invitee');
        $team = $this->makeTeam($owner);
        $invitation = $this->invite($team, $invitee, expiresAt: now()->subDay());

        $this->assertRefused(fn () => (new AcceptTeamInvitation)->accept($invitee, $invitation));

        Event::assertNotDispatched(TeamMemberJoined::class);
    }

    public function test_a_join_the_hook_refuses_dispatches_nothing(): void
    {
        $owner = $this->makeUser();
        $invitee = $this->makeUser('Invitee');
        $team = $this->makeTeam($owner);
        $invitation = $this->invite($team, $invitee);
        $action = new class extends AcceptTeamInvitation
        {
            protected function ensureCanJoin(Model $team, Authenticatable $user): void
            {
                throw ValidationException::withMessages([
                    'team' => 'This household is full.',
                ]);
            }
        };

        $this->assertRefused(fn () => $action->accept($invitee, $invitation));

        Event::assertNotDispatched(TeamMemberJoined::class);
    }

    // Removed.

    public function test_removing_a_member_dispatches_removed_once_with_the_remover_as_the_actor(): void
    {
        $owner = $this->makeUser();
        $member = $this->makeUser('Member');
        $team = $this->makeTeam($owner);
        $this->addMember($team, $member);

        $this->app->make(RemovesTeamMembers::class)->remove($owner, $team, $member);

        $this->assertEquals([
            new TeamMemberRemoved(
                teamId: (string) $team->getKey(),
                userId: (string) $member->getKey(),
                actorId: (string) $owner->getKey(),
            ),
        ], $this->dispatched(TeamMemberRemoved::class));
    }

    public function test_leaving_dispatches_removed_with_the_member_as_the_actor(): void
    {
        $owner = $this->makeUser();
        $member = $this->makeUser('Member');
        $team = $this->makeTeam($owner);
        $this->addMember($team, $member);

        $this->app->make(RemovesTeamMembers::class)->remove($member, $team, $member);

        $this->assertEquals([
            new TeamMemberRemoved(
                teamId: (string) $team->getKey(),
                userId: (string) $member->getKey(),
                actorId: (string) $member->getKey(),
            ),
        ], $this->dispatched(TeamMemberRemoved::class));
    }

    public function test_removing_the_owner_dispatches_nothing(): void
    {
        $owner = $this->makeUser();
        $team = $this->makeTeam($owner);

        $this->assertRefused(fn () => (new RemoveTeamMember)->remove($owner, $team, $owner));

        Event::assertNotDispatched(TeamMemberRemoved::class);
    }

    public function test_removing_someone_who_is_not_a_member_dispatches_nothing(): void
    {
        $owner = $this->makeUser();
        $stranger = $this->makeUser('Stranger');
        $team = $this->makeTeam($owner);

        (new RemoveTeamMember)->remove($owner, $team, $stranger);

        Event::assertNotDispatched(TeamMemberRemoved::class);
    }

    // Deleted.

    public function test_the_purge_dispatches_deleted_once_carrying_every_former_member(): void
    {
        $owner = $this->makeUser();
        $first = $this->makeUser('First');
        $second = $this->makeUser('Second');
        $team = $this->makeTeam($owner);
        $this->addMember($team, $first);
        $this->addMember($team, $second);

        $this->app->make(DeletesTeams::class)->delete($team);

        $events = $this->dispatched(TeamDeleted::class);

        $this->assertCount(1, $events);
        $this->assertSame((string) $team->getKey(), $events[0]->teamId);
        $this->assertSame((string) $owner->getKey(), $events[0]->ownerId);
        $this->assertEqualsCanonicalizing([
            (string) $owner->getKey(),
            (string) $first->getKey(),
            (string) $second->getKey(),
        ], $events[0]->memberIds);
        $this->assertSame(0, DB::table('team_user')->where('team_id', $team->getKey())->count());
    }

    public function test_deleted_lists_an_owner_who_has_no_membership_row(): void
    {
        $owner = $this->makeUser();
        $member = $this->makeUser('Member');
        $team = new RestorableTeam;
        $team->forceFill([
            'user_id' => $owner->getKey(),
            'name' => 'Home',
            'personal_team' => false,
        ])->save();
        $this->addMember($team, $member);

        (new DeleteTeam)->delete($team);

        $events = $this->dispatched(TeamDeleted::class);

        $this->assertCount(1, $events);
        $this->assertEqualsCanonicalizing([
            (string) $owner->getKey(),
            (string) $member->getKey(),
        ], $events[0]->memberIds);
    }

    public function test_a_trashed_team_that_is_purged_dispatches_deleted_with_its_members(): void
    {
        $owner = $this->makeUser();
        $member = $this->makeUser('Member');
        $team = $this->makeTeam($owner);
        $this->addMember($team, $member);
        (new TrashTeam)->trash($team);

        (new DeleteTeam)->delete(RestorableTeam::onlyTrashed()->findOrFail($team->getKey()));

        $events = $this->dispatched(TeamDeleted::class);

        $this->assertCount(1, $events);
        $this->assertSame((string) $team->getKey(), $events[0]->teamId);
        $this->assertEqualsCanonicalizing([
            (string) $owner->getKey(),
            (string) $member->getKey(),
        ], $events[0]->memberIds);
    }

    public function test_a_delete_the_billing_guard_refuses_dispatches_nothing(): void
    {
        config(['magic-starter.billing.billable' => 'team']);
        $team = $this->makeTeam($this->makeUser(), [
            'plan' => 'pro',
            'plan_status' => 'active',
            'plan_provider' => 'app_store',
        ]);

        $this->assertRefused(fn () => $this->app->make(DeletesTeams::class)->delete($team));

        Event::assertNotDispatched(TeamDeleted::class);
        $this->assertNotNull(RestorableTeam::query()->find($team->getKey()));
    }

    // Trashed and restored.

    public function test_trashing_dispatches_trashed_once(): void
    {
        $team = $this->makeTeam($this->makeUser());

        $this->app->make(TrashesTeams::class)->trash($team);

        $this->assertEquals([
            new TeamTrashed(teamId: (string) $team->getKey()),
        ], $this->dispatched(TeamTrashed::class));
        Event::assertNotDispatched(TeamDeleted::class);
    }

    public function test_restoring_dispatches_restored_once(): void
    {
        $team = $this->makeTeam($this->makeUser());
        (new TrashTeam)->trash($team);

        $this->app->make(TrashesTeams::class)->restore(RestorableTeam::onlyTrashed()->findOrFail($team->getKey()));

        $this->assertEquals([
            new TeamRestored(teamId: (string) $team->getKey()),
        ], $this->dispatched(TeamRestored::class));
    }

    public function test_restoring_a_live_team_dispatches_nothing(): void
    {
        $team = $this->makeTeam($this->makeUser());

        (new TrashTeam)->restore($team);

        Event::assertNotDispatched(TeamRestored::class);
    }

    public function test_restoring_after_the_window_closed_dispatches_nothing(): void
    {
        $team = $this->makeTeam($this->makeUser());
        (new TrashTeam)->trash($team);
        Carbon::setTestNow(now()->addDays(31));

        $this->assertRefused(
            fn () => (new TrashTeam)->restore(RestorableTeam::onlyTrashed()->findOrFail($team->getKey())),
        );

        Event::assertNotDispatched(TeamRestored::class);
    }

    public function test_a_trash_the_billing_guard_refuses_dispatches_nothing(): void
    {
        config(['magic-starter.billing.billable' => 'team']);
        $team = $this->makeTeam($this->makeUser(), [
            'plan' => 'pro',
            'plan_status' => 'active',
            'plan_provider' => 'app_store',
        ]);

        $this->assertRefused(fn () => $this->app->make(TrashesTeams::class)->trash($team));

        Event::assertNotDispatched(TeamTrashed::class);
    }

    public function test_trashing_a_team_model_without_soft_deletes_dispatches_nothing(): void
    {
        MagicStarter::useTeamModel(ConcreteTeam::class);
        $team = ConcreteTeam::query()->create([
            'user_id' => $this->makeUser()->getKey(),
            'name' => 'Home',
            'personal_team' => false,
        ]);

        try {
            (new TrashTeam)->trash($team);
            $this->fail('A model without SoftDeletes must be refused.');
        } catch (LogicException) {
            Event::assertNotDispatched(TeamTrashed::class);
        }
    }

    // Ownership.

    public function test_a_transfer_dispatches_transferred_once_with_both_owners_and_the_actor(): void
    {
        $owner = $this->makeUser();
        $heir = $this->makeUser('Heir');
        $team = $this->makeTeam($owner);
        $this->addMember($team, $heir);

        $this->app->make(TransfersTeamOwnership::class)->transfer($owner, $team, $heir);

        $this->assertEquals([
            new TeamOwnershipTransferred(
                teamId: (string) $team->getKey(),
                previousOwnerId: (string) $owner->getKey(),
                newOwnerId: (string) $heir->getKey(),
                actorId: (string) $owner->getKey(),
            ),
        ], $this->dispatched(TeamOwnershipTransferred::class));
    }

    public function test_a_transfer_names_the_actor_when_someone_else_performs_it(): void
    {
        $owner = $this->makeUser();
        $heir = $this->makeUser('Heir');
        $staff = $this->makeUser('Staff');
        $team = $this->makeTeam($owner);
        $this->addMember($team, $heir);

        $this->app->make(TransfersTeamOwnership::class)->transfer($staff, $team, $heir);

        $events = $this->dispatched(TeamOwnershipTransferred::class);

        $this->assertCount(1, $events);
        $this->assertSame((string) $staff->getKey(), $events[0]->actorId);
        $this->assertSame((string) $owner->getKey(), $events[0]->previousOwnerId);
    }

    public function test_a_transfer_to_a_non_member_dispatches_nothing(): void
    {
        $owner = $this->makeUser();
        $stranger = $this->makeUser('Stranger');
        $team = $this->makeTeam($owner);

        $this->assertRefused(fn () => (new TransferTeamOwnership)->transfer($owner, $team, $stranger));

        Event::assertNotDispatched(TeamOwnershipTransferred::class);
    }

    public function test_a_transfer_to_the_current_owner_changes_nothing_and_dispatches_nothing(): void
    {
        $owner = $this->makeUser();
        $team = $this->makeTeam($owner);

        (new TransferTeamOwnership)->transfer($owner, $team, $owner);

        Event::assertNotDispatched(TeamOwnershipTransferred::class);
        $this->assertSame('owner', $this->roleOf($team, $owner));
    }

    public function test_the_outgoing_owner_becomes_an_admin_by_default(): void
    {
        $owner = $this->makeUser();
        $heir = $this->makeUser('Heir');
        $team = $this->makeTeam($owner);
        $this->addMember($team, $heir);

        (new TransferTeamOwnership)->transfer($owner, $team, $heir);

        $this->assertSame('admin', $this->roleOf($team, $owner));
        $this->assertSame('owner', $this->roleOf($team, $heir));
    }

    public function test_an_overridden_outgoing_role_changes_the_demotion_and_the_event_still_fires(): void
    {
        $owner = $this->makeUser();
        $heir = $this->makeUser('Heir');
        $team = $this->makeTeam($owner);
        $this->addMember($team, $heir);
        $this->app->bind(TransfersTeamOwnership::class, TeamLifecycleEventsTestMemberDemotingTransfer::class);

        $this->app->make(TransfersTeamOwnership::class)->transfer($owner, $team, $heir);

        $this->assertSame('member', $this->roleOf($team, $owner));
        $this->assertSame('owner', $this->roleOf($team, $heir));
        $this->assertEquals([
            new TeamOwnershipTransferred(
                teamId: (string) $team->getKey(),
                previousOwnerId: (string) $owner->getKey(),
                newOwnerId: (string) $heir->getKey(),
                actorId: (string) $owner->getKey(),
            ),
        ], $this->dispatched(TeamOwnershipTransferred::class));
    }

    // Timing.

    /**
     * The purge calls these actions inside its own transaction: a listener
     * must hear of a change only once the whole of it has committed.
     */
    public function test_every_event_waits_for_the_enclosing_transaction_to_commit(): void
    {
        $owner = $this->makeUser();
        $added = $this->makeUser('Added');
        $invitee = $this->makeUser('Invitee');
        $leaver = $this->makeUser('Leaver');
        $heir = $this->makeUser('Heir');
        $team = $this->makeTeam($owner);
        $this->addMember($team, $leaver);
        $this->addMember($team, $heir);
        $invitation = $this->invite($team, $invitee);
        $this->useTheRealDispatcher();
        $heard = $this->listen();

        DB::transaction(function () use ($owner, $added, $invitee, $leaver, $heir, $team, $invitation, $heard): void {
            (new AddTeamMember)->add($owner, $team, $added->email, 'member');
            (new AcceptTeamInvitation)->accept($invitee, $invitation);
            (new RemoveTeamMember)->remove($leaver, $team, $leaver);
            (new TransferTeamOwnership)->transfer($owner, $team, $heir);
            (new TrashTeam)->trash($team);
            (new TrashTeam)->restore(RestorableTeam::onlyTrashed()->findOrFail($team->getKey()));
            (new DeleteTeam)->delete(RestorableTeam::query()->findOrFail($team->getKey()));

            $this->assertSame([], $heard->getArrayCopy(), 'Nothing is heard before the outer commit.');
        });

        $this->assertSame([
            'TeamMemberJoined',
            'TeamMemberJoined',
            'TeamMemberRemoved',
            'TeamOwnershipTransferred',
            'TeamTrashed',
            'TeamRestored',
            'TeamDeleted',
        ], $heard->getArrayCopy());
    }

    public function test_a_rolled_back_transaction_never_reaches_the_listeners(): void
    {
        $owner = $this->makeUser();
        $added = $this->makeUser('Added');
        $team = $this->makeTeam($owner);
        $this->useTheRealDispatcher();
        $heard = $this->listen();

        try {
            DB::transaction(function () use ($owner, $added, $team): void {
                (new AddTeamMember)->add($owner, $team, $added->email, 'member');
                (new DeleteTeam)->delete($team);

                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame([], $heard->getArrayCopy());
        $this->assertNotNull(RestorableTeam::query()->find($team->getKey()));
    }

    /**
     * The setUp fake dispatches at once, so the commit deferral is only
     * observable on the application's own dispatcher.
     */
    private function useTheRealDispatcher(): void
    {
        Event::clearResolvedInstances();
        $this->app->forgetInstance('events');
    }

    /**
     * Record the short name of every lifecycle event as the dispatcher delivers it.
     *
     * @return ArrayObject<int, string> filled as events arrive
     */
    private function listen(): ArrayObject
    {
        $heard = new ArrayObject;

        foreach (self::EVENTS as $event) {
            Event::listen($event, function (object $dispatched) use ($heard): void {
                $heard->append(class_basename($dispatched));
            });
        }

        return $heard;
    }

    /**
     * The events of one class that the fake recorded, in dispatch order.
     *
     * @param  class-string  $event
     * @return list<object>
     */
    private function dispatched(string $event): array
    {
        return Event::dispatched($event)
            ->map(fn (array $call): object => $call[0])
            ->values()
            ->all();
    }

    /**
     * Run `$call`, which must refuse with a validation or invitation refusal.
     */
    private function assertRefused(callable $call): void
    {
        try {
            $call();
            $this->fail('The action should have refused.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    private function invite(RestorableTeam $team, RestorableTeamUser $user, ?CarbonInterface $expiresAt = null): ConcreteTeamInvitation
    {
        // `team_id` is not mass assignable, and the fixture team's own relation guesses another column.
        $invitation = (new ConcreteTeamInvitation)->forceFill([
            'team_id' => $team->getKey(),
            'email' => $user->email,
            'role' => 'member',
            'token' => 'token-' . $user->getKey(),
            'expires_at' => $expiresAt,
        ]);
        $invitation->save();

        return $invitation;
    }

    private function roleOf(RestorableTeam $team, RestorableTeamUser $user): ?string
    {
        return DB::table('team_user')
            ->where('team_id', $team->getKey())
            ->where('user_id', $user->getKey())
            ->value('role');
    }
}

/**
 * A host whose households have owners and members only: the outgoing owner is
 * a member afterwards, not the package's admin.
 */
class TeamLifecycleEventsTestMemberDemotingTransfer extends TransferTeamOwnership
{
    protected function outgoingRole(): Role
    {
        return Role::MEMBER;
    }
}
