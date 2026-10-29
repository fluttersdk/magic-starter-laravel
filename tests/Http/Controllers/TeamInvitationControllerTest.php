<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Actions\AcceptTeamInvitation;
use FlutterSdk\MagicStarter\Contracts\AcceptsTeamInvitations;
use FlutterSdk\MagicStarter\Contracts\InvitesTeamMembers;
use FlutterSdk\MagicStarter\Events\TeamMemberJoined;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Http\Controllers\TeamInvitationController;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\SocialAccount;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

final class TeamInvitationControllerTest extends TestCase
{
    /**
     * An address Sign in with Apple hands out when the person hides their own.
     */
    private const RELAY_EMAIL = 'x7k2m9@privaterelay.appleid.com';

    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::reset();

        \call_user_func('config', [
            'database.default' => 'testing',
            'database.connections.testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'magic-starter.models.user' => TeamInvitationControllerTestUser::class,
            'magic-starter.models.team' => TeamInvitationControllerTestTeam::class,
            'magic-starter.models.team_invitation' => TeamInvitationControllerTestInvitation::class,
            'magic-starter.models.membership' => TeamInvitationControllerTestMembership::class,
        ]);

        \call_user_func([\call_user_func('app', 'db.schema'), 'create'], 'users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->string('password')->nullable();
            $table->string('current_team_id')->nullable();
            $table->timestamps();
        });

        \call_user_func([\call_user_func('app', 'db.schema'), 'create'], 'teams', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('name');
            $table->boolean('personal_team')->default(false);
            $table->string('profile_photo_path', 2048)->nullable();
            $table->timestamps();
        });

        \call_user_func([\call_user_func('app', 'db.schema'), 'create'], 'team_user', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->uuid('user_id');
            $table->string('role')->nullable();
            $table->timestamps();
        });

        \call_user_func([\call_user_func('app', 'db.schema'), 'create'], 'team_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->string('email');
            $table->string('role')->nullable()->default('member');
            $table->string('token')->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        \call_user_func('app')->singleton('gate', function ($app) {
            return new \Illuminate\Auth\Access\Gate($app, function (): mixed {
                return \call_user_func('auth')->user();
            });
        });

        \call_user_func('app')->alias('gate', \Illuminate\Contracts\Auth\Access\Gate::class);

        \call_user_func('app', 'gate')->policy(
            TeamInvitationControllerTestTeam::class,
            \FlutterSdk\MagicStarter\Policies\TeamPolicy::class,
        );

        $this->app->instance(InvitesTeamMembers::class, new class implements InvitesTeamMembers
        {
            public function invite(\Illuminate\Contracts\Auth\Authenticatable $user, \Illuminate\Database\Eloquent\Model $team, string $email, string $role): \Illuminate\Database\Eloquent\Model
            {
                return $team->invitations()->create([
                    'email' => $email,
                    'role' => $role,
                    'token' => \call_user_func('str')->random(32),
                ]);
            }
        });

        \call_user_func('app', 'router')->get('/teams/{team}/invitations', [TeamInvitationController::class, 'index']);
        \call_user_func('app', 'router')->post('/teams/{team}/invitations', [TeamInvitationController::class, 'store']);
        \call_user_func('app', 'router')->delete('/teams/{team}/invitations/{invitation}', [TeamInvitationController::class, 'destroy']);
        \call_user_func('app', 'router')->post('/invitations/{token}/accept', [TeamInvitationController::class, 'accept']);
    }

    public function test_index_lists_team_invitations(): void
    {
        $owner = TeamInvitationControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $team = TeamInvitationControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $invitation = $team->invitations()->create(['email' => 'invitee@test.dev', 'role' => 'member', 'token' => 'tok-1']);

        $this->actingAs($owner)
            ->getJson('/teams/' . $team->id . '/invitations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $invitation->id);
    }

    public function test_store_creates_invitation_via_contract_action(): void
    {
        $owner = TeamInvitationControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $team = TeamInvitationControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);

        $this->actingAs($owner)
            ->postJson('/teams/' . $team->id . '/invitations', [
                'email' => 'new@test.dev',
                'role' => 'editor',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.email', 'new@test.dev')
            ->assertJsonPath('data.role', 'editor');

        $this->assertTrue($team->fresh()->invitations()->where('email', 'new@test.dev')->exists());
    }

    public function test_destroy_cancels_invitation_for_same_team(): void
    {
        $owner = TeamInvitationControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $team = TeamInvitationControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $invitation = $team->invitations()->create(['email' => 'invitee@test.dev', 'role' => 'member', 'token' => 'tok-2']);

        $this->actingAs($owner)
            ->deleteJson('/teams/' . $team->id . '/invitations/' . $invitation->id)
            ->assertOk()
            ->assertJsonPath('message', 'Invitation canceled successfully.');

        $invitationModelClass = MagicStarter::teamInvitationModel();
        $this->assertNull($invitationModelClass::query()->find($invitation->id));
    }

    public function test_accept_adds_user_to_team_and_deletes_invitation(): void
    {
        $owner = TeamInvitationControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $invitee = TeamInvitationControllerTestUser::query()->create(['name' => 'Invitee', 'email' => 'invitee@test.dev']);
        $team = TeamInvitationControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $invitation = $team->invitations()->create([
            'email' => $invitee->email,
            'role' => 'admin',
            'token' => 'accept-token',
        ]);

        $this->actingAs($invitee)
            ->postJson('/invitations/accept-token/accept')
            ->assertOk()
            ->assertJsonPath('message', 'Invitation accepted. You have joined the team.');

        $this->assertTrue($team->fresh()->users()->where('user_id', $invitee->id)->exists());
        $this->assertSame('admin', $team->fresh()->users()->find($invitee->id)?->pivot?->role);
        $invitationModelClass = MagicStarter::teamInvitationModel();
        $this->assertNull($invitationModelClass::query()->find($invitation->id));
    }

    public function test_accept_dispatches_joined_once_even_when_the_link_is_followed_twice(): void
    {
        Event::fake([TeamMemberJoined::class]);
        $owner = TeamInvitationControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $invitee = TeamInvitationControllerTestUser::query()->create(['name' => 'Invitee', 'email' => 'invitee@test.dev']);
        $team = TeamInvitationControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $team->invitations()->create([
            'email' => $invitee->email,
            'role' => 'member',
            'token' => 'accept-token',
        ]);

        $this->actingAs($invitee)->postJson('/invitations/accept-token/accept')->assertOk();
        $this->actingAs($invitee)->postJson('/invitations/accept-token/accept')->assertNotFound();

        Event::assertDispatchedTimes(TeamMemberJoined::class, 1);
        Event::assertDispatched(
            TeamMemberJoined::class,
            fn (TeamMemberJoined $event): bool => $event->teamId === (string) $team->id
                && $event->userId === (string) $invitee->id
                && $event->actorId === (string) $invitee->id,
        );
    }

    public function test_accept_rejects_when_email_does_not_match(): void
    {
        $owner = TeamInvitationControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $team = TeamInvitationControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $team->invitations()->create([
            'email' => 'invitee@test.dev',
            'role' => 'admin',
            'token' => 'accept-token',
        ]);

        $otherUser = TeamInvitationControllerTestUser::query()->create(['name' => 'Other', 'email' => 'other@test.dev']);

        $this->actingAs($otherUser)
            ->postJson('/invitations/accept-token/accept')
            ->assertStatus(403)
            ->assertJsonPath('message', 'This invitation was sent to a different email address.');
    }

    public function test_accept_rejects_expired_invitation(): void
    {
        $owner = TeamInvitationControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $team = TeamInvitationControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $invitation = $team->invitations()->create([
            'email' => 'invitee@test.dev',
            'role' => 'admin',
            'token' => 'accept-token',
            'expires_at' => now()->subDay(),
        ]);

        $invitee = TeamInvitationControllerTestUser::query()->create(['name' => 'Invitee', 'email' => 'invitee@test.dev']);

        $this->actingAs($invitee)
            ->postJson('/invitations/accept-token/accept')
            ->assertStatus(410)
            ->assertJsonPath('message', 'This invitation has expired.');

        $invitationModelClass = MagicStarter::teamInvitationModel();
        $this->assertNull($invitationModelClass::query()->find($invitation->id));
    }

    /**
     * Accepting an invitation you no longer need is a 200, not an error, and it
     * clears the invitation.
     *
     * The row can outlive the reason for it: an admin adds the person directly
     * while the mail is in their inbox, or the same link is opened twice. The
     * person did nothing wrong and is already where the link was taking them,
     * so the endpoint tidies up rather than refusing. Without the delete the
     * invitation would sit in the team's pending list for good, since accepting
     * it again lands here again.
     */
    public function test_accept_clears_the_invitation_when_the_user_already_joined(): void
    {
        $owner = TeamInvitationControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $invitee = TeamInvitationControllerTestUser::query()->create(['name' => 'Invitee', 'email' => 'invitee@test.dev']);
        $team = TeamInvitationControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $invitation = $team->invitations()->create([
            'email' => $invitee->email,
            'role' => 'admin',
            'token' => 'already-joined-token',
        ]);

        // The admin added them directly while the invitation was in flight.
        $team->users()->attach($invitee->id, ['role' => 'member']);

        $this->actingAs($invitee)
            ->postJson('/invitations/already-joined-token/accept')
            ->assertOk()
            ->assertJsonPath('message', 'You are already a member of this team.');

        $invitationModelClass = MagicStarter::teamInvitationModel();
        $this->assertNull($invitationModelClass::query()->find($invitation->id));

        $this->assertSame(1, $team->fresh()->users()->count(), 'The membership must not be duplicated.');
        $this->assertSame('member', $team->fresh()->users()->find($invitee->id)?->pivot?->role, 'The existing role must survive.');
    }

    /**
     * The owner reaches the same answer, and by the other half of the same
     * check: ownership lives on `teams.user_id` and writes no pivot row, so a
     * membership query alone would let the owner attach themselves as a member
     * of their own team.
     */
    public function test_accept_clears_the_invitation_when_the_user_owns_the_team(): void
    {
        $owner = TeamInvitationControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $team = TeamInvitationControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $invitation = $team->invitations()->create([
            'email' => $owner->email,
            'role' => 'admin',
            'token' => 'owner-token',
        ]);

        $this->actingAs($owner)
            ->postJson('/invitations/owner-token/accept')
            ->assertOk()
            ->assertJsonPath('message', 'You are already a member of this team.');

        $invitationModelClass = MagicStarter::teamInvitationModel();
        $this->assertNull($invitationModelClass::query()->find($invitation->id));

        $this->assertSame(0, $team->fresh()->users()->count(), 'The owner must not gain a pivot row.');
    }

    /**
     * The same answer reaches the caller in the caller's language.
     *
     * Under `en` a key that resolved to nothing is indistinguishable from one
     * that resolved: `__()` returns its argument on a miss and the English line
     * happens to be the sentence the tests above expect.
     */
    public function test_accept_answers_the_already_joined_case_in_the_callers_locale(): void
    {
        \call_user_func('app')->setLocale('tr');

        $owner = TeamInvitationControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $team = TeamInvitationControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $team->invitations()->create([
            'email' => $owner->email,
            'role' => 'admin',
            'token' => 'owner-token-tr',
        ]);

        $this->actingAs($owner)
            ->postJson('/invitations/owner-token-tr/accept')
            ->assertOk()
            ->assertJsonPath('message', 'Bu takımın zaten üyesisiniz.');
    }

    public function test_index_returns_403_for_non_owner(): void
    {
        $owner = TeamInvitationControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $member = TeamInvitationControllerTestUser::query()->create(['name' => 'Member', 'email' => 'member@test.dev']);
        $team = TeamInvitationControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $team->users()->attach($member->id, ['role' => 'member']);
        $this->actingAs($member)
            ->getJson('/teams/' . $team->id . '/invitations')
            ->assertStatus(403);
    }

    public function test_store_returns_422_for_duplicate_invitation(): void
    {
        $owner = TeamInvitationControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $team = TeamInvitationControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $team->invitations()->create(['email' => 'dup@test.dev', 'role' => 'member', 'token' => 'tok-dup']);
        $this->actingAs($owner)
            ->postJson('/teams/' . $team->id . '/invitations', [
                'email' => 'dup@test.dev',
                'role' => 'editor',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors']);
    }

    public function test_store_returns_422_for_existing_member(): void
    {
        $owner = TeamInvitationControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $member = TeamInvitationControllerTestUser::query()->create(['name' => 'Member', 'email' => 'member@test.dev']);
        $team = TeamInvitationControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $team->users()->attach($member->id, ['role' => 'member']);
        $this->actingAs($owner)
            ->postJson('/teams/' . $team->id . '/invitations', [
                'email' => $member->email,
                'role' => 'editor',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors']);
    }

    public function test_accept_returns_404_for_invalid_token(): void
    {
        $user = TeamInvitationControllerTestUser::query()->create(['name' => 'User', 'email' => 'user@test.dev']);
        $this->actingAs($user)
            ->postJson('/invitations/nonexistent-token/accept')
            ->assertStatus(404);
    }

    /**
     * The three answers a client already parses, pinned to their exact bodies:
     * the accept moved behind a contract, and a body that grew a key or lost
     * `data` would pass every `assertJsonPath` above.
     */
    public function test_accept_keeps_the_wire_shape_of_each_answer(): void
    {
        $owner = TeamInvitationControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $invitee = TeamInvitationControllerTestUser::query()->create(['name' => 'Invitee', 'email' => 'invitee@test.dev']);
        $team = TeamInvitationControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $team->invitations()->create(['email' => $invitee->email, 'role' => 'admin', 'token' => 'shape-accepted']);
        $team->invitations()->create(['email' => $owner->email, 'role' => 'admin', 'token' => 'shape-joined']);
        $team->invitations()->create([
            'email' => $invitee->email,
            'role' => 'admin',
            'token' => 'shape-expired',
            'expires_at' => now()->subDay(),
        ]);

        $this->actingAs($invitee)
            ->postJson('/invitations/shape-expired/accept')
            ->assertStatus(410)
            ->assertExactJson(['message' => 'This invitation has expired.']);

        $this->actingAs($owner)
            ->postJson('/invitations/shape-joined/accept')
            ->assertOk()
            ->assertExactJson(['data' => null, 'message' => 'You are already a member of this team.']);

        $this->actingAs($invitee)
            ->postJson('/invitations/shape-accepted/accept')
            ->assertOk()
            ->assertExactJson(['data' => null, 'message' => 'Invitation accepted. You have joined the team.']);
    }

    /**
     * A link opened twice joins once: the first accept consumes the token, so
     * the second finds no invitation at all.
     */
    public function test_accept_twice_with_the_same_token_attaches_once(): void
    {
        $owner = TeamInvitationControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $invitee = TeamInvitationControllerTestUser::query()->create(['name' => 'Invitee', 'email' => 'invitee@test.dev']);
        $team = TeamInvitationControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $team->invitations()->create(['email' => $invitee->email, 'role' => 'admin', 'token' => 'twice-token']);

        $this->actingAs($invitee)->postJson('/invitations/twice-token/accept')->assertOk();
        $this->actingAs($invitee)->postJson('/invitations/twice-token/accept')->assertStatus(404);

        $this->assertSame(1, $team->fresh()->users()->count());
    }

    /**
     * The endpoint resolves whatever is bound over the contract, so an app can
     * refuse a join (a plan's member cap) and the endpoint answers the refusal
     * as the contract's validation error, with nothing attached and the
     * invitation left for a retry after the cap is lifted.
     */
    public function test_accept_answers_a_refusal_from_the_bound_contract(): void
    {
        $this->app->instance(AcceptsTeamInvitations::class, new class extends AcceptTeamInvitation
        {
            protected function ensureCanJoin(Model $team, AuthenticatableContract $user): void
            {
                $message = 'This household is full.';
                $exception = ValidationException::withMessages(['invitation' => $message]);
                $exception->response = new JsonResponse([
                    'message' => $message,
                    'code' => 'member_limit_reached',
                    'errors' => $exception->errors(),
                ], 422);

                throw $exception;
            }
        });

        $owner = TeamInvitationControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $invitee = TeamInvitationControllerTestUser::query()->create(['name' => 'Invitee', 'email' => 'invitee@test.dev']);
        $team = TeamInvitationControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $invitation = $team->invitations()->create(['email' => $invitee->email, 'role' => 'admin', 'token' => 'full-token']);

        $this->actingAs($invitee)
            ->postJson('/invitations/full-token/accept')
            ->assertStatus(422)
            ->assertJsonPath('code', 'member_limit_reached')
            ->assertJsonPath('message', 'This household is full.');

        $this->assertSame(0, $team->fresh()->users()->count());
        $this->assertNotNull(TeamInvitationControllerTestInvitation::query()->find($invitation->id));
    }

    /**
     * The email check stays with the endpoint, ahead of the contract: a
     * binding never sees an invitation addressed to somebody else.
     */
    public function test_accept_does_not_call_the_contract_for_a_different_email(): void
    {
        $this->app->instance(AcceptsTeamInvitations::class, new class implements AcceptsTeamInvitations
        {
            public function accept(AuthenticatableContract $user, Model $invitation): void
            {
                throw new LogicException('The contract must not run for a mismatched email.');
            }
        });

        $owner = TeamInvitationControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $other = TeamInvitationControllerTestUser::query()->create(['name' => 'Other', 'email' => 'other@test.dev']);
        $team = TeamInvitationControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $team->invitations()->create(['email' => 'invitee@test.dev', 'role' => 'admin', 'token' => 'mismatch-token']);

        $this->actingAs($other)
            ->postJson('/invitations/mismatch-token/accept')
            ->assertStatus(403);
    }

    public function test_the_email_mismatch_confirmation_is_off_by_default(): void
    {
        $this->assertFalse(config('magic-starter.invitation_confirm_email_mismatch'));
    }

    /**
     * With the flag off a relay address is just another different email: the
     * 403 body is the one clients already parse, and a confirmation flag in the
     * request changes nothing.
     */
    public function test_accept_keeps_the_403_for_a_relay_user_while_confirmation_is_off(): void
    {
        [$team, $invitation] = $this->teamWithInvitation('ayse@example.com', 'relay-off');
        $relay = $this->makeUser(self::RELAY_EMAIL);

        $this->actingAs($relay)
            ->postJson('/invitations/relay-off/accept', ['confirm_email_mismatch' => true])
            ->assertStatus(403)
            ->assertExactJson(['message' => 'This invitation was sent to a different email address.']);

        $this->assertSame(0, $team->fresh()->users()->count());
        $this->assertNotNull(TeamInvitationControllerTestInvitation::query()->find($invitation->id));
    }

    /**
     * The first call only asks: nothing is attached and the invitation is not
     * consumed. The repeat call carrying the confirmation joins through the
     * same contract as any other accept, with the invitation's role.
     */
    public function test_accept_asks_a_relay_user_to_confirm_and_then_joins(): void
    {
        $this->enableEmailMismatchConfirmation();
        [$team, $invitation] = $this->teamWithInvitation('ayse@example.com', 'relay-on');
        $relay = $this->makeUser(self::RELAY_EMAIL);

        $this->actingAs($relay)
            ->postJson('/invitations/relay-on/accept')
            ->assertStatus(409)
            ->assertExactJson([
                'message' => 'This invitation was sent to a different email address. Confirm to join with this account.',
                'code' => 'email_mismatch',
                'requires_confirmation' => true,
                'masked_email' => 'a***@example.com',
            ]);

        $this->assertSame(0, $team->fresh()->users()->count(), 'The question must not attach anybody.');
        $this->assertNotNull(
            TeamInvitationControllerTestInvitation::query()->find($invitation->id),
            'The question must not consume the invitation.',
        );

        $this->actingAs($relay)
            ->postJson('/invitations/relay-on/accept', ['confirm_email_mismatch' => true])
            ->assertOk()
            ->assertExactJson(['data' => null, 'message' => 'Invitation accepted. You have joined the team.']);

        $this->assertSame('admin', $team->fresh()->users()->find($relay->id)?->pivot?->role);
        $this->assertNull(TeamInvitationControllerTestInvitation::query()->find($invitation->id));
    }

    /**
     * An Apple link is enough on its own, whatever address the account holds:
     * a person who signed up with a password and linked Apple later has an
     * ordinary address that is not the one they were invited at.
     */
    public function test_accept_asks_a_user_with_an_apple_link_to_confirm_and_then_joins(): void
    {
        $this->enableEmailMismatchConfirmation();
        $this->enableSocialLogin();
        [$team, $invitation] = $this->teamWithInvitation('ayse@example.com', 'apple-on');
        $user = $this->makeUser('ayse.work@example.org');
        $this->linkProvider($user, 'apple');

        $this->actingAs($user)
            ->postJson('/invitations/apple-on/accept')
            ->assertStatus(409)
            ->assertJsonPath('code', 'email_mismatch')
            ->assertJsonPath('requires_confirmation', true);

        $this->actingAs($user)
            ->postJson('/invitations/apple-on/accept', ['confirm_email_mismatch' => true])
            ->assertOk();

        $this->assertTrue($team->fresh()->users()->where('user_id', $user->id)->exists());
        $this->assertNull(TeamInvitationControllerTestInvitation::query()->find($invitation->id));
    }

    /**
     * Only the confirmation is optional for the people who may give it. Everyone
     * else keeps the 403, even when the request claims to confirm.
     */
    public function test_accept_keeps_the_403_for_a_user_who_may_not_confirm_even_with_the_flag(): void
    {
        $this->enableEmailMismatchConfirmation();
        $this->enableSocialLogin();
        [$team, $invitation] = $this->teamWithInvitation('ayse@example.com', 'plain-on');
        $other = $this->makeUser('other@test.dev');

        foreach ([[], ['confirm_email_mismatch' => true]] as $payload) {
            $this->actingAs($other)
                ->postJson('/invitations/plain-on/accept', $payload)
                ->assertStatus(403)
                ->assertExactJson(['message' => 'This invitation was sent to a different email address.']);
        }

        $this->assertSame(0, $team->fresh()->users()->count());
        $this->assertNotNull(TeamInvitationControllerTestInvitation::query()->find($invitation->id));
    }

    public function test_accept_keeps_the_403_for_a_revoked_apple_link(): void
    {
        $this->enableEmailMismatchConfirmation();
        $this->enableSocialLogin();
        $this->teamWithInvitation('ayse@example.com', 'revoked-on');
        $user = $this->makeUser('ayse.work@example.org');
        $this->linkProvider($user, 'apple', ['revoked_at' => now()]);

        $this->actingAs($user)
            ->postJson('/invitations/revoked-on/accept', ['confirm_email_mismatch' => true])
            ->assertStatus(403);
    }

    public function test_accept_keeps_the_403_for_a_link_to_another_provider(): void
    {
        $this->enableEmailMismatchConfirmation();
        $this->enableSocialLogin();
        $this->teamWithInvitation('ayse@example.com', 'google-on');
        $user = $this->makeUser('ayse.work@example.org');
        $this->linkProvider($user, 'google');

        $this->actingAs($user)
            ->postJson('/invitations/google-on/accept', ['confirm_email_mismatch' => true])
            ->assertStatus(403);
    }

    /**
     * The relay domain is matched as the whole domain of the address, never as
     * a substring: a lookalike must not buy the confirmation path.
     */
    #[DataProvider('lookalikeRelayAddresses')]
    public function test_accept_keeps_the_403_for_a_lookalike_of_the_relay_domain(string $email): void
    {
        $this->enableEmailMismatchConfirmation();
        $this->teamWithInvitation('ayse@example.com', 'lookalike-on');
        $user = $this->makeUser($email);

        $this->actingAs($user)
            ->postJson('/invitations/lookalike-on/accept', ['confirm_email_mismatch' => true])
            ->assertStatus(403);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function lookalikeRelayAddresses(): array
    {
        return [
            'prefixed domain' => ['x7k2m9@evil-privaterelay.appleid.com'],
            'suffixed domain' => ['x7k2m9@privaterelay.appleid.com.evil.test'],
            'subdomain' => ['x7k2m9@mail.privaterelay.appleid.com'],
            'domain in the local part' => ['privaterelay.appleid.com@evil.test'],
        ];
    }

    public function test_accept_matches_the_relay_domain_case_insensitively(): void
    {
        $this->enableEmailMismatchConfirmation();
        $this->teamWithInvitation('ayse@example.com', 'relay-case');
        $user = $this->makeUser('X7K2M9@PrivateRelay.AppleID.com');

        $this->actingAs($user)
            ->postJson('/invitations/relay-case/accept')
            ->assertStatus(409);
    }

    /**
     * An application that never installed social login holds no Apple link and
     * may have no `social_accounts` table: the check must answer 403 rather
     * than fail on the query. The relay address still opens the question.
     */
    public function test_accept_does_not_read_social_accounts_when_social_login_is_off(): void
    {
        $this->enableEmailMismatchConfirmation();
        $this->teamWithInvitation('ayse@example.com', 'no-social');

        $this->actingAs($this->makeUser('other@test.dev'))
            ->postJson('/invitations/no-social/accept', ['confirm_email_mismatch' => true])
            ->assertStatus(403);

        $this->actingAs($this->makeUser(self::RELAY_EMAIL))
            ->postJson('/invitations/no-social/accept')
            ->assertStatus(409);
    }

    /**
     * A user without an address (a guest) can never match, so only an Apple
     * link can open the question for them.
     */
    public function test_accept_handles_a_user_without_an_email(): void
    {
        $this->enableEmailMismatchConfirmation();
        $this->enableSocialLogin();
        $this->teamWithInvitation('ayse@example.com', 'no-email');
        $guest = $this->makeUser(null);

        $this->actingAs($guest)
            ->postJson('/invitations/no-email/accept', ['confirm_email_mismatch' => true])
            ->assertStatus(403);

        $this->linkProvider($guest, 'apple');

        $this->actingAs($guest)
            ->postJson('/invitations/no-email/accept')
            ->assertStatus(409);
    }

    /**
     * Only a real yes confirms. Anything else is the question again, so a
     * client that sends the field blank cannot join by accident.
     */
    #[DataProvider('refusedConfirmations')]
    public function test_accept_asks_again_unless_the_confirmation_is_true(mixed $value): void
    {
        $this->enableEmailMismatchConfirmation();
        [$team] = $this->teamWithInvitation('ayse@example.com', 'not-confirmed');

        $this->actingAs($this->makeUser(self::RELAY_EMAIL))
            ->postJson('/invitations/not-confirmed/accept', ['confirm_email_mismatch' => $value])
            ->assertStatus(409);

        $this->assertSame(0, $team->fresh()->users()->count());
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function refusedConfirmations(): array
    {
        return [
            'false' => [false],
            'null' => [null],
            'zero' => [0],
            'the word no' => ['no'],
            'the word false' => ['false'],
            'an empty string' => [''],
        ];
    }

    /**
     * Turning the flag on changes nothing for the person the invitation was
     * sent to: there is no question to ask.
     */
    public function test_accept_does_not_ask_the_invited_person_to_confirm(): void
    {
        $this->enableEmailMismatchConfirmation();
        $invitee = $this->makeUser('Ayse@Example.com');
        [$team] = $this->teamWithInvitation('ayse@example.com', 'matched-on');

        $this->actingAs($invitee)
            ->postJson('/invitations/matched-on/accept')
            ->assertOk()
            ->assertExactJson(['data' => null, 'message' => 'Invitation accepted. You have joined the team.']);

        $this->assertTrue($team->fresh()->users()->where('user_id', $invitee->id)->exists());
    }

    /**
     * The confirmation is not a way round the expiry: the invitation is
     * deleted and answers 410 once the person has confirmed.
     */
    public function test_accept_still_refuses_an_expired_invitation_after_confirmation(): void
    {
        $this->enableEmailMismatchConfirmation();
        [$team, $invitation] = $this->teamWithInvitation('ayse@example.com', 'expired-on', [
            'expires_at' => now()->subDay(),
        ]);

        $this->actingAs($this->makeUser(self::RELAY_EMAIL))
            ->postJson('/invitations/expired-on/accept', ['confirm_email_mismatch' => true])
            ->assertStatus(410)
            ->assertExactJson(['message' => 'This invitation has expired.']);

        $this->assertSame(0, $team->fresh()->users()->count());
        $this->assertNull(TeamInvitationControllerTestInvitation::query()->find($invitation->id));
    }

    /**
     * A confirmed accept runs the same contract as any other, so the hook an
     * application binds still refuses a join (a member cap) and the invitation
     * stays for a retry. The unconfirmed call never reaches the contract.
     */
    public function test_accept_runs_the_bound_contract_only_after_confirmation(): void
    {
        $this->enableEmailMismatchConfirmation();
        $this->app->instance(AcceptsTeamInvitations::class, new class extends AcceptTeamInvitation
        {
            public int $calls = 0;

            protected function ensureCanJoin(Model $team, AuthenticatableContract $user): void
            {
                $this->calls++;

                $message = 'This household is full.';
                $exception = ValidationException::withMessages(['invitation' => $message]);
                $exception->response = new JsonResponse([
                    'message' => $message,
                    'code' => 'member_limit_reached',
                    'errors' => $exception->errors(),
                ], 422);

                throw $exception;
            }
        });
        [$team, $invitation] = $this->teamWithInvitation('ayse@example.com', 'capped-on');
        $relay = $this->makeUser(self::RELAY_EMAIL);

        $this->actingAs($relay)->postJson('/invitations/capped-on/accept')->assertStatus(409);

        $this->assertSame(0, $this->app->make(AcceptsTeamInvitations::class)->calls);

        $this->actingAs($relay)
            ->postJson('/invitations/capped-on/accept', ['confirm_email_mismatch' => true])
            ->assertStatus(422)
            ->assertJsonPath('code', 'member_limit_reached');

        $this->assertSame(1, $this->app->make(AcceptsTeamInvitations::class)->calls);
        $this->assertSame(0, $team->fresh()->users()->count());
        $this->assertNotNull(TeamInvitationControllerTestInvitation::query()->find($invitation->id));
    }

    public function test_accept_does_not_attach_a_confirmed_user_who_already_belongs(): void
    {
        $this->enableEmailMismatchConfirmation();
        [$team, $invitation] = $this->teamWithInvitation('ayse@example.com', 'member-on');
        $relay = $this->makeUser(self::RELAY_EMAIL);
        $team->users()->attach($relay->id, ['role' => 'member']);

        $this->actingAs($relay)
            ->postJson('/invitations/member-on/accept', ['confirm_email_mismatch' => true])
            ->assertOk()
            ->assertJsonPath('message', 'You are already a member of this team.');

        $this->assertSame(1, $team->fresh()->users()->count());
        $this->assertSame('member', $team->fresh()->users()->find($relay->id)?->pivot?->role);
        $this->assertNull(TeamInvitationControllerTestInvitation::query()->find($invitation->id));
    }

    /**
     * The token stays single use: the confirmed accept consumes it, so a second
     * call (from the same person or anybody else) finds no invitation.
     */
    public function test_accept_after_a_confirmed_join_does_not_attach_twice(): void
    {
        $this->enableEmailMismatchConfirmation();
        [$team] = $this->teamWithInvitation('ayse@example.com', 'twice-on');
        $relay = $this->makeUser(self::RELAY_EMAIL);

        $this->actingAs($relay)
            ->postJson('/invitations/twice-on/accept', ['confirm_email_mismatch' => true])
            ->assertOk();
        $this->actingAs($relay)
            ->postJson('/invitations/twice-on/accept', ['confirm_email_mismatch' => true])
            ->assertStatus(404);
        $this->actingAs($this->makeUser('second-relay@privaterelay.appleid.com'))
            ->postJson('/invitations/twice-on/accept', ['confirm_email_mismatch' => true])
            ->assertStatus(404);

        $this->assertSame(1, $team->fresh()->users()->count());
    }

    public function test_accept_asks_for_confirmation_in_the_callers_locale(): void
    {
        $this->enableEmailMismatchConfirmation();
        \call_user_func('app')->setLocale('tr');
        $this->teamWithInvitation('ayse@example.com', 'relay-tr');

        $this->actingAs($this->makeUser(self::RELAY_EMAIL))
            ->postJson('/invitations/relay-tr/accept')
            ->assertStatus(409)
            ->assertJsonPath('message', 'Bu davet farklı bir e-posta adresine gönderilmiş. Bu hesapla katılmak için onaylayın.');
    }

    /**
     * The invited address is shown to a person who is not yet known to own
     * it, so it is masked: the first character and the domain, which is what
     * they need to recognise their own address, and a fixed run of stars so
     * the length of the rest is not given away.
     */
    #[DataProvider('invitedAddresses')]
    public function test_the_confirmation_masks_the_invited_email(string $invited, string $masked): void
    {
        $this->enableEmailMismatchConfirmation();
        $this->teamWithInvitation($invited, 'mask-token');

        $this->actingAs($this->makeUser(self::RELAY_EMAIL))
            ->postJson('/invitations/mask-token/accept')
            ->assertStatus(409)
            ->assertJsonPath('masked_email', $masked);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invitedAddresses(): array
    {
        return [
            'a plain address' => ['ayse@example.com', 'a***@example.com'],
            'a one character local part' => ['a@example.com', 'a***@example.com'],
            'a long local part' => ['ayse.cakir.household@example.com', 'a***@example.com'],
            'a multibyte first character' => ['Çağlar@example.com', 'Ç***@example.com'],
            'a tagged address keeps its domain only' => ['ayse+home@mail.example.co.uk', 'a***@mail.example.co.uk'],
        ];
    }

    private function enableEmailMismatchConfirmation(): void
    {
        config(['magic-starter.invitation_confirm_email_mismatch' => true]);
    }

    /**
     * Turn social login on and create its table with the shipped migration.
     */
    private function enableSocialLogin(): void
    {
        config(['magic-starter.features' => [Features::socialLogin()]]);

        (require __DIR__ . '/../../../database/migrations/create_social_accounts_table.php')->up();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function linkProvider(TeamInvitationControllerTestUser $user, string $provider, array $attributes = []): void
    {
        SocialAccount::query()->create([
            'user_id' => $user->getKey(),
            'provider' => $provider,
            'provider_user_id' => $provider . '-' . $user->getKey(),
            ...$attributes,
        ]);
    }

    private function makeUser(?string $email): TeamInvitationControllerTestUser
    {
        return TeamInvitationControllerTestUser::query()->create(['name' => 'Person', 'email' => $email]);
    }

    /**
     * A team with an invitation to `$email`, owned by somebody else.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: TeamInvitationControllerTestTeam, 1: Model}
     */
    private function teamWithInvitation(string $email, string $token, array $attributes = []): array
    {
        $owner = $this->makeUser('owner-' . $token . '@test.dev');
        $team = TeamInvitationControllerTestTeam::query()->create([
            'user_id' => $owner->id,
            'name' => 'A Team',
            'personal_team' => false,
        ]);

        $invitation = $team->invitations()->create([
            'email' => $email,
            'role' => 'admin',
            'token' => $token,
            ...$attributes,
        ]);

        return [$team, $invitation];
    }
}

final class TeamInvitationControllerTestUser extends Model implements AuthenticatableContract
{
    use AuthenticatableTrait;
    use Authorizable;
    use \FlutterSdk\MagicStarter\Traits\HasProfilePhoto;
    use \FlutterSdk\MagicStarter\Traits\HasTeams;
    use HasUuids;

    protected $table = 'users';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}

final class TeamInvitationControllerTestTeam extends \FlutterSdk\MagicStarter\Models\Team
{
    protected $table = 'teams';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(TeamInvitationControllerTestUser::class, 'team_user', 'team_id', 'user_id')
            ->using(\FlutterSdk\MagicStarter\MagicStarter::membershipModel())
            ->withPivot('role')
            ->withTimestamps();
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(\FlutterSdk\MagicStarter\MagicStarter::teamInvitationModel(), 'team_id');
    }
}

final class TeamInvitationControllerTestInvitation extends \FlutterSdk\MagicStarter\Models\TeamInvitation
{
    protected $table = 'team_invitations';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}

final class TeamInvitationControllerTestMembership extends \FlutterSdk\MagicStarter\Models\TeamUser
{
    protected $table = 'team_user';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
