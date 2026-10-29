<?php

namespace FlutterSdk\MagicStarter\Tests\Actions;

use FlutterSdk\MagicStarter\Actions\SubscriptionGuardedTrashTeam;
use FlutterSdk\MagicStarter\Actions\TrashTeam;
use FlutterSdk\MagicStarter\Contracts\TrashesTeams;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\Fixtures\BuildsRestorableTeamSchema;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\RestorableTeam;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The restorable half of a team delete: a trash hides the team for a window,
 * leaves its members attached, and a restore inside that window brings it back.
 */
final class TrashTeamTest extends TestCase
{
    use BuildsRestorableTeamSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildRestorableTeamSchema();

        Carbon::setTestNow('2026-10-29 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_contract_resolves_to_the_subscription_guarded_action(): void
    {
        $this->assertInstanceOf(SubscriptionGuardedTrashTeam::class, $this->app->make(TrashesTeams::class));
    }

    public function test_the_default_window_is_thirty_days(): void
    {
        $this->assertSame(30, config('magic-starter.teams.restore_days'));
    }

    public function test_trash_soft_deletes_the_team_and_closes_the_window_after_the_configured_days(): void
    {
        $team = $this->makeTeam($this->makeUser());

        (new TrashTeam)->trash($team);

        $trashed = RestorableTeam::onlyTrashed()->findOrFail($team->getKey());

        $this->assertTrue($trashed->trashed());
        $this->assertTrue($trashed->restore_until->equalTo(now()->addDays(30)));
    }

    public function test_trash_reads_the_configured_window(): void
    {
        config(['magic-starter.teams.restore_days' => 7]);
        $team = $this->makeTeam($this->makeUser());

        (new TrashTeam)->trash($team);

        $this->assertTrue(
            RestorableTeam::onlyTrashed()->findOrFail($team->getKey())->restore_until->equalTo(now()->addDays(7)),
        );
    }

    public function test_an_explicit_number_of_days_wins_over_the_configured_window(): void
    {
        $team = $this->makeTeam($this->makeUser());

        (new TrashTeam)->trash($team, 3);

        $this->assertTrue(
            RestorableTeam::onlyTrashed()->findOrFail($team->getKey())->restore_until->equalTo(now()->addDays(3)),
        );
    }

    public function test_a_trashed_team_disappears_from_every_members_list(): void
    {
        $owner = $this->makeUser();
        $member = $this->makeUser('Member');
        $team = $this->makeTeam($owner);
        $this->addMember($team, $member);
        $this->assertSame(1, $owner->ownedTeams()->count());
        $this->assertSame(1, $member->teams()->count());

        (new TrashTeam)->trash($team);

        $this->assertSame(0, $owner->ownedTeams()->count());
        $this->assertSame(0, $member->teams()->count());
        $this->assertNull(RestorableTeam::query()->find($team->getKey()));
    }

    public function test_trash_clears_the_pointer_of_every_user_on_the_team_and_only_theirs(): void
    {
        $owner = $this->makeUser();
        $member = $this->makeUser('Member');
        $bystander = $this->makeUser('Bystander');
        $team = $this->makeTeam($owner);
        $other = $this->makeTeam($bystander, ['name' => 'Elsewhere']);
        $this->addMember($team, $member);
        $this->addMember($other, $member);
        $this->pointAt($owner, $team);
        $this->pointAt($member, $team);
        $this->pointAt($bystander, $other);

        (new TrashTeam)->trash($team);

        $this->assertNull($this->pointerOf($owner));
        $this->assertNull($this->pointerOf($member));
        $this->assertSame((string) $other->getKey(), $this->pointerOf($bystander));
    }

    public function test_a_member_pointing_at_another_team_keeps_that_pointer(): void
    {
        $owner = $this->makeUser();
        $member = $this->makeUser('Member');
        $team = $this->makeTeam($owner);
        $other = $this->makeTeam($member, ['name' => 'Own place']);
        $this->addMember($team, $member);
        $this->pointAt($member, $other);

        (new TrashTeam)->trash($team);

        $this->assertSame((string) $other->getKey(), $this->pointerOf($member));
    }

    public function test_trash_keeps_the_members_attached(): void
    {
        $team = $this->makeTeam($this->makeUser());
        $this->addMember($team, $this->makeUser('Member'));

        (new TrashTeam)->trash($team);

        $this->assertSame(2, DB::table('team_user')->where('team_id', $team->getKey())->count());
    }

    public function test_trash_cancels_the_pending_invitations_of_the_team_and_only_its_own(): void
    {
        $owner = $this->makeUser();
        $team = $this->makeTeam($owner);
        $other = $this->makeTeam($owner, ['name' => 'Elsewhere']);
        $team->invitations()->create([
            'email' => 'guest@example.test',
            'role' => 'member',
            'token' => 'token-1',
        ]);
        $other->invitations()->create([
            'email' => 'other@example.test',
            'role' => 'member',
            'token' => 'token-2',
        ]);

        (new TrashTeam)->trash($team);

        $this->assertSame(['token-2'], DB::table('team_invitations')->pluck('token')->all());
    }

    public function test_trash_refuses_a_team_model_that_cannot_be_soft_deleted(): void
    {
        MagicStarter::useTeamModel(ConcreteTeam::class);
        $team = ConcreteTeam::query()->create([
            'user_id' => $this->makeUser()->getKey(),
            'name' => 'Home',
            'personal_team' => false,
        ]);

        try {
            (new TrashTeam)->trash($team);
            $this->fail('A model without SoftDeletes must be refused, not deleted for good.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString(ConcreteTeam::class, $exception->getMessage());
        }

        $this->assertNotNull(ConcreteTeam::query()->find($team->getKey()));
    }

    public function test_restore_brings_the_team_back_inside_the_window(): void
    {
        $owner = $this->makeUser();
        $member = $this->makeUser('Member');
        $team = $this->makeTeam($owner);
        $this->addMember($team, $member);
        (new TrashTeam)->trash($team);

        Carbon::setTestNow(now()->addDays(29));
        $trashed = RestorableTeam::onlyTrashed()->findOrFail($team->getKey());
        (new TrashTeam)->restore($trashed);

        $restored = RestorableTeam::query()->findOrFail($team->getKey());
        $this->assertFalse($restored->trashed());
        $this->assertNull($restored->restore_until);
        $this->assertSame(1, $owner->ownedTeams()->count());
        $this->assertSame(1, $member->teams()->count());
    }

    public function test_restore_is_refused_after_the_window_and_leaves_the_team_trashed(): void
    {
        $team = $this->makeTeam($this->makeUser());
        (new TrashTeam)->trash($team);

        Carbon::setTestNow(now()->addDays(30)->addSecond());
        $trashed = RestorableTeam::onlyTrashed()->findOrFail($team->getKey());

        try {
            (new TrashTeam)->restore($trashed);
            $this->fail('A restore past the window must be refused.');
        } catch (ValidationException $exception) {
            $this->assertSame('restoreTeam', $exception->errorBag);
            $this->assertSame(
                __('magic-starter::teams.restore_window_closed'),
                $exception->errors()['team'][0],
            );
        }

        $this->assertTrue(RestorableTeam::onlyTrashed()->findOrFail($team->getKey())->trashed());
    }

    public function test_restore_is_refused_at_the_exact_deadline(): void
    {
        $team = $this->makeTeam($this->makeUser());
        (new TrashTeam)->trash($team, 1);

        Carbon::setTestNow(now()->addDay());

        $this->expectException(ValidationException::class);

        (new TrashTeam)->restore(RestorableTeam::onlyTrashed()->findOrFail($team->getKey()));
    }

    public function test_restoring_a_live_team_changes_nothing(): void
    {
        $team = $this->makeTeam($this->makeUser());

        (new TrashTeam)->restore($team);

        $this->assertNull(RestorableTeam::query()->findOrFail($team->getKey())->restore_until);
    }

    public function test_a_team_trashed_without_a_window_can_still_be_restored(): void
    {
        $team = $this->makeTeam($this->makeUser());
        $team->delete();

        (new TrashTeam)->restore(RestorableTeam::onlyTrashed()->findOrFail($team->getKey()));

        $this->assertNotNull(RestorableTeam::query()->find($team->getKey()));
    }

    public function test_the_guarded_action_refuses_to_trash_a_team_a_store_is_billing(): void
    {
        config(['magic-starter.billing.billable' => 'team']);
        $team = $this->makeTeam($this->makeUser(), [
            'plan' => 'pro',
            'plan_status' => 'active',
            'plan_provider' => 'app_store',
        ]);

        try {
            $this->app->make(TrashesTeams::class)->trash($team);
            $this->fail('A team a store is billing must not be trashed.');
        } catch (ValidationException $exception) {
            $this->assertSame('deleteTeam', $exception->errorBag);
            $this->assertSame(
                __('magic-starter::billing.refusals.store_subscription_active'),
                $exception->errors()['team'][0],
            );
        }

        $this->assertNotNull(RestorableTeam::query()->find($team->getKey()), 'A refused trash leaves the team live.');
    }

    public function test_the_guarded_action_trashes_a_team_nobody_is_billing(): void
    {
        config(['magic-starter.billing.billable' => 'team']);
        $team = $this->makeTeam($this->makeUser());

        $this->app->make(TrashesTeams::class)->trash($team);

        $this->assertNull(RestorableTeam::query()->find($team->getKey()));
        $this->assertNotNull(RestorableTeam::onlyTrashed()->find($team->getKey()));
    }
}
