<?php

namespace FlutterSdk\MagicStarter\Tests\Fixtures;

use FlutterSdk\MagicStarter\MagicStarter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tables and models a test of the restorable team delete needs: a teams
 * table carrying the `deleted_at` and `restore_until` columns, owners, members
 * and invitations.
 *
 * The invitations table keys on `restorable_team_id` because
 * `Team::invitations()` infers its foreign key from the runtime class name.
 */
trait BuildsRestorableTeamSchema
{
    protected function buildRestorableTeamSchema(): void
    {
        MagicStarter::useUserModel(RestorableTeamUser::class);
        MagicStarter::useTeamModel(RestorableTeam::class);

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->nullable();
            $table->uuid('current_team_id')->nullable();
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('name');
            $table->boolean('personal_team')->default(false);
            $table->string('profile_photo_path')->nullable();
            $table->string('plan')->nullable();
            $table->string('plan_status')->nullable();
            $table->string('plan_provider')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->timestamp('restore_until')->nullable()->index();
        });

        Schema::create('team_user', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->uuid('user_id');
            $table->string('role')->nullable();
            $table->timestamps();
        });

        Schema::create('team_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('restorable_team_id')->nullable();
            $table->string('email');
            $table->string('role')->nullable();
            $table->string('token');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuidMorphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });
    }

    protected function makeUser(string $name = 'Owner'): RestorableTeamUser
    {
        return RestorableTeamUser::query()->create([
            'name' => $name,
            'email' => strtolower($name) . '@example.test',
        ]);
    }

    /**
     * A shared team owned by the given user, who is also its first member.
     *
     * @param  array<string, mixed>  $attributes  Written with forceFill, so a column outside `$fillable` works.
     */
    protected function makeTeam(RestorableTeamUser $owner, array $attributes = []): RestorableTeam
    {
        $team = new RestorableTeam;
        $team->forceFill([
            'user_id' => $owner->getKey(),
            'name' => 'Home',
            'personal_team' => false,
            ...$attributes,
        ])->save();

        $team->users()->attach($owner->getKey(), ['role' => 'owner']);

        return $team;
    }

    protected function addMember(RestorableTeam $team, RestorableTeamUser $member): void
    {
        $team->users()->attach($member->getKey(), ['role' => 'member']);
    }

    /**
     * Point a user's active team at the given team, bypassing `$fillable` like the application does.
     */
    protected function pointAt(RestorableTeamUser $user, RestorableTeam $team): void
    {
        RestorableTeamUser::query()
            ->whereKey($user->getKey())
            ->update(['current_team_id' => $team->getKey()]);
    }

    protected function pointerOf(RestorableTeamUser $user): ?string
    {
        $pointer = RestorableTeamUser::query()->whereKey($user->getKey())->value('current_team_id');

        return $pointer === null ? null : (string) $pointer;
    }
}
