<?php

namespace FlutterSdk\MagicStarter\Tests\Policies;

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Policies\BillingPolicy;
use FlutterSdk\MagicStarter\Policies\TeamPolicy;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use ReflectionClass;
use ReflectionMethod;

/**
 * The billing write gate, and the registration it must not make.
 *
 * The test that carries this file is the COEXISTENCE one. Every other assertion
 * here passes with the policy registered as `Gate::policy(billableModel(), ...)`
 * instead of as a named ability, because a model-policy registration answers the
 * billing question perfectly well; what it silently destroys is the OTHER
 * registration on the same model, since under `billing.billable = 'team'` the
 * billable model IS the team model and the policy map is a plain assignment. So
 * a suite that only exercises billing certifies a build where team member
 * management, invitations and deletion are no longer guarded by anything.
 */
class BillingPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $schema = app('db.schema');

        $schema->create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });

        $schema->create('teams', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('name');
            $table->boolean('personal_team')->default(false);
            $table->timestamps();
        });

        $schema->create('team_user', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->uuid('user_id');
            $table->string('role')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Both registrations are on the same model, and both still answer.
     */
    public function test_teams_and_billing_enabled_together_leave_both_gates_answering(): void
    {
        $this->bootProvider([Features::teams(), Features::billing()], 'team');

        // The premise of the whole file: this is the configuration in which the
        // billable model and the team model are the same class, so a second
        // Gate::policy() call would have somewhere to land.
        $this->assertSame(MagicStarter::teamModel(), MagicStarter::billableModel());

        $owner = $this->createUser('coexistence-owner@example.test');
        $member = $this->createUser('coexistence-member@example.test');
        $team = $this->createTeam($owner);
        $this->attachMember($team, $member, 'admin');

        // Half one: the team policy is still the team model's policy, and it
        // still answers with ITS OWN rule rather than with a stand-in. The
        // instance check alone would survive a policy that lost its methods.
        $this->assertInstanceOf(TeamPolicy::class, Gate::getPolicyFor(MagicStarter::teamModel()));
        $this->assertTrue(Gate::forUser($owner)->allows('delete', $team));
        $this->assertTrue(Gate::forUser($member)->denies('delete', $team));

        // Half two: the billing ability answers, on the same model, at the same
        // time, with the ownership split it exists for.
        $this->assertTrue(Gate::has('manageBilling'));
        $this->assertTrue(Gate::forUser($owner)->allows('manageBilling', $team));
        $this->assertTrue(Gate::forUser($member)->denies('manageBilling', $team));
    }

    /**
     * The ability is the billing feature's, and exists only while it is on.
     */
    public function test_the_ability_is_absent_when_billing_is_disabled(): void
    {
        $this->bootProvider([Features::teams()], 'team');

        $this->assertFalse(Gate::has('manageBilling'));

        // The disarming limb: the provider really did boot, it just did not
        // define this one. Without it an assertion on a gate nobody touched
        // would pass for the wrong reason.
        $this->assertInstanceOf(TeamPolicy::class, Gate::getPolicyFor(MagicStarter::teamModel()));
    }

    /**
     * Nothing registers this policy against a model, on either billable subject.
     */
    public function test_the_policy_is_never_registered_as_the_billable_model_s_policy(): void
    {
        $this->bootProvider([Features::billing()], 'user');

        $this->assertSame(MagicStarter::userModel(), MagicStarter::billableModel());
        $this->assertTrue(Gate::has('manageBilling'));
        $this->assertNull(Gate::getPolicyFor(MagicStarter::billableModel()));
    }

    /**
     * A write is the owner's, and neither membership predicate substitutes.
     */
    public function test_a_write_requires_the_owner_under_the_team_subject(): void
    {
        $this->bootProvider([Features::teams(), Features::billing()], 'team');

        $owner = $this->createUser('write-owner@example.test');
        $member = $this->createUser('write-member@example.test');
        $stranger = $this->createUser('write-stranger@example.test');
        $team = $this->createTeam($owner);
        $this->attachMember($team, $member, 'admin');

        // The disarming limb, and it is the point of the fixture: this member is
        // a real member AND holds the highest pivot role, so both predicates the
        // docblock warns against would authorize them. The refusal below is
        // therefore about ownership and not about an under-built fixture.
        $this->assertTrue($member->belongsToTeam($team));
        $this->assertTrue($member->hasTeamRole($team, 'admin'));

        $policy = new BillingPolicy;

        $this->assertTrue($policy->manage($owner, $team));
        $this->assertFalse($policy->manage($member, $team));
        $this->assertFalse($policy->manage($stranger, $team));
    }

    /**
     * Under the user subject the owner is the billable itself.
     */
    public function test_ownership_is_identity_under_the_user_subject(): void
    {
        $this->bootProvider([Features::billing()], 'user');

        $user = $this->createUser('identity-self@example.test');
        $other = $this->createUser('identity-other@example.test');

        $policy = new BillingPolicy;

        $this->assertTrue($policy->manage($user, $user));
        $this->assertFalse($policy->manage($user, $other));

        $this->assertTrue(Gate::forUser($user)->allows('manageBilling', $user));
        $this->assertTrue(Gate::forUser($user)->denies('manageBilling', $other));
    }

    /**
     * There is one ability here, so a read cannot be refused by this policy.
     *
     * The five reads are open to any member on purpose, and the way that rule is
     * kept is that no read ever routes through a gate: it is scoped by membership
     * at the controller. A permissive `view()` here would be a second home for
     * the same rule and free to disagree with the first. The same assertion pins
     * the absence of a `before()` admin hatch, which would also be public.
     */
    public function test_the_policy_exposes_the_write_ability_and_nothing_else(): void
    {
        $policy = new ReflectionClass(BillingPolicy::class);

        $abilities = array_map(
            fn (ReflectionMethod $method): string => $method->getName(),
            array_filter(
                $policy->getMethods(ReflectionMethod::IS_PUBLIC),
                // HandlesAuthorization contributes two public helpers of its own
                // (denyWithStatus, denyAsNotFound) and they are not abilities. A
                // trait method reports the USING class as its declaring class, so
                // the defining file is what tells the two apart, and a before()
                // written in this policy would still be declared right here.
                fn (ReflectionMethod $method): bool => $method->getFileName() === $policy->getFileName(),
            ),
        );

        sort($abilities);

        $this->assertSame(['manage'], $abilities);
    }

    /**
     * Re-boot the provider against a feature set and a billable subject.
     *
     * The gate is thrown away first. It is a container singleton resolved during
     * the application's own boot, so without this every assertion would be
     * reading the registrations made under the DEFAULT feature set (all off)
     * plus whatever this method added, and the disabled case could never fail.
     *
     * @param  list<string>  $features  The features to enable.
     * @param  string  $billable  The billable subject token.
     */
    private function bootProvider(array $features, string $billable): void
    {
        config([
            'magic-starter.features' => $features,
            'magic-starter.billing.billable' => $billable,
        ]);

        $this->app->forgetInstance(GateContract::class);
        Gate::clearResolvedInstance(GateContract::class);

        (new MagicStarterServiceProvider($this->app))->boot();
    }

    private function createUser(string $email): ConcreteUser
    {
        return ConcreteUser::query()->create([
            'name' => 'Test User',
            'email' => $email,
        ]);
    }

    private function createTeam(ConcreteUser $owner): ConcreteTeam
    {
        return ConcreteTeam::query()->create([
            'user_id' => $owner->getKey(),
            'name' => 'Billed Team',
            'personal_team' => false,
        ]);
    }

    private function attachMember(ConcreteTeam $team, ConcreteUser $user, string $role): void
    {
        $team->users()->attach($user->getKey(), [
            'role' => $role,
        ]);
    }
}
