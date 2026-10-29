<?php

namespace FlutterSdk\MagicStarter\Tests\Actions;

use FlutterSdk\MagicStarter\Actions\TrashTeam;
use FlutterSdk\MagicStarter\Contracts\DeletesUsers;
use FlutterSdk\MagicStarter\Support\OwnedTeams;
use FlutterSdk\MagicStarter\Tests\Fixtures\BuildsRestorableTeamSchema;
use FlutterSdk\MagicStarter\Tests\Fixtures\RestorableTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\RestorableTeamUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * The account purge against a team model that can be trashed: the teams a user
 * trashed are still theirs to purge, and they neither block the deletion nor
 * outlive it. The same purge against a model that cannot be trashed is covered
 * by {@see DeleteUserTest}, which uses a team model without SoftDeletes.
 */
final class DeleteUserRestorableTeamsTest extends TestCase
{
    use BuildsRestorableTeamSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildRestorableTeamSchema();
        Storage::fake('public');
    }

    public function test_the_account_purge_deletes_a_trashed_team_with_its_photo(): void
    {
        $user = $this->makeUser();
        $personal = $this->makeTeam($user, ['personal_team' => true]);
        $trashed = $this->makeTeam($user, ['profile_photo_path' => 'team-photos/home.png']);
        $this->addMember($trashed, $this->makeUser('Member'));
        Storage::disk('public')->put('team-photos/home.png', 'png');
        (new TrashTeam)->trash($trashed);

        $this->app->make(DeletesUsers::class)->delete($user);

        $this->assertNull(RestorableTeam::withTrashed()->find($personal->getKey()));
        $this->assertNull(RestorableTeam::withTrashed()->find($trashed->getKey()));
        $this->assertSame(0, DB::table('team_user')->where('team_id', $trashed->getKey())->count());
        Storage::disk('public')->assertMissing('team-photos/home.png');
        $this->assertNull(RestorableTeamUser::query()->find($user->getKey()));
    }

    public function test_a_trashed_shared_team_does_not_block_the_account_deletion(): void
    {
        $user = $this->makeUser();
        $trashed = $this->makeTeam($user);
        $this->addMember($trashed, $this->makeUser('Member'));
        (new TrashTeam)->trash($trashed);

        $this->assertTrue(OwnedTeams::shared($user)->isEmpty());

        $this->app->make(DeletesUsers::class)->delete($user);

        $this->assertNull(RestorableTeamUser::query()->find($user->getKey()));
    }

    public function test_a_live_shared_team_still_blocks_the_account_deletion_beside_a_trashed_one(): void
    {
        $user = $this->makeUser();
        $trashed = $this->makeTeam($user, ['name' => 'Trashed']);
        $live = $this->makeTeam($user, ['name' => 'Live']);
        $this->addMember($live, $this->makeUser('Member'));
        (new TrashTeam)->trash($trashed);

        try {
            $this->app->make(DeletesUsers::class)->delete($user);
            $this->fail('A live shared team must still refuse the deletion.');
        } catch (ValidationException $exception) {
            $this->assertSame(__('magic-starter::social.owns_shared_teams'), $exception->errors()['user'][0]);
        }

        $this->assertNotNull(RestorableTeamUser::query()->find($user->getKey()));
        $this->assertNotNull(RestorableTeam::onlyTrashed()->find($trashed->getKey()));
    }

    public function test_a_trashed_team_a_store_is_billing_still_holds_the_account(): void
    {
        config(['magic-starter.billing.billable' => 'team']);
        $user = $this->makeUser();
        $trashed = $this->makeTeam($user);
        (new TrashTeam)->trash($trashed);
        DB::table('teams')->where('id', $trashed->getKey())->update([
            'plan' => 'pro',
            'plan_status' => 'active',
            'plan_provider' => 'app_store',
        ]);

        $this->assertSame([$trashed->getKey()], OwnedTeams::billing($user)->map->getKey()->all());

        $this->expectException(ValidationException::class);

        $this->app->make(DeletesUsers::class)->delete($user);
    }
}
