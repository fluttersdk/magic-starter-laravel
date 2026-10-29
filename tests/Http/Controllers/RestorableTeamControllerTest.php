<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Actions\TrashTeam;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Http\Controllers\TeamController;
use FlutterSdk\MagicStarter\Http\Controllers\TeamInvitationController;
use FlutterSdk\MagicStarter\Policies\TeamPolicy;
use FlutterSdk\MagicStarter\Tests\Fixtures\BuildsRestorableTeamSchema;
use FlutterSdk\MagicStarter\Tests\Fixtures\RestorableTeam;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * The team endpoints with and without the restorable delete: off, DELETE
 * purges as it always did; on, it trashes, and the owner restores.
 */
final class RestorableTeamControllerTest extends TestCase
{
    use BuildsRestorableTeamSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildRestorableTeamSchema();
        Carbon::setTestNow('2026-10-29 12:00:00');

        Gate::policy(RestorableTeam::class, TeamPolicy::class);

        $this->app['router']->get('/teams', [TeamController::class, 'index']);
        $this->app['router']->get('/teams/{team}', [TeamController::class, 'show']);
        $this->app['router']->delete('/teams/{team}', [TeamController::class, 'destroy']);
        $this->app['router']->post('/teams/{team}/restore', [TeamController::class, 'restore']);
        $this->app['router']->post('/invitations/{token}/accept', [TeamInvitationController::class, 'accept']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_with_the_flag_off_delete_purges_the_team_for_good(): void
    {
        $owner = $this->makeUser();
        $member = $this->makeUser('Member');
        $team = $this->makeTeam($owner);
        $this->addMember($team, $member);

        $this->actingAs($owner)
            ->deleteJson('/teams/' . $team->getKey())
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('message', __('magic-starter::teams.deleted'));

        $this->assertNull(RestorableTeam::withTrashed()->find($team->getKey()));
        $this->assertSame(0, DB::table('team_user')->where('team_id', $team->getKey())->count());
    }

    public function test_with_the_flag_on_delete_trashes_the_team_instead(): void
    {
        $this->enableRestorableTeams();
        $owner = $this->makeUser();
        $member = $this->makeUser('Member');
        $team = $this->makeTeam($owner);
        $this->addMember($team, $member);
        $this->pointAt($member, $team);

        $this->actingAs($owner)
            ->deleteJson('/teams/' . $team->getKey())
            ->assertOk()
            ->assertJsonPath('data.restore_until', now()->addDays(30)->toJSON())
            ->assertJsonPath('message', __('magic-starter::teams.trashed'));

        $trashed = RestorableTeam::onlyTrashed()->findOrFail($team->getKey());
        $this->assertTrue($trashed->restore_until->equalTo(now()->addDays(30)));
        $this->assertSame(2, DB::table('team_user')->where('team_id', $team->getKey())->count());
        $this->assertNull($this->pointerOf($member));
    }

    public function test_a_trashed_team_is_gone_from_the_lists_and_from_show(): void
    {
        $this->enableRestorableTeams();
        $owner = $this->makeUser();
        $member = $this->makeUser('Member');
        $team = $this->makeTeam($owner);
        $this->addMember($team, $member);
        (new TrashTeam)->trash($team);

        $this->actingAs($member)->getJson('/teams')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($owner)->getJson('/teams')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($member)->getJson('/teams/' . $team->getKey())->assertNotFound();
    }

    public function test_an_invitation_to_a_trashed_team_can_no_longer_be_accepted(): void
    {
        $this->enableRestorableTeams();
        $owner = $this->makeUser();
        $invitee = $this->makeUser('Invitee');
        $team = $this->makeTeam($owner);
        $team->invitations()->create([
            'email' => $invitee->email,
            'role' => 'member',
            'token' => 'token-1',
        ]);
        (new TrashTeam)->trash($team);

        $this->actingAs($invitee)->postJson('/invitations/token-1/accept')->assertNotFound();

        $this->assertSame(0, DB::table('team_user')->where('user_id', $invitee->getKey())->count());
    }

    public function test_the_owner_lands_on_another_team_when_they_trash_their_active_one(): void
    {
        $this->enableRestorableTeams();
        $owner = $this->makeUser();
        $home = $this->makeTeam($owner, ['name' => 'A home']);
        $trashed = $this->makeTeam($owner, ['name' => 'B shared']);
        $this->pointAt($owner, $trashed);

        // A request loads its user fresh, pointer included.
        $this->actingAs($owner->fresh())->deleteJson('/teams/' . $trashed->getKey())->assertOk();

        $this->assertSame((string) $home->getKey(), $this->pointerOf($owner));
    }

    public function test_a_personal_team_is_never_trashed(): void
    {
        $this->enableRestorableTeams();
        $owner = $this->makeUser();
        $personal = $this->makeTeam($owner, ['personal_team' => true]);

        $this->actingAs($owner)
            ->deleteJson('/teams/' . $personal->getKey())
            ->assertStatus(422)
            ->assertJsonPath('errors.team.0', __('magic-starter::teams.personal_team_undeletable'));

        $this->assertNotNull(RestorableTeam::query()->find($personal->getKey()));
    }

    public function test_a_member_cannot_trash_the_team(): void
    {
        $this->enableRestorableTeams();
        $member = $this->makeUser('Member');
        $team = $this->makeTeam($this->makeUser());
        $this->addMember($team, $member);

        $this->actingAs($member)->deleteJson('/teams/' . $team->getKey())->assertForbidden();

        $this->assertNotNull(RestorableTeam::query()->find($team->getKey()));
    }

    public function test_a_team_a_store_is_billing_is_refused_a_trash(): void
    {
        $this->enableRestorableTeams();
        config(['magic-starter.billing.billable' => 'team']);
        $owner = $this->makeUser();
        $team = $this->makeTeam($owner, [
            'plan' => 'pro',
            'plan_status' => 'active',
            'plan_provider' => 'play_store',
        ]);

        $this->actingAs($owner)
            ->deleteJson('/teams/' . $team->getKey())
            ->assertStatus(422)
            ->assertJsonPath('errors.team.0', __('magic-starter::billing.refusals.store_subscription_active'));

        $this->assertNotNull(RestorableTeam::query()->find($team->getKey()));
    }

    public function test_the_owner_restores_a_trashed_team_inside_the_window(): void
    {
        $this->enableRestorableTeams();
        $owner = $this->makeUser();
        $member = $this->makeUser('Member');
        $team = $this->makeTeam($owner);
        $this->addMember($team, $member);
        (new TrashTeam)->trash($team);
        Carbon::setTestNow(now()->addDays(29));

        $this->actingAs($owner)
            ->postJson('/teams/' . $team->getKey() . '/restore')
            ->assertOk()
            ->assertJsonPath('data.id', $team->getKey())
            ->assertJsonPath('data.user_role', 'owner')
            ->assertJsonPath('message', __('magic-starter::teams.restored'));

        $this->assertFalse(RestorableTeam::query()->findOrFail($team->getKey())->trashed());
        $this->actingAs($member)->getJson('/teams')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_restoring_after_the_window_is_refused(): void
    {
        $this->enableRestorableTeams();
        $owner = $this->makeUser();
        $team = $this->makeTeam($owner);
        (new TrashTeam)->trash($team);
        Carbon::setTestNow(now()->addDays(31));

        $this->actingAs($owner)
            ->postJson('/teams/' . $team->getKey() . '/restore')
            ->assertStatus(422)
            ->assertJsonPath('errors.team.0', __('magic-starter::teams.restore_window_closed'));

        $this->assertNotNull(RestorableTeam::onlyTrashed()->find($team->getKey()));
    }

    public function test_only_the_owner_restores(): void
    {
        $this->enableRestorableTeams();
        $member = $this->makeUser('Member');
        $stranger = $this->makeUser('Stranger');
        $team = $this->makeTeam($this->makeUser());
        $this->addMember($team, $member);
        (new TrashTeam)->trash($team);

        $this->actingAs($member)->postJson('/teams/' . $team->getKey() . '/restore')->assertForbidden();
        $this->actingAs($stranger)->postJson('/teams/' . $team->getKey() . '/restore')->assertForbidden();

        $this->assertNotNull(RestorableTeam::onlyTrashed()->find($team->getKey()));
    }

    public function test_restoring_an_unknown_team_answers_404(): void
    {
        $this->enableRestorableTeams();
        $owner = $this->makeUser();

        $this->actingAs($owner)->postJson('/teams/' . Str::uuid() . '/restore')->assertNotFound();
    }

    public function test_restoring_a_live_team_is_a_harmless_repeat(): void
    {
        $this->enableRestorableTeams();
        $owner = $this->makeUser();
        $team = $this->makeTeam($owner);
        (new TrashTeam)->trash($team);
        $this->actingAs($owner)->postJson('/teams/' . $team->getKey() . '/restore')->assertOk();

        $this->actingAs($owner)->postJson('/teams/' . $team->getKey() . '/restore')->assertOk();

        $this->assertNotNull(RestorableTeam::query()->find($team->getKey()));
    }

    private function enableRestorableTeams(): void
    {
        config([
            'magic-starter.features' => [
                Features::teams(),
                Features::restorableTeams(),
            ],
        ]);
    }
}
