<?php

namespace FlutterSdk\MagicStarter\Tests\Actions;

use FlutterSdk\MagicStarter\Actions\ReleaseUserBilling;
use FlutterSdk\MagicStarter\Contracts\ReleasesUserBilling;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Support\BillingReleaseFailed;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\Support\StripeHttpStub;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Laravel\Cashier\Billable;
use Stripe\Exception\ApiErrorException;

/**
 * Releasing a user's billing for an account deletion: the card rail's
 * subscriptions end in Stripe at once, with no proration and no final invoice,
 * and a store's subscription, which only the customer can cancel, is reported.
 *
 * Only the Stripe transport is stubbed ({@see StripeHttpStub}), so the requests
 * asserted here are the ones Stripe would have received.
 */
class ReleaseUserBillingTest extends TestCase
{
    use RefreshDatabase;

    private StripeHttpStub $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::useUserModel(ReleaseUserBillingTestUser::class);

        config([
            'auth.providers.users.model' => ReleaseUserBillingTestUser::class,
            'magic-starter.billing.billable' => 'user',
            'cashier.secret' => 'sk_test_release_user_billing',
        ]);

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('plan')->nullable();
            $table->string('plan_status')->nullable();
            $table->string('plan_provider')->nullable();
            $table->string('plan_manage_url')->nullable();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('user_id');
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

