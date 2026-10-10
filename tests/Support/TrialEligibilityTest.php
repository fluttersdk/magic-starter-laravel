<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use FlutterSdk\MagicStarter\Models\BillingTrial;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use FlutterSdk\MagicStarter\Support\TrialEligibility;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Cashier\Billable;

/**
 * Who may start a free trial: somebody who is not a guest, has never trialed,
 * whose billable has never trialed, and whose billable has never held the
 * subscription this rail sells.
 *
 * Every refusal is paired with the limb that changes ONE fact and is allowed,
 * because "refuse everybody" passes every refusal on its own.
 */
class TrialEligibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['magic-starter.use_uuids' => true]);

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->uuid('current_team_id')->nullable();
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('name');
            $table->boolean('personal_team')->default(false);
            $table->string('stripe_id')->nullable();
            $table->timestamps();
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

        $this->artisan('migrate', [
            '--path' => __DIR__ . '/../../database/migrations/create_billing_trials_table.php',
            '--realpath' => true,
        ]);
    }

    /**
     * The control every refusal below is measured against.
     */
    public function test_a_registered_user_who_never_trialed_is_allowed(): void
    {
        $user = $this->createUser();

        $this->assertTrue($this->eligibility()->allows($user, $user));
    }

    /**
     * A guest account costs nothing to make, so a trial per guest is a trial per
     * click. Refused on the user model that carries `HasGuestSupport`.
     */
    public function test_a_guest_is_refused(): void
    {
        $guest = $this->createUser(['is_guest' => true]);

        $this->assertFalse($this->eligibility()->allows($guest, $guest));

        $guest->forceFill(['is_guest' => false])->save();

        $this->assertTrue($this->eligibility()->allows($guest->fresh(), $guest));
    }

    /**
     * `isGuest()` exists only where the application applied `HasGuestSupport`,
     * so a direct call on any other user model would be a fatal on the plans
     * endpoint. Without the trait there are no guests, and the user is allowed.
     */
    public function test_a_user_model_without_guest_support_is_not_a_guest(): void
    {
        $user = GuestlessUser::query()->create([
            'name' => 'No Guests',
            'email' => 'guestless@example.test',
            'is_guest' => true,
        ]);

        $this->assertFalse(method_exists($user, 'isGuest'));
        $this->assertTrue($this->eligibility()->allows($user, $user));
    }

    /**
     * One trial per person, whatever they bill: a row this user started for a
     * team refuses them a trial for their own account too.
     */
    public function test_a_user_with_a_prior_trial_row_is_refused(): void
    {
        $user = $this->createUser();
        $team = $this->createTeam($user);
        $this->recordTrial($user, $team);

        $this->assertFalse($this->eligibility()->allows($user, $user));

        // The disarming limb: somebody else, on the same kind of billable, is
        // untouched by that row.
        $other = $this->createUser();

        $this->assertTrue($this->eligibility()->allows($other, $other));
    }

    /**
     * One trial per billable: a team one member already trialed is refused to
     * the next member who tries, even though that member never trialed.
     */
    public function test_a_billable_with_a_prior_trial_row_is_refused_to_another_user(): void
    {
        $first = $this->createUser();
        $second = $this->createUser();
        $team = $this->createTeam($first);
        $this->recordTrial($first, $team);

        $this->assertFalse($this->eligibility()->allows($second, $team));

        // The disarming limb: the same second user on a team nobody trialed.
        $this->assertTrue($this->eligibility()->allows($second, $this->createTeam($second)));
    }

    /**
     * A refused row still counts. A trial the card check refused was a trial
     * started, and letting the refusal reset eligibility would hand a second
     * trial to exactly the person the check caught.
     */
    public function test_a_refused_trial_row_still_counts(): void
    {
        $user = $this->createUser();
        $this->recordTrial($user, $user, [
            'refused_at' => now(),
            'refusal_reason' => 'card_reused',
        ]);

        $this->assertFalse($this->eligibility()->allows($user, $user));
    }

    /**
     * Trials switched on over a database that never ran the `billing_trials`
     * migration offer nobody a trial, and say so once, rather than raising a
     * QueryException on the plans endpoint and the checkout.
     *
     * Asked twice on one instance, because both readers may ask within one
     * request and the table is looked for only the first time.
     */
    public function test_a_missing_trials_table_allows_no_trial_and_warns_once(): void
    {
        $user = $this->createUser();

        Schema::drop('billing_trials');
        Log::spy();
        DB::enableQueryLog();

        $eligibility = $this->eligibility();

        $this->assertFalse($eligibility->allows($user, $user));
        $this->assertFalse($eligibility->allows($user, $user));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => ($context['reason'] ?? null)
                === 'billing_trials_table_missing')
            ->once();

        $this->assertSame([], array_filter(
            array_column(DB::getQueryLog(), 'query'),
            static fn (string $query): bool => str_contains($query, 'from "billing_trials"'),
        ));
    }

    /**
     * The billable is matched on its morph class AND its key, so a row for a
     * team never refuses a user who happens to share its key.
     */
    public function test_a_row_for_another_billable_type_with_the_same_key_does_not_refuse(): void
    {
        $user = $this->createUser();
        $stranger = $this->createUser();

        $this->recordTrial($stranger, $user, [
            'billable_type' => (new ConcreteTeam)->getMorphClass(),
        ]);

        $this->assertTrue($this->eligibility()->allows($user, $user));

        // The disarming limb: the same key under the user's own morph class.
        $this->recordTrial($stranger, $user);

        $this->assertFalse($this->eligibility()->allows($user, $user));
    }

    /**
     * A billable that has held this rail's subscription is a returning
     * customer, not a new one, in ANY status: a cancelled one ended, and a
     * granting one would meet the checkout's `subscription_exists` refusal
     * right after a "Start free trial" button.
     */
    public function test_a_billable_with_a_cancelled_default_subscription_is_refused(): void
    {
        $user = $this->createUser();
        $team = $this->createTeam($user);
        $this->createSubscription($team, 'default', 'canceled');

        $this->assertFalse($this->eligibility()->allows($user, $team));
    }

    /**
     * Only the `default` type is this rail's subscription; an adopter's own
     * named subscription says nothing about whether this one was ever bought.
     */
    public function test_a_subscription_of_another_type_does_not_refuse(): void
    {
        $user = $this->createUser();
        $team = $this->createTeam($user);
        $this->createSubscription($team, 'seats', 'active');

        $this->assertTrue($this->eligibility()->allows($user, $team));
    }

    /**
     * The billable is the application's model, and an attribute of its own
     * named `subscriptions` (a counter column, say) shadows Cashier's relation
     * on `getAttribute()`. That is not a list of subscriptions, so it says
     * nothing held and the history decides, instead of a foreach over a number.
     */
    public function test_a_subscriptions_attribute_that_is_not_a_list_holds_nothing(): void
    {
        $user = $this->createUser();
        $team = $this->createTeam($user);
        $team->setAttribute('subscriptions', 3);

        $this->assertTrue($this->eligibility()->allows($user, $team));

        $this->recordTrial($user, $team);

        $this->assertFalse($this->eligibility()->allows($user, $team));
    }

    private function eligibility(): TrialEligibility
    {
        return $this->app->make(TrialEligibility::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createUser(array $attributes = []): ConcreteUser
    {
        return ConcreteUser::query()->create(array_merge([
            'name' => 'Trial Candidate',
            'email' => 'trial-' . Str::uuid() . '@example.test',
            'password' => 'secret',
        ], $attributes));
    }

    private function createTeam(ConcreteUser $owner): CashierTrialTeam
    {
        return CashierTrialTeam::query()->create([
            'user_id' => $owner->getKey(),
            'name' => 'Trial Team',
        ]);
    }

    private function createSubscription(Model $team, string $type, string $status): void
    {
        DB::table('subscriptions')->insert([
            'team_id' => $team->getKey(),
            'type' => $type,
            'stripe_id' => 'sub_' . Str::random(10),
            'stripe_status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function recordTrial(Model $user, Model $billable, array $overrides = []): void
    {
        BillingTrial::query()->create(array_merge([
            'user_id' => $user->getKey(),
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'stripe_subscription_id' => 'sub_' . Str::random(10),
            'subscription_created_at' => now(),
        ], $overrides));
    }
}

/**
 * A team carrying Cashier's real billable trait. The foreign key is pinned to
 * `team_id` because Cashier derives it from the class basename.
 */
class CashierTrialTeam extends ConcreteTeam
{
    use Billable;

    protected $table = 'teams';

    public function getForeignKey(): string
    {
        return 'team_id';
    }
}

/**
 * A user model whose application never applied `HasGuestSupport`, so it has no
 * `isGuest()` even though an `is_guest` column happens to exist.
 */
class GuestlessUser extends Model implements AuthenticatableContract
{
    use AuthenticatableTrait;
    use ConditionallyUsesUuids;

    protected $table = 'users';

    protected $guarded = [];
}
