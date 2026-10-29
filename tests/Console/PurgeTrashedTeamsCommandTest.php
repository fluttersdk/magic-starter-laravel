<?php

namespace FlutterSdk\MagicStarter\Tests\Console;

use FlutterSdk\MagicStarter\Actions\TrashTeam;
use FlutterSdk\MagicStarter\Console\PurgeTrashedTeamsCommand;
use FlutterSdk\MagicStarter\Contracts\DeletesTeams;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\Fixtures\BuildsRestorableTeamSchema;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\RestorableTeam;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The purge is the only place a trashed team is deleted for good, and it acts
 * on the teams whose restore window has ended and on nothing else.
 */
final class PurgeTrashedTeamsCommandTest extends TestCase
{
    use BuildsRestorableTeamSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildRestorableTeamSchema();
        Storage::fake('public');
        Carbon::setTestNow('2026-10-29 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_removes_only_the_trashed_teams_whose_window_has_ended(): void
    {
        $owner = $this->makeUser();
        $live = $this->makeTeam($owner, ['name' => 'Live']);
        $expired = $this->makeTeam($owner, ['name' => 'Expired']);
        $inWindow = $this->makeTeam($owner, ['name' => 'In window']);
        (new TrashTeam)->trash($expired, 10);

        Carbon::setTestNow(now()->addDays(5));
        (new TrashTeam)->trash($inWindow, 10);

        Carbon::setTestNow(now()->addDays(6));
        $this->artisan(PurgeTrashedTeamsCommand::NAME)
            ->expectsOutputToContain('Purge finished: 1 deleted, 0 held, 0 restored, 0 failed.')
            ->assertExitCode(0);

        $this->assertNotNull(RestorableTeam::query()->find($live->getKey()));
        $this->assertNotNull(RestorableTeam::onlyTrashed()->find($inWindow->getKey()));
        $this->assertNull(RestorableTeam::withTrashed()->find($expired->getKey()));
    }

    public function test_a_window_that_has_not_ended_is_left_trashed_and_restorable(): void
    {
        $team = $this->makeTeam($this->makeUser());
        (new TrashTeam)->trash($team, 10);

        Carbon::setTestNow(now()->addDays(9));
        $this->artisan(PurgeTrashedTeamsCommand::NAME)->assertExitCode(0);

        $this->assertNotNull(RestorableTeam::onlyTrashed()->find($team->getKey()));
    }

    public function test_the_deadline_itself_is_due(): void
    {
        $team = $this->makeTeam($this->makeUser());
        (new TrashTeam)->trash($team, 10);

        Carbon::setTestNow(now()->addDays(10));
        $this->artisan(PurgeTrashedTeamsCommand::NAME)->assertExitCode(0);

        $this->assertNull(RestorableTeam::withTrashed()->find($team->getKey()));
    }

    public function test_it_purges_through_the_contract_with_the_photo_members_and_invitations(): void
    {
        $team = $this->makeTeam($this->makeUser(), ['profile_photo_path' => 'team-photos/home.png']);
        $this->addMember($team, $this->makeUser('Member'));
        $team->invitations()->create([
            'email' => 'guest@example.test',
            'role' => 'member',
            'token' => 'token-1',
        ]);
        Storage::disk('public')->put('team-photos/home.png', 'png');
        (new TrashTeam)->trash($team, 1);

        Carbon::setTestNow(now()->addDay());
        $this->artisan(PurgeTrashedTeamsCommand::NAME)->assertExitCode(0);

        Storage::disk('public')->assertMissing('team-photos/home.png');
        $this->assertSame(0, DB::table('team_user')->count());
        $this->assertSame(0, DB::table('team_invitations')->count());
    }

    public function test_a_trashed_team_with_no_window_is_left_alone(): void
    {
        $team = $this->makeTeam($this->makeUser());
        $team->delete();

        Carbon::setTestNow(now()->addYear());
        $this->artisan(PurgeTrashedTeamsCommand::NAME)->assertExitCode(0);

        $this->assertNotNull(RestorableTeam::onlyTrashed()->find($team->getKey()));
    }

    public function test_a_team_restored_after_the_walk_loaded_it_is_not_purged(): void
    {
        $team = $this->makeTeam($this->makeUser());
        (new TrashTeam)->trash($team, 1);
        Carbon::setTestNow(now()->addDay());

        $this->app->instance(DeletesTeams::class, new class implements DeletesTeams
        {
            public function delete(Model $team): void
            {
                throw new RuntimeException('The purge must not reach a team that is no longer due.');
            }
        });

        // The owner restores the team after the walk has read it and before the purge acts on it.
        $restored = false;
        RestorableTeam::retrieved(function (RestorableTeam $retrieved) use (&$restored): void {
            if ($restored) {
                return;
            }

            $restored = true;
            DB::table('teams')->where('id', $retrieved->getKey())->update([
                'deleted_at' => null,
                'restore_until' => null,
            ]);
        });

        $this->artisan(PurgeTrashedTeamsCommand::NAME)
            ->expectsOutputToContain('Purge finished: 0 deleted, 0 held, 1 restored, 0 failed.')
            ->assertExitCode(0);

        $this->assertNotNull(RestorableTeam::query()->find($team->getKey()));
    }

    public function test_a_team_the_billing_guard_refuses_is_held_and_logged_not_failed(): void
    {
        config(['magic-starter.billing.billable' => 'team']);
        $held = $this->makeTeam($this->makeUser('Billed'), ['name' => 'Held']);
        $due = $this->makeTeam($this->makeUser(), ['name' => 'Due']);
        (new TrashTeam)->trash($held, 1);
        (new TrashTeam)->trash($due, 1);
        // A store subscription that started after the trash: the trash guard would have refused it before.
        DB::table('teams')->where('id', $held->getKey())->update([
            'plan' => 'pro',
            'plan_status' => 'active',
            'plan_provider' => 'app_store',
        ]);
        Log::spy();

        Carbon::setTestNow(now()->addDay());
        $this->artisan(PurgeTrashedTeamsCommand::NAME)
            ->expectsOutputToContain('Purge finished: 1 deleted, 1 held, 0 restored, 0 failed.')
            ->assertExitCode(0);

        $this->assertNotNull(RestorableTeam::onlyTrashed()->find($held->getKey()));
        $this->assertNull(RestorableTeam::withTrashed()->find($due->getKey()));
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_one_team_failing_is_reported_and_the_run_moves_on(): void
    {
        Exceptions::fake();
        $broken = $this->makeTeam($this->makeUser('Broken'), ['name' => 'Broken']);
        $fine = $this->makeTeam($this->makeUser(), ['name' => 'Fine']);
        (new TrashTeam)->trash($broken, 1);
        (new TrashTeam)->trash($fine, 1);

        $this->app->bind(DeletesTeams::class, fn (): DeletesTeams => new class($broken) implements DeletesTeams
        {
            public function __construct(private readonly Model $broken) {}

            public function delete(Model $team): void
            {
                if ($team->getKey() === $this->broken->getKey()) {
                    throw new RuntimeException('Storage is down.');
                }

                $team->forceDelete();
            }
        });

        Carbon::setTestNow(now()->addDay());
        $this->artisan(PurgeTrashedTeamsCommand::NAME)
            ->expectsOutputToContain('Purge finished: 1 deleted, 0 held, 0 restored, 1 failed.')
            ->assertExitCode(1);

        Exceptions::assertReported(RuntimeException::class);
        $this->assertNotNull(RestorableTeam::onlyTrashed()->find($broken->getKey()));
        $this->assertNull(RestorableTeam::withTrashed()->find($fine->getKey()));
    }

    public function test_a_team_model_that_cannot_be_trashed_has_nothing_to_purge(): void
    {
        MagicStarter::useTeamModel(ConcreteTeam::class);
        $team = ConcreteTeam::query()->create([
            'user_id' => $this->makeUser()->getKey(),
            'name' => 'Home',
            'personal_team' => false,
        ]);

        $this->artisan(PurgeTrashedTeamsCommand::NAME)
            ->expectsOutputToContain('nothing to purge')
            ->assertExitCode(0);

        $this->assertNotNull(ConcreteTeam::query()->find($team->getKey()));
    }

    public function test_a_teams_table_without_the_window_column_has_nothing_to_purge(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->dropIndex(['restore_until']);
            $table->dropColumn('restore_until');
        });

        $this->artisan(PurgeTrashedTeamsCommand::NAME)
            ->expectsOutputToContain('nothing to purge')
            ->assertExitCode(0);
    }
}