        $this->stripe = StripeHttpStub::install();
    }

    protected function tearDown(): void
    {
        StripeHttpStub::uninstall();

        parent::tearDown();
    }

    public function test_the_contract_resolves_to_the_default_action(): void
    {
        $this->assertInstanceOf(ReleaseUserBilling::class, $this->app->make(ReleasesUserBilling::class));
    }

    /**
     * Nothing bills a free user, so nothing is asked of Stripe and nothing is
     * reported.
     */
    public function test_a_user_nothing_bills_releases_nothing(): void
    {
        $user = $this->createUser();

        $this->assertSame($this->nothing(), $this->release($user));
        $this->assertSame([], $this->stripe->requests);
    }

    /**
     * The cancel is Stripe's DELETE with `prorate` and `invoice_now` off. Never
     * Cashier's `cancelNow()`, which prorates by default and would credit or
     * invoice a person who is leaving.
     */
    public function test_a_live_stripe_subscription_is_cancelled_without_proration_or_a_final_invoice(): void
    {
        $user = $this->createUser();
        $this->subscribe($user, 'sub_live', 'active');
        $this->stripe->answer($this->canceled('sub_live'));

        $release = $this->release($user);

        $this->assertSame([
            'cancelled' => [
                [
                    'provider' => 'stripe',
                    'id' => 'sub_live',
                ],
            ],
            'store' => [],
        ], $release);

        $deletes = $this->stripe->requestsTo('delete', '/v1/subscriptions/sub_live');
        $this->assertCount(1, $deletes);
        $this->assertSame('false', $deletes[0]['params']['prorate']);
        $this->assertSame('false', $deletes[0]['params']['invoice_now']);
        $this->assertCount(1, $this->stripe->requests, 'Nothing else is asked of Stripe.');
    }

    /**
     * The local Cashier row is ended with the Stripe subscription, rather than
     * left valid until the `customer.subscription.deleted` webhook lands: the
     * purge and the deleter hold an account while a card subscription is valid,
     * and an immediate deletion would otherwise be held by its own release.
     */
    public function test_the_local_row_is_ended_with_the_stripe_subscription(): void
    {
        Carbon::setTestNow('2026-10-29 12:00:00');
        $user = $this->createUser();
        $this->subscribe($user, 'sub_live', 'active');
        $this->stripe->answer($this->canceled('sub_live'));

        $this->release($user);

        $row = $user->subscriptions()->firstOrFail();
        $this->assertSame('canceled', $row->stripe_status);
        $this->assertSame('2026-10-29 12:00:00', $row->ends_at?->toDateTimeString());
        $this->assertTrue($row->ended());
    }

    /**
     * Whatever Stripe could still bill or activate ends with the account: a
     * trial, a subscription cancelled at period end, a payment in dunning and a
     * first payment still pending. A subscription that has ended already is not
     * asked about.
     */
    public function test_every_subscription_that_has_not_ended_is_cancelled(): void
    {
        $user = $this->createUser();
        $this->subscribe($user, 'sub_trial', 'trialing', trialEndsAt: now()->addDays(5));
        $this->subscribe($user, 'sub_grace', 'active', endsAt: now()->addDays(10));
        $this->subscribe($user, 'sub_dunning', 'past_due');
        $this->subscribe($user, 'sub_pending', 'incomplete');
        $this->subscribe($user, 'sub_ended', 'canceled', endsAt: now()->subDay());

        foreach (['sub_trial', 'sub_grace', 'sub_dunning', 'sub_pending'] as $id) {
            $this->stripe->answer($this->canceled($id));
        }

        $release = $this->release($user);

        $this->assertEqualsCanonicalizing(
            ['sub_trial', 'sub_grace', 'sub_dunning', 'sub_pending'],
            array_column($release['cancelled'], 'id'),
        );
        $this->assertSame([], $user->subscriptions()->get()->reject->ended()->all());
        $this->assertSame([], $this->stripe->requestsTo('delete', '/v1/subscriptions/sub_ended'));
        $this->assertCount(4, $this->stripe->requests);
    }

    /**
     * A store's subscription lives in the customer's store account, which this
     * application cannot reach. It is named so the client can send the person
     * there, and nothing is asked of Stripe.
     */
    public function test_a_store_subscription_is_reported_and_never_cancelled(): void
    {
        $user = $this->createUser();
        $user->forceFill([
            'plan' => 'pro',
            'plan_status' => 'active',
            'plan_provider' => 'play_store',
            'plan_manage_url' => 'https://play.google.com/store/account/subscriptions',
        ])->save();

        $release = $this->release($user);

        $this->assertSame([
            'cancelled' => [],
            'store' => [
                [
                    'provider' => 'play_store',
                    'manage_url' => 'https://play.google.com/store/account/subscriptions',
                ],
            ],
        ], $release);
        $this->assertSame([], $this->stripe->requests);
    }

    /**
     * A store entitlement that has lapsed bills nobody, so there is nothing to
     * send the person to the store for.
     */
    public function test_a_lapsed_store_entitlement_is_not_reported(): void
    {
        $user = $this->createUser();
        $user->forceFill([
            'plan' => null,
            'plan_status' => 'canceled',
            'plan_provider' => 'app_store',
        ])->save();

        $this->assertSame($this->nothing(), $this->release($user));
    }

    /**
     * A store with no management link reports the provider and a null link.
     */
    public function test_a_store_subscription_without_a_management_link_reports_a_null_link(): void
    {
        $user = $this->createUser();
        $user->forceFill([
            'plan' => 'pro',
            'plan_status' => 'active',
            'plan_provider' => 'app_store',
        ])->save();

        $this->assertSame([
            [
                'provider' => 'app_store',
                'manage_url' => null,
            ],
        ], $this->release($user)['store']);
    }

    /**
     * A subscription Stripe no longer knows (deleted in the dashboard, a wiped
     * test account) is already released. Treating it as a failure would keep a
     * stale local row from ever letting the person leave.
     */
    public function test_a_subscription_stripe_no_longer_knows_counts_as_released(): void
    {
        $user = $this->createUser();
        $this->subscribe($user, 'sub_gone', 'active');
        $this->stripe
            ->answer($this->noSuchSubscription('sub_gone'), 404)
            ->answer($this->noSuchSubscription('sub_gone'), 404);

        $release = $this->release($user);

        $this->assertSame('sub_gone', $release['cancelled'][0]['id']);
        $this->assertTrue($user->subscriptions()->firstOrFail()->ended(), 'A stale row is ended too.');
    }

    /**
     * Stripe refusing the cancel of a live subscription is a failure, and it is
     * raised: the caller must not go on as if the person had stopped paying.
     */
    public function test_a_stripe_failure_raises_a_billing_release_failure(): void
    {
        $user = $this->createUser();
        $this->subscribe($user, 'sub_unreachable', 'active');

        try {
            $this->release($user);
            $this->fail('Expected the Stripe failure to be raised.');
        } catch (BillingReleaseFailed $failure) {
            $this->assertInstanceOf(ApiErrorException::class, $failure->getPrevious());
            $this->assertStringContainsString('sub_unreachable', $failure->getMessage());
        }

        $this->assertTrue($user->subscriptions()->firstOrFail()->valid(), 'A subscription Stripe kept stays valid.');
    }

    /**
     * The subscriptions are cancelled one by one, so a failure on the second
     * leaves the first cancelled. It is raised all the same, and a retry finds
     * the first already ended in Stripe.
     */
    public function test_a_failure_on_a_later_subscription_still_raises(): void
    {
        $user = $this->createUser();
        $this->subscribe($user, 'sub_first', 'active');
        $this->subscribe($user, 'sub_second', 'active');
        $this->stripe->answer($this->canceled('sub_first'));

        try {
            $this->release($user);
            $this->fail('Expected the second cancel to fail.');
        } catch (BillingReleaseFailed) {
            $this->assertCount(2, $this->stripe->requests, 'The first cancel went out before the second failed.');
        }
    }

    /**
     * Under team billing the money is on the team, never on the user, so the
     * user has nothing to release.
     */
    public function test_under_team_billing_the_user_has_nothing_to_release(): void
    {
        config([
            'magic-starter.billing.billable' => 'team',
        ]);

        $user = $this->createUser();
        $this->subscribe($user, 'sub_orphaned_row', 'active');
        $user->forceFill([
            'plan' => 'pro',
            'plan_status' => 'active',
            'plan_provider' => 'app_store',
        ])->save();

        $this->assertSame($this->nothing(), $this->release($user));
        $this->assertSame([], $this->stripe->requests);
    }

    /**
     * @return array{cancelled: list<array{provider: string, id: string}>, store: list<array{provider: string, manage_url: string|null}>}
     */
    private function release(ReleaseUserBillingTestUser $user): array
    {
        return $this->app->make(ReleasesUserBilling::class)->release($user);
    }

    /**
     * @return array{cancelled: list<mixed>, store: list<mixed>}
     */
    private function nothing(): array
    {
        return [
            'cancelled' => [],
            'store' => [],
        ];
    }

    private function createUser(): ReleaseUserBillingTestUser
    {
        return ReleaseUserBillingTestUser::query()->create([
            'name' => 'Leaver',
        ]);
    }

    private function subscribe(
        ReleaseUserBillingTestUser $user,
        string $id,
        string $status,
        ?Carbon $trialEndsAt = null,
        ?Carbon $endsAt = null,
    ): void {
        $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => $id,
            'stripe_status' => $status,
            'stripe_price' => 'price_pro',
            'quantity' => 1,
            'trial_ends_at' => $trialEndsAt,
            'ends_at' => $endsAt,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function canceled(string $id): array
    {
        return [
            'id' => $id,
            'object' => 'subscription',
            'customer' => 'cus_leaver',
            'status' => 'canceled',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function noSuchSubscription(string $id): array
    {
        return [
            'error' => [
                'type' => 'invalid_request_error',
                'code' => 'resource_missing',
                'message' => 'No such subscription: ' . $id,
            ],
        ];
    }
}

/**
 * A user Cashier bills. The foreign key is pinned because Cashier derives it
 * from the class basename.
 */
class ReleaseUserBillingTestUser extends ConcreteUser
{
    use Billable;

    public function getForeignKey(): string
    {
        return 'user_id';
    }
}
