<?php

namespace FlutterSdk\MagicStarter\Tests\Actions;

use FlutterSdk\MagicStarter\Actions\AddTeamMember;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Tests for the default AddTeamMember action.
 *
 * The action is driven directly rather than through an endpoint because no
 * controller in this package resolves AddsTeamMembers: the contract is bound in
 * the service provider for a consumer to call, and TeamMemberController has no
 * store method. A test that went through HTTP would be testing a route that
 * does not exist, and TeamMemberControllerTest binds a stub over the contract
 * anyway, so nothing there reaches this class.
 */
final class AddTeamMemberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function ($table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('email')->unique()->nullable();
            $table->string('password')->nullable();
            $table->timestamps();
        });

        Schema::create('teams', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('name');
            $table->boolean('personal_team')->default(false);
            $table->string('profile_photo_path', 2048)->nullable();
            $table->timestamps();
        });

        Schema::create('team_user', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->uuid('user_id');
            $table->string('role')->nullable();
            $table->timestamps();
        });
    }

    /**
     * The happy path, which is what makes the three refusals below mean
     * something: an action that threw unconditionally would satisfy them all.
     */
    public function test_add_attaches_the_user_with_the_given_role(): void
    {
        $owner = ConcreteUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $invitee = ConcreteUser::query()->create(['name' => 'Invitee', 'email' => 'invitee@test.dev']);
        $team = ConcreteTeam::query()->create([
            'user_id' => $owner->getKey(),
            'name' => 'A Team',
            'personal_team' => false,
        ]);

        (new AddTeamMember)->add($owner, $team, 'invitee@test.dev', 'editor');

        $attached = $team->fresh()->users()->find($invitee->getKey());

        $this->assertNotNull($attached, 'The invitee must be attached to the team.');
        $this->assertSame('editor', $attached->pivot?->role);
    }

    /**
     * An email that belongs to nobody is refused on the `email` field, so the
     * caller can render it against the input the person typed.
     */
    public function test_add_refuses_an_email_that_belongs_to_no_user(): void
    {
        $owner = ConcreteUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $team = ConcreteTeam::query()->create([
            'user_id' => $owner->getKey(),
            'name' => 'A Team',
            'personal_team' => false,
        ]);

        try {
            (new AddTeamMember)->add($owner, $team, 'nobody@test.dev', 'editor');

            $this->fail('An unknown email must be refused.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['The selected user could not be found.'],
                $exception->errors()['email'],
            );
        }

        $this->assertSame(0, $team->fresh()->users()->count());
    }

    /**
     * The owner is already on the team without a pivot row, which is the first
     * half of the duplicate check and the half a membership query alone would
     * miss: ownership lives on `teams.user_id`, not in `team_user`.
     */
    public function test_add_refuses_the_team_owner(): void
    {
        $owner = ConcreteUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $team = ConcreteTeam::query()->create([
            'user_id' => $owner->getKey(),
            'name' => 'A Team',
            'personal_team' => false,
        ]);

        try {
            (new AddTeamMember)->add($owner, $team, 'owner@test.dev', 'editor');

            $this->fail('The owner must not be addable as a member.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['This user is already a member of the team.'],
                $exception->errors()['email'],
            );
        }

        $this->assertSame(0, $team->fresh()->users()->count());
    }

    /**
     * The second half of the same check: somebody who already holds a pivot row
     * is refused too, and a duplicate row is not written.
     */
    public function test_add_refuses_an_existing_member(): void
    {
        $owner = ConcreteUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $member = ConcreteUser::query()->create(['name' => 'Member', 'email' => 'member@test.dev']);
        $team = ConcreteTeam::query()->create([
            'user_id' => $owner->getKey(),
            'name' => 'A Team',
            'personal_team' => false,
        ]);

        $team->users()->attach($member->getKey(), ['role' => 'member']);

        try {
            (new AddTeamMember)->add($owner, $team, 'member@test.dev', 'editor');

            $this->fail('An existing member must not be added twice.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['This user is already a member of the team.'],
                $exception->errors()['email'],
            );
        }

        $this->assertSame(1, $team->fresh()->users()->count());
        $this->assertSame('member', $team->fresh()->users()->find($member->getKey())?->pivot?->role);
    }

    /**
     * The two refusals reach the caller in the caller's language.
     *
     * Every other assertion here runs under `en`, where a key that resolved to
     * nothing is indistinguishable from one that resolved correctly: `__()`
     * returns its argument on a miss and the English line happens to be the
     * sentence those tests expect. Asking for `tr` is what proves the package's
     * translation namespace is actually registered and the keys exist.
     */
    public function test_add_answers_its_refusals_in_the_callers_locale(): void
    {
        $this->app->setLocale('tr');

        $owner = ConcreteUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $team = ConcreteTeam::query()->create([
            'user_id' => $owner->getKey(),
            'name' => 'A Team',
            'personal_team' => false,
        ]);

        try {
            (new AddTeamMember)->add($owner, $team, 'nobody@test.dev', 'editor');

            $this->fail('An unknown email must be refused.');
        } catch (ValidationException $exception) {
            $this->assertSame(['Seçilen kullanıcı bulunamadı.'], $exception->errors()['email']);
        }

        try {
            (new AddTeamMember)->add($owner, $team, 'owner@test.dev', 'editor');

            $this->fail('The owner must not be addable as a member.');
        } catch (ValidationException $exception) {
            $this->assertSame(['Bu kullanıcı zaten takımın üyesi.'], $exception->errors()['email']);
        }
    }
}
