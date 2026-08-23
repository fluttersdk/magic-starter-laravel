<?php

namespace FlutterSdk\MagicStarter\Tests\Actions;

use FlutterSdk\MagicStarter\Actions\StoreSubscriptionGuardedDeleteTeam;
use FlutterSdk\MagicStarter\Contracts\DeletesTeams;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * The store-owned delete guard: a team a store is still billing cannot be
 * deleted through this action, and every other team can.
 *
 * The tier half of the guard is a NULL CHECK and nothing more, because this
 * package has no tier vocabulary to name a "free" plan with. The application
 * this action was ported from read a typed accessor that answered its own
 * free tier for both a stored `'free'` value and a revoked NULL, so its call
 * site never had to re-decide what NULL meant; here the raw column already
 * carries that meaning by the writer's own convention (a revoked team stores
 * NULL, never a free-tier word), so a plain null check is the whole of what
 * the accessor bought. The NULL-versus-`'free'` tests below exist because a
 * worker who instead compared the raw column against the literal `'free'`
 * would get the revoked case wrong forever: NULL is not `'free'`, so a
 * `!== 'free'` comparison answers true for it, and a team the store stopped
 * billing would never be deletable again.
 */
class StoreSubscriptionGuardedDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Every case but one asks about a team, so 'team' is the default
        // subject; the one case that needs 'user' sets it after the team row
        // exists, since creating the row does not depend on the subject.
        config(['magic-starter.billing.billable' => 'team']);

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $this->addEntitlementColumns($table);
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('name');
            $table->boolean('personal_team')->default(false);
            $table->string('profile_photo_path')->nullable();
            $this->addEntitlementColumns($table);
            $table->timestamps();
        });

        Schema::create('team_user', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->uuid('user_id');
            $table->string('role')->nullable();
            $table->timestamps();
        });

        // `Team::invitations()` declares no explicit foreign key, so Eloquent
        // infers one from the RUNTIME class name of the team instance calling
        // it. The fixture bound in TestCase is `ConcreteTeam`, so the column
        // this action's inherited `parent::delete()` actually queries is
        // `concrete_team_id`, not `team_id`.
        Schema::create('team_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('concrete_team_id');
            $table->string('email');
            $table->string('role')->nullable();
            $table->string('token');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * The two provenance columns this guard reads; the `plan` tier column is
     * added by the caller since most cases here vary it.
     */
    private function addEntitlementColumns(Blueprint $table): void
    {
        $table->string('plan')->nullable();
        $table->string('plan_status')->nullable();
        $table->string('plan_provider')->nullable();
    }

    /**
     * Bound over `DeletesTeams`, replacing the plain action.
     */
    public function test_the_contract_resolves_to_the_guarded_action(): void
    {
        $this->assertInstanceOf(
            StoreSubscriptionGuardedDeleteTeam::class,
            $this->app->make(DeletesTeams::class),
        );
    }

    /**
     * Both stores refuse the deletion while a paid plan is still active.
     */
    public function test_a_store_funded_team_cannot_be_deleted(): void
    {
        foreach (['app_store', 'play_store'] as $provider) {
            $team = $this->makeTeam([
                'plan' => 'pro',
                'plan_status' => 'active',
                'plan_provider' => $provider,
            ]);

            try {
                (new StoreSubscriptionGuardedDeleteTeam)->delete($team);
                $this->fail(sprintf('Expected a refusal for provider [%s].', $provider));
            } catch (ValidationException $exception) {
                $this->assertSame('deleteTeam', $exception->errorBag);
                $this->assertArrayHasKey('team', $exception->errors());
            }

            $this->assertNotNull(ConcreteTeam::query()->find($team->getKey()), 'A refused deletion must leave the team in place.');
        }
    }

    /**
     * The refusal sentence is read from the shipped catalogue, in both
     * locales.
     *
     * This proves the action reads the catalogue rather than carrying an
     * inline literal. It does NOT prove the Turkish sentence is actually
     * Turkish: an English sentence pasted into `tr/billing.php` would satisfy
     * this assertion too, since it only checks that the raised message equals
     * whatever that file happens to hold. Step 18's locale-parity check is
     * what closes that half, by asserting the two locale values differ.
     */
    public function test_the_refusal_reads_from_the_shipped_catalogue_in_both_locales(): void
    {
        $team = $this->makeTeam([
            'plan' => 'business',
            'plan_status' => 'active',
            'plan_provider' => 'app_store',
        ]);

        $englishCatalogue = require __DIR__ . '/../../lang/en/billing.php';
        $turkishCatalogue = require __DIR__ . '/../../lang/tr/billing.php';

        $this->app->setLocale('en');
        $this->assertRefusalMessage($team, $englishCatalogue['refusals']['store_subscription_active']);

        $this->app->setLocale('tr');
        $this->assertRefusalMessage($team, $turkishCatalogue['refusals']['store_subscription_active']);

        $this->assertNotSame(
            $englishCatalogue['refusals']['store_subscription_active'],
            $turkishCatalogue['refusals']['store_subscription_active'],
            'The Turkish sentence must not be the English one pasted across.',
        );
    }

    /**
     * `past_due` and `grace` are dunning statuses, not lost plans: the rail is
     * still trying to take the money, which is exactly the state where
     * deleting the team strands a charge. Both must stay INSIDE the guard.
     */
    public function test_a_dunning_store_subscription_stays_inside_the_guard(): void
    {
        foreach (['past_due', 'grace'] as $status) {
            $team = $this->makeTeam([
                'plan' => 'pro',
                'plan_status' => $status,
                'plan_provider' => 'app_store',
            ]);

            $this->expectException(ValidationException::class);
            (new StoreSubscriptionGuardedDeleteTeam)->delete($team);
        }
    }

    /**
     * A Stripe-funded team is not this guard's concern: the application is the
     * merchant on that rail, so deleting the team is the end of the story.
     */
    public function test_a_stripe_funded_team_can_be_deleted(): void
    {
        $team = $this->makeTeam([
            'plan' => 'pro',
            'plan_status' => 'active',
            'plan_provider' => 'stripe',
        ]);

        (new StoreSubscriptionGuardedDeleteTeam)->delete($team);

        $this->assertNull(ConcreteTeam::query()->find($team->getKey()));
    }

    /**
     * A manually-granted plan has no rail to strand a charge on either.
     */
    public function test_a_manually_granted_team_can_be_deleted(): void
    {
        $team = $this->makeTeam([
            'plan' => 'business',
            'plan_status' => 'active',
            'plan_provider' => 'manual',
        ]);

        (new StoreSubscriptionGuardedDeleteTeam)->delete($team);

        $this->assertNull(ConcreteTeam::query()->find($team->getKey()));
    }

    /**
     * A team no rail has ever charged is deletable outright.
     */
    public function test_an_unfunded_team_can_be_deleted(): void
    {
        $team = $this->makeTeam([
            'plan' => null,
            'plan_status' => null,
            'plan_provider' => null,
        ]);

        (new StoreSubscriptionGuardedDeleteTeam)->delete($team);

        $this->assertNull(ConcreteTeam::query()->find($team->getKey()));
    }

    /**
     * The ordinary shape of a lapsed subscription: revocation pairs a NULL
     * `plan` with a non-granting `plan_status` (the writer's own invariant),
     * `plan_provider` still says `app_store` because provenance survives.
     * Neither half of the guard fires, so the team is deletable outright.
     */
    public function test_a_revoked_store_team_with_a_null_plan_can_be_deleted(): void
    {
        $team = $this->makeTeam([
            'plan' => null,
            'plan_status' => 'canceled',
            'plan_provider' => 'app_store',
        ]);

        $this->assertFalse(StoreSubscriptionGuardedDeleteTeam::storeIsBilling($team));

        (new StoreSubscriptionGuardedDeleteTeam)->delete($team);

        $this->assertNull(ConcreteTeam::query()->find($team->getKey()));
    }

    /**
     * THE DEFECT THIS STEP EXISTS TO PREVENT, isolated from the status half.
     *
     * A row with a NULL `plan` but a STILL-GRANTING `plan_status` is what
     * separates a null check from a `!== 'free'` comparison: the status half
     * alone cannot refuse this row (it grants), so only the tier half decides.
     * The null check reads "not above free" and lets it through; a worker who
     * instead wrote `$plan !== 'free'` would read NULL as "not free", i.e. as
     * a real tier, and the guard would refuse the deletion forever, exactly
     * the defect this step exists to prevent. Kept as a direct call to the
     * predicate, deliberately, so the assertion is about this ONE field and
     * not entangled with the status check's own refusal.
     */
    public function test_a_null_plan_alone_does_not_read_as_store_billed(): void
    {
        $team = $this->makeTeam([
            'plan' => null,
            'plan_status' => 'active',
            'plan_provider' => 'app_store',
        ]);

        $this->assertFalse(
            StoreSubscriptionGuardedDeleteTeam::storeIsBilling($team),
            'A NULL plan must read as "not above free", the same as an explicit free tier would.',
        );
    }

    /**
     * Under `billing.billable = 'user'` a team's own row carries no rail at
     * all: the money is on the user's row, not the team's, so this guard must
     * not fire even when the team row happens to carry store-shaped values.
     */
    public function test_the_guard_does_not_apply_when_the_billable_subject_is_the_user(): void
    {
        $team = $this->makeTeam([
            'plan' => 'pro',
            'plan_status' => 'active',
            'plan_provider' => 'app_store',
        ]);

        // Switched AFTER the row exists: creating it does not depend on the
        // billable subject, and the whole point of this test is asking the
        // predicate the question with the subject pointed at the user.
        config(['magic-starter.billing.billable' => 'user']);

        $this->assertFalse(StoreSubscriptionGuardedDeleteTeam::storeIsBilling($team));

        (new StoreSubscriptionGuardedDeleteTeam)->delete($team);

        $this->assertNull(ConcreteTeam::query()->find($team->getKey()));
    }

    /**
     * A non-billable, non-team model always answers false rather than raising.
     */
    public function test_a_non_billable_model_never_reads_as_store_billed(): void
    {
        config(['magic-starter.billing.billable' => 'team']);

        $this->assertFalse(StoreSubscriptionGuardedDeleteTeam::storeIsBilling(new ConcreteUser));
    }

    private function assertRefusalMessage(Model $team, string $expected): void
    {
        try {
            (new StoreSubscriptionGuardedDeleteTeam)->delete($team);
            $this->fail('Expected the store-billed team to be refused.');
        } catch (ValidationException $exception) {
            $this->assertSame($expected, $exception->errors()['team'][0]);
        }
    }

    private function makeTeam(array $overrides): ConcreteTeam
    {
        $owner = ConcreteUser::query()->create([
            'name' => 'Team Owner',
        ]);

        return ConcreteTeam::query()->forceCreate([
            'user_id' => $owner->getKey(),
            'name' => 'Guarded Team',
            'personal_team' => false,
            ...$overrides,
        ]);
    }
}
