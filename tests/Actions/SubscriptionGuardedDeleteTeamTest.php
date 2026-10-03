<?php

namespace FlutterSdk\MagicStarter\Tests\Actions;

use Carbon\CarbonInterface;
use FlutterSdk\MagicStarter\Actions\SubscriptionGuardedDeleteTeam;
use FlutterSdk\MagicStarter\Contracts\DeletesTeams;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Laravel\Cashier\Billable;

/**
 * The subscription delete guard: a team a store is still billing, or one that
 * carries a valid Cashier subscription, cannot be deleted through this action,
 * and every other team can. Each rail refuses with its own sentence.
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
class SubscriptionGuardedDeleteTeamTest extends TestCase
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
        // `CashierTeam` pins its foreign key to `team_id`, which moves this
        // inferred column with it, so both shapes are present.
        Schema::create('team_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('concrete_team_id')->nullable();
            $table->uuid('team_id')->nullable();
            $table->string('email');
            $table->string('role')->nullable();
            $table->string('token');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('team_id');
            $table->string('type');
            $table->string('stripe_id')->unique();
            $table->string('stripe_status');
            $table->string('stripe_price')->nullable();
            $table->integer('quantity')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });

        // Cashier's subscription model eager-loads its items on every read.
        Schema::create('subscription_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('subscription_id');
            $table->string('stripe_id')->unique();
            $table->string('stripe_product');
            $table->string('stripe_price');
            $table->integer('quantity')->nullable();
            $table->timestamps();
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
            SubscriptionGuardedDeleteTeam::class,
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
                (new SubscriptionGuardedDeleteTeam)->delete($team);
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
            (new SubscriptionGuardedDeleteTeam)->delete($team);
        }
    }

    /**
     * `plan_provider = 'stripe'` is provenance, not a subscription: the card
     * rail's guard reads Cashier's own subscription rows, so a team whose row
     * merely names the rail, with no subscription behind it, is deletable.
     */
    public function test_stripe_provenance_without_a_cashier_subscription_can_be_deleted(): void
    {
        $team = $this->makeTeam([
            'plan' => 'pro',
            'plan_status' => 'active',
            'plan_provider' => 'stripe',
        ]);

        (new SubscriptionGuardedDeleteTeam)->delete($team);

        $this->assertNull(ConcreteTeam::query()->find($team->getKey()));
    }

    /**
     * A valid Cashier subscription refuses the deletion with the card rail's
     * own sentence, and the team, its members and its subscription stay.
     *
     * Nothing is cancelled on the customer's behalf: deleting the team would
     * leave Stripe charging a subject that no longer exists, and cancelling
     * for them is a decision this package does not take.
     */
    public function test_a_team_with_an_active_stripe_subscription_cannot_be_deleted(): void
    {
        $team = $this->makeCashierTeam();
        $member = ConcreteUser::query()->create(['name' => 'Member']);
        $team->users()->attach($member->getKey(), ['role' => 'member']);
        $this->subscribe($team, 'active');

        try {
            (new SubscriptionGuardedDeleteTeam)->delete($team);
            $this->fail('Expected a refusal for a valid Stripe subscription.');
        } catch (ValidationException $exception) {
            $this->assertSame('deleteTeam', $exception->errorBag);
            $this->assertSame(
                __('magic-starter::billing.refusals.stripe_subscription_active'),
                $exception->errors()['team'][0],
            );
        }

        $this->assertNotNull(CashierTeam::query()->find($team->getKey()));
        $this->assertSame(1, $team->users()->count(), 'A refused deletion must leave the members attached.');
        $this->assertSame('active', $team->subscriptions()->first()?->stripe_status);
    }

    /**
     * Cashier's `valid()` is the definition, so a subscription cancelled at
     * period end still refuses until that period is over, and a trial refuses
     * like a paid period does.
     */
    public function test_a_cancelled_subscription_still_inside_its_paid_period_refuses(): void
    {
        $graceTeam = $this->makeCashierTeam();
        $this->subscribe($graceTeam, 'active', endsAt: now()->addDays(10));

        $trialTeam = $this->makeCashierTeam();
        $this->subscribe($trialTeam, 'trialing', trialEndsAt: now()->addDays(5));

        foreach ([$graceTeam, $trialTeam] as $team) {
            $this->assertTrue(
                $this->readsStripeBilling($team),
                'A subscription Cashier still calls valid must keep the team inside the guard.',
            );
        }
    }

    /**
     * An ended or unpaid subscription no longer bills anything, so the team is
     * deletable outright.
     */
    public function test_an_ended_or_unpaid_stripe_subscription_does_not_refuse(): void
    {
        $endedTeam = $this->makeCashierTeam();
        $this->subscribe($endedTeam, 'canceled', endsAt: now()->subDay());

        $unpaidTeam = $this->makeCashierTeam();
        $this->subscribe($unpaidTeam, 'unpaid');

        foreach ([$endedTeam, $unpaidTeam] as $team) {
            $this->assertFalse($this->readsStripeBilling($team));

            (new SubscriptionGuardedDeleteTeam)->delete($team);

            $this->assertNull(CashierTeam::query()->find($team->getKey()));
        }
    }

    /**
     * Under user billing a team's own subscriptions are not the billable
     * subject's, so the card guard does not fire on the team.
     */
    public function test_the_stripe_guard_does_not_apply_when_the_billable_subject_is_the_user(): void
    {
        $team = $this->makeCashierTeam();
        $this->subscribe($team, 'active');

        config(['magic-starter.billing.billable' => 'user']);

        $this->assertFalse($this->readsStripeBilling($team));
    }

    /**
     * Both rails refuse with their own sentence, in both locales, and the two
     * sentences differ: "cancel it in the store" and "cancel it in billing"
     * are different instructions.
     */
    public function test_the_stripe_refusal_reads_from_the_shipped_catalogue_in_both_locales(): void
    {
        $englishCatalogue = require __DIR__ . '/../../lang/en/billing.php';
        $turkishCatalogue = require __DIR__ . '/../../lang/tr/billing.php';

        $team = $this->makeCashierTeam();
        $this->subscribe($team, 'active');

        $this->app->setLocale('tr');
        $this->assertRefusalMessage($team, $turkishCatalogue['refusals']['stripe_subscription_active']);

        $this->assertNotSame(
            $englishCatalogue['refusals']['stripe_subscription_active'],
            $turkishCatalogue['refusals']['stripe_subscription_active'],
        );
        $this->assertNotSame(
            $englishCatalogue['refusals']['stripe_subscription_active'],
            $englishCatalogue['refusals']['store_subscription_active'],
        );
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

        (new SubscriptionGuardedDeleteTeam)->delete($team);

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

        (new SubscriptionGuardedDeleteTeam)->delete($team);

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

        $this->assertFalse(SubscriptionGuardedDeleteTeam::storeIsBilling($team));

        (new SubscriptionGuardedDeleteTeam)->delete($team);

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
            SubscriptionGuardedDeleteTeam::storeIsBilling($team),
            'A NULL plan must read as "not above free", the same as an explicit free tier would.',
        );
    }

    /**
     * The adopter's own catalogue names their floor, and a subject sitting on
     * it is not paying for anything.
     *
     * This is the half a bare null check cannot answer. An application that
     * writes its own free-tier WORD on a downgrade, rather than the NULL this
     * package's writer stores, would otherwise read as store-billed forever:
     * `plan_provider` is provenance and survives the subscription ending, so
     * the team could never be deleted again, and the refusal would name a
     * subscription its owner had already cancelled.
     *
     * The floor is `tier_order[0]` because the config's documented convention
     * is cheapest first. Nothing here names a tier the package invented.
     */
    public function test_the_declared_floor_tier_does_not_read_as_store_billed(): void
    {
        config(['magic-starter.billing.tier_order' => ['free', 'pro', 'business']]);

        $team = $this->makeTeam([
            'plan' => 'free',
            'plan_status' => 'active',
            'plan_provider' => 'app_store',
        ]);

        $this->assertFalse(
            SubscriptionGuardedDeleteTeam::storeIsBilling($team),
            'A subject on the catalogue\'s floor tier holds nothing anybody is paying for.',
        );

        $team->forceFill(['plan' => 'pro'])->save();

        $this->assertTrue(
            SubscriptionGuardedDeleteTeam::storeIsBilling($team),
            'The tier above the floor is the control: without it, a predicate that always '
            . 'answered false would pass the assertion above.',
        );
    }

    /**
     * An UNPUBLISHED catalogue keeps exactly the behaviour it had before the
     * floor existed, which is what makes reading it a widening rather than a
     * change: the package ships an empty `tier_order`, so an adopter who never
     * publishes one must not see their guard move under them.
     */
    public function test_an_unpublished_catalogue_leaves_the_guard_where_it_was(): void
    {
        config(['magic-starter.billing.tier_order' => []]);

        $team = $this->makeTeam([
            'plan' => 'free',
            'plan_status' => 'active',
            'plan_provider' => 'app_store',
        ]);

        $this->assertTrue(
            SubscriptionGuardedDeleteTeam::storeIsBilling($team),
            'With no catalogue there is no floor to recognise, so a named tier reads as paid, '
            . 'exactly as it did before the floor was read at all.',
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

        $this->assertFalse(SubscriptionGuardedDeleteTeam::storeIsBilling($team));

        (new SubscriptionGuardedDeleteTeam)->delete($team);

        $this->assertNull(ConcreteTeam::query()->find($team->getKey()));
    }

    /**
     * A non-billable, non-team model always answers false rather than raising.
     */
    public function test_a_non_billable_model_never_reads_as_store_billed(): void
    {
        config(['magic-starter.billing.billable' => 'team']);

        $this->assertFalse(SubscriptionGuardedDeleteTeam::storeIsBilling(new ConcreteUser));
    }

    private function assertRefusalMessage(Model $team, string $expected): void
    {
        try {
            (new SubscriptionGuardedDeleteTeam)->delete($team);
            $this->fail('Expected the store-billed team to be refused.');
        } catch (ValidationException $exception) {
            $this->assertSame($expected, $exception->errors()['team'][0]);
        }
    }

    /**
     * Ask the trait's predicate through the guard, the one class that uses it here.
     */
    private function readsStripeBilling(Model $team): bool
    {
        $guard = new class extends SubscriptionGuardedDeleteTeam
        {
            public function asks(Model $team): bool
            {
                return $this->stripeIsBilling($team);
            }
        };

        return $guard->asks($team);
    }

    private function makeCashierTeam(): CashierTeam
    {
        MagicStarter::useTeamModel(CashierTeam::class);

        $owner = ConcreteUser::query()->create([
            'name' => 'Card Owner',
        ]);

        return CashierTeam::query()->forceCreate([
            'user_id' => $owner->getKey(),
            'name' => 'Card Team',
            'personal_team' => false,
        ]);
    }

    private function subscribe(
        CashierTeam $team,
        string $status,
        ?CarbonInterface $endsAt = null,
        ?CarbonInterface $trialEndsAt = null,
    ): void {
        $team->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_' . bin2hex(random_bytes(6)),
            'stripe_status' => $status,
            'stripe_price' => 'price_pro',
            'quantity' => 1,
            'ends_at' => $endsAt,
            'trial_ends_at' => $trialEndsAt,
        ]);
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

/**
 * A team that carries Cashier's billable trait, the shape a team-billing
 * adopter ships. The foreign key is pinned to `team_id` because Cashier
 * derives it from the class basename, which no real subscriptions table uses.
 */
class CashierTeam extends ConcreteTeam
{
    use Billable;

    public function getForeignKey(): string
    {
        return 'team_id';
    }
}
