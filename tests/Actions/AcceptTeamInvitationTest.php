<?php

namespace FlutterSdk\MagicStarter\Tests\Actions;

use Carbon\CarbonInterface;
use Closure;
use FlutterSdk\MagicStarter\Actions\AcceptTeamInvitation;
use FlutterSdk\MagicStarter\Contracts\AcceptsTeamInvitations;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Support\TeamInvitationRefused;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeamInvitation;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * The default accept action, driven directly.
 *
 * The endpoint tests in TeamInvitationControllerTest cover what a client sees;
 * these cover the contract an application binds over: what the action refuses,
 * what it leaves behind, and that the `ensureCanJoin` hook runs inside the
 * transaction and under the team row's lock, where a plan's member cap can be
 * counted without two joins racing past it.
 */
final class AcceptTeamInvitationTest extends TestCase
{
    use RefreshDatabase;

    private ConcreteUser $owner;

    private ConcreteUser $invitee;

    private ConcreteTeam $team;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique()->nullable();
            $table->string('password')->nullable();
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('name');
            $table->boolean('personal_team')->default(false);
            $table->string('profile_photo_path', 2048)->nullable();
            $table->timestamps();
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
            $table->uuid('team_id');
            $table->string('email');
            $table->string('role')->nullable()->default('member');
            $table->string('token')->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        $this->owner = ConcreteUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $this->invitee = ConcreteUser::query()->create(['name' => 'Invitee', 'email' => 'invitee@test.dev']);
        $this->team = ConcreteTeam::query()->create([
            'user_id' => $this->owner->getKey(),
            'name' => 'A Team',
            'personal_team' => false,
        ]);
    }

    public function test_the_contract_resolves_to_the_default_action(): void
    {
        $this->assertInstanceOf(AcceptTeamInvitation::class, app(AcceptsTeamInvitations::class));
    }

    public function test_accept_attaches_the_user_with_the_invitations_role_and_deletes_it(): void
    {
        $invitation = $this->invite($this->invitee, 'admin');

        (new AcceptTeamInvitation)->accept($this->invitee, $invitation);

        $this->assertSame('admin', $this->roleOf($this->invitee));
        $this->assertFalse($invitation->exists, 'The invitation must be consumed.');
        $this->assertSame(0, ConcreteTeamInvitation::query()->count());
    }

    /**
     * An expired invitation never joins anyone, and it is deleted all the same:
     * the delete must survive the refusal, which is why it is not inside the
     * transaction the join runs in.
     */
    public function test_accept_refuses_an_expired_invitation_and_deletes_it(): void
    {
        $invitation = $this->invite($this->invitee, 'admin', expiresAt: now()->subDay());

        $refusal = $this->defaultRefusalOf(fn () => (new AcceptTeamInvitation)->accept($this->invitee, $invitation));

        $this->assertSame(TeamInvitationRefused::EXPIRED, $refusal->refusal());
        $this->assertSame(__('magic-starter::teams.invitations.expired'), $refusal->getMessage());
        $this->assertNull($this->roleOf($this->invitee));
        $this->assertSame(0, ConcreteTeamInvitation::query()->count());
    }

    /**
     * Already a member is not an error to the person, but the contract returns
     * only after a join, so it answers with a refusal the endpoint turns back
     * into its 200. The invitation is cleared first, and the role they hold
     * is not touched.
     */
    public function test_accept_refuses_a_member_without_attaching_again_and_clears_the_invitation(): void
    {
        $this->team->users()->attach($this->invitee->getKey(), ['role' => 'member']);
        $invitation = $this->invite($this->invitee, 'admin');

        $refusal = $this->defaultRefusalOf(fn () => (new AcceptTeamInvitation)->accept($this->invitee, $invitation));

        $this->assertSame(TeamInvitationRefused::ALREADY_JOINED, $refusal->refusal());
        $this->assertSame(__('magic-starter::teams.invitations.already_joined'), $refusal->getMessage());
        $this->assertSame(1, $this->team->users()->count());
        $this->assertSame('member', $this->roleOf($this->invitee));
        $this->assertSame(0, ConcreteTeamInvitation::query()->count());
    }

    /**
     * Ownership lives on `teams.user_id` and writes no pivot row, so a
     * membership query alone would let the owner attach themselves.
     */
    public function test_accept_refuses_the_owner_without_giving_them_a_pivot_row(): void
    {
        $invitation = $this->invite($this->owner, 'admin');

        $refusal = $this->defaultRefusalOf(fn () => (new AcceptTeamInvitation)->accept($this->owner, $invitation));

        $this->assertSame(TeamInvitationRefused::ALREADY_JOINED, $refusal->refusal());
        $this->assertSame(0, $this->team->users()->count());
        $this->assertSame(0, ConcreteTeamInvitation::query()->count());
    }

    /**
     * Two requests that loaded the invitation before either finished hold the
     * same row: the second sees the first's membership under the lock instead
     * of attaching again. A stale model instance stands in for the second.
     */
    public function test_a_second_accept_of_the_same_invitation_does_not_attach_twice(): void
    {
        $first = $this->invite($this->invitee, 'admin');
        $second = ConcreteTeamInvitation::query()->findOrFail($first->getKey());

        (new AcceptTeamInvitation)->accept($this->invitee, $first);
        $refusal = $this->defaultRefusalOf(fn () => (new AcceptTeamInvitation)->accept($this->invitee, $second));

        $this->assertSame(TeamInvitationRefused::ALREADY_JOINED, $refusal->refusal());
        $this->assertSame(1, $this->team->users()->count());
        $this->assertSame('admin', $this->roleOf($this->invitee));
    }

    public function test_the_hook_runs_once_inside_a_transaction_and_before_the_attach(): void
    {
        $invitation = $this->invite($this->invitee, 'admin');
        $outside = DB::transactionLevel();

        $action = new class extends AcceptTeamInvitation
        {
            /** @var list<array{level: int, member: bool, team: mixed, user: mixed}> */
            public array $calls = [];

            protected function ensureCanJoin(Model $team, Authenticatable $user): void
            {
                $this->calls[] = [
                    'level' => DB::transactionLevel(),
                    'member' => $team->users()->where('user_id', $user->getAuthIdentifier())->exists(),
                    'team' => $team->getKey(),
                    'user' => $user->getAuthIdentifier(),
                ];
            }
        };

        $action->accept($this->invitee, $invitation);

        $this->assertCount(1, $action->calls);
        $this->assertSame($outside + 1, $action->calls[0]['level'], 'The hook must run inside the join transaction.');
        $this->assertFalse($action->calls[0]['member'], 'The hook must run before the attach.');
        $this->assertSame($this->team->getKey(), $action->calls[0]['team']);
        $this->assertSame($this->invitee->getKey(), $action->calls[0]['user']);
        $this->assertSame('admin', $this->roleOf($this->invitee));
    }

    public function test_the_hook_receives_the_team_as_a_fresh_instance_of_the_team_model(): void
    {
        $invitation = $this->invite($this->invitee, 'admin');
        ConcreteTeam::query()->whereKey($this->team->getKey())->update(['name' => 'Renamed elsewhere']);

        $seen = new class
        {
            public ?Model $team = null;
        };
        $action = new class($seen) extends AcceptTeamInvitation
        {
            public function __construct(private readonly object $seen) {}

            protected function ensureCanJoin(Model $team, Authenticatable $user): void
            {
                $this->seen->team = $team;
            }
        };

        $action->accept($this->invitee, $invitation);

        $this->assertInstanceOf(MagicStarter::teamModel(), $seen->team);
        $this->assertNotSame($this->team, $seen->team, 'The locked row is read again, not the caller\'s copy.');
        $this->assertSame('Renamed elsewhere', $seen->team->getAttribute('name'));
    }

    /**
     * A refusal in the hook undoes everything the transaction did. The hook
     * here writes before it refuses (it attaches the user itself), so the
     * absence of a membership afterwards proves the rollback rather than only
     * the order of the checks.
     */
    public function test_a_refusal_in_the_hook_rolls_back_and_leaves_the_invitation_intact(): void
    {
        $invitation = $this->invite($this->invitee, 'admin');

        $action = new class extends AcceptTeamInvitation
        {
            protected function ensureCanJoin(Model $team, Authenticatable $user): void
            {
                $team->users()->attach($user->getAuthIdentifier(), ['role' => 'member']);

                throw ValidationException::withMessages(['invitation' => 'Full.']);
            }
        };

        $refusal = $this->refusalOf(fn () => $action->accept($this->invitee, $invitation));

        $this->assertSame('Full.', $refusal->getMessage());
        $this->assertNull($this->roleOf($this->invitee), 'No membership may survive the refusal.');
        $this->assertSame(1, ConcreteTeamInvitation::query()->count(), 'The invitation must stay for a retry.');
    }

    public function test_the_hook_is_not_asked_about_an_expired_invitation_or_a_member(): void
    {
        $action = new class extends AcceptTeamInvitation
        {
            public int $asked = 0;

            protected function ensureCanJoin(Model $team, Authenticatable $user): void
            {
                $this->asked++;
            }
        };

        $expired = $this->invite($this->invitee, 'admin', expiresAt: now()->subDay());
        $this->refusalOf(fn () => $action->accept($this->invitee, $expired));

        $this->team->users()->attach($this->invitee->getKey(), ['role' => 'member']);
        $member = $this->invite($this->invitee, 'admin');
        $this->refusalOf(fn () => $action->accept($this->invitee, $member));

        $this->assertSame(0, $action->asked);
    }

    /**
     * What an application's cap looks like: count the household inside the
     * hook and refuse at the limit. Two invitations accepted back to back at a
     * limit of two (the owner and one more) admit exactly one, and the refused
     * one stays pending.
     */
    public function test_a_member_cap_in_the_hook_admits_one_of_two_back_to_back_invitations(): void
    {
        $second = ConcreteUser::query()->create(['name' => 'Second', 'email' => 'second@test.dev']);
        $firstInvitation = $this->invite($this->invitee, 'member');
        $secondInvitation = $this->invite($second, 'member');

        $action = new class extends AcceptTeamInvitation
        {
            protected function ensureCanJoin(Model $team, Authenticatable $user): void
            {
                $used = 1 + $team->users()->count();

                if ($used >= 2) {
                    throw ValidationException::withMessages(['invitation' => 'Full.']);
                }
            }
        };

        $action->accept($this->invitee, $firstInvitation);
        $refusal = $this->refusalOf(fn () => $action->accept($second, $secondInvitation));

        $this->assertSame('Full.', $refusal->getMessage());
        $this->assertSame(1, $this->team->users()->count());
        $this->assertNotNull($this->roleOf($this->invitee));
        $this->assertNull($this->roleOf($second));
        $this->assertSame(1, ConcreteTeamInvitation::query()->where('email', 'second@test.dev')->count());
    }

    /**
     * SQLite ignores `for update`, so a lock that was dropped passes every test
     * above; this one reads the SQL a real PostgreSQL receives, and runs only
     * on `DB_CONNECTION=pgsql` (the CI `pgsql` job).
     */
    public function test_the_join_locks_the_team_row(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Row locks show only on PostgreSQL; run with DB_CONNECTION=pgsql.');
        }

        $invitation = $this->invite($this->invitee, 'admin');
        $locking = [];

        DB::listen(function (QueryExecuted $query) use (&$locking): void {
            if (preg_match('/^select .* from "teams" .* for update$/i', $query->sql) === 1) {
                $locking[] = $query->sql;
            }
        });

        (new AcceptTeamInvitation)->accept($this->invitee, $invitation);

        $this->assertNotEmpty($locking, 'No `for update` select from teams ran.');
    }

    private function invite(ConcreteUser $user, string $role, ?CarbonInterface $expiresAt = null): ConcreteTeamInvitation
    {
        // `team_id` is not mass assignable, and the fixture team's own relation guesses another column.
        $invitation = (new ConcreteTeamInvitation)->forceFill([
            'team_id' => $this->team->getKey(),
            'email' => $user->email,
            'role' => $role,
            'token' => 'token-' . $user->getKey() . '-' . ($expiresAt === null ? 'open' : 'lapsed'),
            'expires_at' => $expiresAt,
        ]);
        $invitation->save();

        return $invitation;
    }

    private function roleOf(ConcreteUser $user): ?string
    {
        return $this->team->users()->find($user->getKey())?->pivot?->role;
    }

    /**
     * Run `$call`, which must refuse as the default action does, and return the refusal.
     *
     * @param  Closure(): mixed  $call
     */
    private function defaultRefusalOf(Closure $call): TeamInvitationRefused
    {
        $refusal = $this->refusalOf($call);

        $this->assertInstanceOf(TeamInvitationRefused::class, $refusal);

        return $refusal;
    }

    /**
     * Run `$call`, which must refuse with a validation error, and return it.
     *
     * @param  Closure(): mixed  $call
     */
    private function refusalOf(Closure $call): ValidationException
    {
        try {
            $call();
        } catch (ValidationException $refusal) {
            return $refusal;
        }

        $this->fail('Expected a ValidationException.');
    }
}
