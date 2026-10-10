<?php

namespace FlutterSdk\MagicStarter\Tests\Jobs;

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Jobs\CheckTrialCard;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\BillingTrial;
use FlutterSdk\MagicStarter\Models\Subscription;
use FlutterSdk\MagicStarter\Models\SubscriptionItem;
use FlutterSdk\MagicStarter\Notifications\TrialRefusedNotification;
use FlutterSdk\MagicStarter\Support\TrialCardGateway;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Cashier\Billable;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription as CashierSubscription;
use Laravel\Cashier\SubscriptionItem as CashierSubscriptionItem;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * Locks the trial card check: one trial per person, per subject and per card,
 * with the EARLIEST subscription surviving.
 *
 * The gateway is replaced, so every rule here is the job's own: what it does
 * with each of the gateway's three answers, which row of a conflicting set it
 * keeps, that the refusal is written before the cancel, that a refused
 * duplicate on the same subject leaves the subject's `default` subscription
 * pointing at the survivor, and that a subscription already paying is never
 * touched. {@see \FlutterSdk\MagicStarter\Tests\Support\TrialCardGatewayTest}
 * pins what the real gateway sends to Stripe.
 *
 * The duplicate scenarios run in BOTH job orders. Two checkouts opened seconds
 * apart queue two jobs, and which of them a worker picks first is not in
 * anybody's control; a rule that kept "whichever job ran first" would refuse
 * the legitimate first trial half the time.
 */
class CheckTrialCardTest extends TestCase
{
    private FakeTrialCardGateway $gateway;

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set([
            'database.default' => 'testing',
            'database.connections.testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'cache.default' => 'array',
            'magic-starter.features' => [Features::billing()],
            'magic-starter.billing.billable' => 'user',
            'magic-starter.billing.tier_order' => ['free', 'pro'],
            'magic-starter.billing.products' => [
                'pro_monthly' => [
                    'type' => 'subscription',
                    'tier' => 'pro',
                    'cycle' => 'monthly',
                    'trial_days' => 14,
                    'refs' => ['stripe_price' => 'price_pro'],
                ],
            ],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::useUserModel(User::class);
        Cashier::useSubscriptionModel(Subscription::class);
        Cashier::useSubscriptionItemModel(SubscriptionItem::class);

        $this->createSchema();

        $this->gateway = new FakeTrialCardGateway;
        $this->app->instance(TrialCardGateway::class, $this->gateway);

        Notification::fake();

        $this->freezeTime();
    }

    protected function tearDown(): void
    {
        Cashier::useSubscriptionModel(CashierSubscription::class);
        Cashier::useSubscriptionItemModel(CashierSubscriptionItem::class);
        MagicStarter::reset();

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // The three answers the gateway can give
    // -------------------------------------------------------------------------

    public function test_a_card_fingerprint_is_stored_and_the_trial_kept(): void
    {
        $user = $this->makeUser();
        $trial = $this->trial($user, $user, 'sub_only', minutesAgo: 5);

        $this->gateway->fingerprints['sub_only'] = 'fp_only';

        $this->runJob($trial);

        $trial->refresh();
        $this->assertSame('fp_only', $trial->card_fingerprint);
        $this->assertNotNull($trial->checked_at);
        $this->assertNull($trial->refused_at);
        $this->assertSame([], $this->gateway->cancelled);
    }

    /**
     * No payment method yet is worth asking again, so the job releases itself
     * a minute out; on its last attempt it keeps the trial instead, because a
     * release there would only fail the job before `handle()` ever ran again.
     */
    public function test_a_missing_payment_method_releases_and_then_gives_up_keeping_the_trial(): void
    {
        Log::spy();

        $user = $this->makeUser();
        $trial = $this->trial($user, $user, 'sub_no_pm', minutesAgo: 5);

        $first = $this->runJob($trial);

        $first->assertReleased(60);
        $this->assertNull($trial->refresh()->checked_at);

        $last = $this->runJob($trial, attempts: 6);

        $last->assertNotReleased();
        $trial->refresh();
        $this->assertNotNull($trial->checked_at);
        $this->assertNull($trial->refused_at);
        $this->assertNull($trial->card_fingerprint);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => ($context['reason'] ?? null)
                === 'trial_card_never_attached');
    }

    public function test_a_payment_method_without_a_card_keeps_the_trial(): void
    {
        $user = $this->makeUser();
        $trial = $this->trial($user, $user, 'sub_sepa', minutesAgo: 5);

        $this->gateway->fingerprints['sub_sepa'] = TrialCardGateway::NO_CARD;

        $job = $this->runJob($trial);

        $job->assertNotReleased();
        $trial->refresh();
        $this->assertNotNull($trial->checked_at);
        $this->assertNull($trial->refused_at);
        $this->assertNull($trial->card_fingerprint);
    }

    // -------------------------------------------------------------------------
    // Earliest wins
    // -------------------------------------------------------------------------

    /**
     * One person, one subject, two trials opened side by side (two tabs, a
     * double tap): the earlier survives and the later is refused as a
     * `duplicate`, whichever job runs first.
     *
     * The two cards differ on purpose, so the match is the PERSON and not the
     * card. The later one's local Cashier row is deleted, because left in place
     * it is the newest `default` row and `subscription('default')` would answer
     * the cancelled one to every swap, cancel and trial-date read after it.
     */
    #[DataProvider('jobOrders')]
    public function test_one_person_keeps_the_earlier_of_two_parallel_trials(bool $earlierFirst): void
    {
        $user = $this->makeUser();
        $earlier = $this->trial($user, $user, 'sub_earlier', minutesAgo: 10);
        $later = $this->trial($user, $user, 'sub_later', minutesAgo: 5);

        $this->gateway->fingerprints = [
            'sub_earlier' => 'fp_first_card',
            'sub_later' => 'fp_second_card',
        ];

        $this->runInOrder($earlierFirst, $earlier, $later);

        $earlier->refresh();
        $later->refresh();

        $this->assertNull($earlier->refused_at);
        $this->assertNotNull($earlier->checked_at);

        $this->assertNotNull($later->refused_at);
        $this->assertSame('duplicate', $later->refusal_reason);
        $this->assertNotNull($later->checked_at);

        $this->assertSame(['sub_later'], $this->gateway->cancelled);
        $this->assertTrue(
            $this->gateway->refusedWhenCancelled['sub_later'],
            'The cancel ran before the refusal was written.',
        );

        // The refused duplicate is gone from the local table, items included,
        // and the subject's `default` subscription is the survivor.
        $this->assertNull(Subscription::query()->where('stripe_id', 'sub_later')->first());
        $this->assertSame(0, SubscriptionItem::query()->where('stripe_id', 'si_sub_later')->count());
        $this->assertSame('sub_earlier', $user->fresh()?->subscription('default')?->stripe_id);

        // A same-person duplicate still holds its surviving trial, so there is
        // nothing to tell them.
        Notification::assertNothingSent();
    }

    /**
     * Two people, one card. The later trial's job runs first and finds nothing
     * to compare against, because the earlier one's fingerprint has not been
     * read yet; when it is, the LATER trial is the one refused, as
     * `card_reused`, and its holder is told.
     *
     * The refused trial bills another subject, so its local row is NOT deleted:
     * it is that subject's only subscription, and Stripe's deletion event is
     * what closes it and revokes the tier it was granted.
     */
    public function test_a_reused_card_refuses_the_later_trial_when_the_earlier_fingerprint_arrives_late(): void
    {
        $first = $this->makeUser();
        $second = $this->makeUser();
        $earlier = $this->trial($first, $first, 'sub_first_person', minutesAgo: 10);
        $later = $this->trial($second, $second, 'sub_second_person', minutesAgo: 5);

        $this->gateway->fingerprints['sub_second_person'] = 'fp_shared_card';

        // The later trial is checked first and kept: nothing shares its card yet.
        $this->runJob($later);

        $this->assertNull($later->refresh()->refused_at);
        $this->assertNotNull($later->checked_at);

        // The earlier one's card arrives.
        $this->gateway->fingerprints['sub_first_person'] = 'fp_shared_card';

        $this->runJob($earlier);

        $earlier->refresh();
        $later->refresh();

        $this->assertNull($earlier->refused_at);
        $this->assertSame('fp_shared_card', $earlier->card_fingerprint);
        $this->assertNotNull($later->refused_at);
        $this->assertSame('card_reused', $later->refusal_reason);
        $this->assertSame(['sub_second_person'], $this->gateway->cancelled);

        $this->assertNotNull(Subscription::query()->where('stripe_id', 'sub_second_person')->first());

        Notification::assertSentTo($second, TrialRefusedNotification::class);
        Notification::assertNotSentTo($first, TrialRefusedNotification::class);
    }

    /**
     * Two people, one card, both jobs in either order with both fingerprints
     * readable: the later trial is refused either way.
     */
    #[DataProvider('jobOrders')]
    public function test_a_shared_card_refuses_the_later_trial_in_either_order(bool $earlierFirst): void
    {
        $first = $this->makeUser();
        $second = $this->makeUser();
        $earlier = $this->trial($first, $first, 'sub_card_a', minutesAgo: 10);
        $later = $this->trial($second, $second, 'sub_card_b', minutesAgo: 5);

        $this->gateway->fingerprints = [
            'sub_card_a' => 'fp_same',
            'sub_card_b' => 'fp_same',
        ];

        $this->runInOrder($earlierFirst, $earlier, $later);

        $this->assertNull($earlier->refresh()->refused_at);
        $this->assertSame('card_reused', $later->refresh()->refusal_reason);
        $this->assertSame(['sub_card_b'], $this->gateway->cancelled);
    }

    /**
     * A later duplicate that already converted to a paid subscription is kept.
     * It is a customer paying, and the check only ever refuses a TRIAL.
     */
    public function test_a_later_duplicate_that_already_converted_is_kept(): void
    {
        $user = $this->makeUser();
        $earlier = $this->trial($user, $user, 'sub_trialing', minutesAgo: 10);
        $later = $this->trial($user, $user, 'sub_paying', minutesAgo: 5, status: 'active');

        $this->gateway->fingerprints = [
            'sub_trialing' => 'fp_a',
            'sub_paying' => 'fp_b',
        ];

        $this->runJob($earlier);

        $later->refresh();
        $this->assertNull($later->refused_at);
        $this->assertNotNull($later->checked_at);
        $this->assertSame([], $this->gateway->cancelled);
        $this->assertNotNull(Subscription::query()->where('stripe_id', 'sub_paying')->first());
    }

    /**
     * A finished run is a no-op on a re-run: no second Stripe read, no second
     * cancel, no second mail.
     */
    public function test_a_rerun_is_idempotent(): void
    {
        $first = $this->makeUser();
        $second = $this->makeUser();
        $earlier = $this->trial($first, $first, 'sub_rerun_a', minutesAgo: 10);
        $later = $this->trial($second, $second, 'sub_rerun_b', minutesAgo: 5);

        $this->gateway->fingerprints = [
            'sub_rerun_a' => 'fp_rerun',
            'sub_rerun_b' => 'fp_rerun',
        ];

        $this->runJob($earlier);
        $this->runJob($later);

        $asked = count($this->gateway->asked);

        $this->runJob($earlier);
        $this->runJob($later);

        $this->assertCount($asked, $this->gateway->asked);
        $this->assertSame(['sub_rerun_b'], $this->gateway->cancelled);
        Notification::assertSentToTimes($second, TrialRefusedNotification::class, 1);
    }

    /**
     * A cancel that failed leaves the refusal written and unfinished, and the
     * retry finishes it rather than skipping the refused row.
     */
    public function test_a_failed_cancel_is_finished_by_the_retry(): void
    {
        $user = $this->makeUser();
        $earlier = $this->trial($user, $user, 'sub_kept', minutesAgo: 10);
        $later = $this->trial($user, $user, 'sub_cancel_fails', minutesAgo: 5);

        $this->gateway->fingerprints = [
            'sub_kept' => 'fp_kept',
            'sub_cancel_fails' => 'fp_other',
        ];
        $this->gateway->failingCancels = 1;

        try {
            $this->runJob($later);
            $this->fail('The failed cancel was swallowed.');
        } catch (RuntimeException $failure) {
            $this->assertSame('Stripe is down.', $failure->getMessage());
        }

        $later->refresh();
        $this->assertNotNull($later->refused_at);
        $this->assertNull($later->checked_at, 'An unconfirmed cancel was stamped as finished.');

        $this->runJob($later);

        $later->refresh();
        $this->assertNotNull($later->checked_at);
        $this->assertSame(['sub_cancel_fails'], $this->gateway->cancelled);
        $this->assertNull(Subscription::query()->where('stripe_id', 'sub_cancel_fails')->first());
        $this->assertNull($earlier->refresh()->refused_at);
    }

    /**
     * A refusal whose cancel never landed, on a subscription that has since
     * converted to paid, is withdrawn rather than cancelled: the check never
     * cancels a customer, and a refusal left on record would make the webhook
     * skip every later update of a subscription somebody is paying for.
     */
    public function test_an_owed_cancel_on_a_subscription_that_converted_withdraws_the_refusal(): void
    {
        $user = $this->makeUser();
        $this->trial($user, $user, 'sub_kept_paying', minutesAgo: 10);
        $later = $this->trial($user, $user, 'sub_converted', minutesAgo: 5);

        $this->gateway->fingerprints = [
            'sub_kept_paying' => 'fp_one',
            'sub_converted' => 'fp_two',
        ];
        $this->gateway->failingCancels = 1;

        try {
            $this->runJob($later);
            $this->fail('The failed cancel was swallowed.');
        } catch (RuntimeException) {
            // The refusal is written and the cancel is owed.
        }

        Subscription::query()->where('stripe_id', 'sub_converted')->update(['stripe_status' => 'active']);

        $this->runJob($later);

        $later->refresh();
        $this->assertNull($later->refused_at);
        $this->assertNull($later->refusal_reason);
        $this->assertNotNull($later->checked_at);
        $this->assertSame([], $this->gateway->cancelled);
        $this->assertNotNull(Subscription::query()->where('stripe_id', 'sub_converted')->first());
    }

    // -------------------------------------------------------------------------
    // The refusal mail
    // -------------------------------------------------------------------------

    public function test_the_refusal_mail_can_be_switched_off(): void
    {
        config(['magic-starter.billing.trial_refused_notification' => false]);

        $this->refuseASharedCard();

        Notification::assertNothingSent();
    }

    /**
     * A config published before the key existed has no key at all, because the
     * merge is shallow, and the default is to send.
     */
    public function test_the_refusal_mail_defaults_on_when_the_key_is_absent(): void
    {
        $billing = config('magic-starter.billing');
        unset($billing['trial_refused_notification']);
        config(['magic-starter.billing' => $billing]);

        $this->assertFalse(config()->has('magic-starter.billing.trial_refused_notification'));

        $second = $this->refuseASharedCard();

        Notification::assertSentTo($second, TrialRefusedNotification::class);
    }

    /**
     * The mail says what happened in the reader's language, and the two
     * locales really are different text.
     */
    public function test_the_refusal_mail_is_translated(): void
    {
        $user = $this->makeUser();

        $english = (new TrialRefusedNotification)->toMail($user);

        app()->setLocale('tr');
        $turkish = (new TrialRefusedNotification)->toMail($user);
        app()->setLocale('en');

        $this->assertSame(__('magic-starter::billing.trial_refused.subject', [], 'en'), $english->subject);
        $this->assertNotSame($english->subject, $turkish->subject);
        $this->assertNotSame($english->introLines, $turkish->introLines);
        $this->assertCount(3, $english->introLines);
        $this->assertSame(['mail'], (new TrialRefusedNotification)->via($user));
    }

    // -------------------------------------------------------------------------
    // Harness
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{0: bool}>
     */
    public static function jobOrders(): array
    {
        return [
            'earlier job first' => [true],
            'later job first' => [false],
        ];
    }

    /**
     * Two people on one card, checked in order; answers the second person.
     */
    private function refuseASharedCard(): User
    {
        $first = $this->makeUser();
        $second = $this->makeUser();
        $earlier = $this->trial($first, $first, 'sub_mail_a', minutesAgo: 10);
        $later = $this->trial($second, $second, 'sub_mail_b', minutesAgo: 5);

        $this->gateway->fingerprints = [
            'sub_mail_a' => 'fp_mail',
            'sub_mail_b' => 'fp_mail',
        ];

        $this->runInOrder(true, $earlier, $later);

        $this->assertSame('card_reused', $later->refresh()->refusal_reason);

        return $second;
    }

    private function runInOrder(bool $earlierFirst, BillingTrial $earlier, BillingTrial $later): void
    {
        foreach ($earlierFirst ? [$earlier, $later] : [$later, $earlier] as $trial) {
            $this->runJob($trial);
        }
    }

    /**
     * Run the job the way a worker does, with a queue job behind it so
     * `attempts()` and `release()` mean what they mean on a real queue.
     */
    private function runJob(BillingTrial $trial, int $attempts = 1): CheckTrialCard
    {
        $job = (new CheckTrialCard($trial->getKey()))->withFakeQueueInteractions();
        $job->job->attempts = $attempts;

        $this->app->call([$job, 'handle']);

        return $job;
    }

    private function makeUser(): User
    {
        return User::query()->create([
            'name' => 'Trialist',
            'email' => 'trialist-' . Str::random(10) . '@example.test',
            'password' => 'secret',
        ]);
    }

    /**
     * A trial as the webhook leaves it: Cashier's local subscription row and
     * item, plus the `billing_trials` row, unchecked.
     *
     * The local row's `created_at` follows Stripe's order, so the newest
     * `default` row is the later subscription, as it is in production.
     */
    private function trial(
        User $user,
        Model $billable,
        string $subscriptionId,
        int $minutesAgo,
        string $status = 'trialing',
    ): BillingTrial {
        $createdAt = Carbon::now()->subMinutes($minutesAgo);

        $subscription = new Subscription;
        $subscription->forceFill([
            $billable->getForeignKey() => $billable->getKey(),
            'type' => 'default',
            'stripe_id' => $subscriptionId,
            'stripe_status' => $status,
            'stripe_price' => 'price_pro',
            'quantity' => 1,
            'trial_ends_at' => Carbon::now()->addDays(14),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        $subscription->items()->create([
            'stripe_id' => 'si_' . $subscriptionId,
            'stripe_product' => 'prod_pro',
            'stripe_price' => 'price_pro',
            'quantity' => 1,
        ]);

        return BillingTrial::query()->create([
            'user_id' => $user->getKey(),
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'stripe_subscription_id' => $subscriptionId,
            'subscription_created_at' => $createdAt,
        ]);
    }

    private function createSchema(): void
    {
        foreach ([
            'create_users_table.php',
            'add_cashier_customer_columns_to_billable_table.php',
            'create_subscriptions_table.php',
            'create_subscription_items_table.php',
            'create_billing_trials_table.php',
        ] as $filename) {
            $migration = require __DIR__ . '/../../database/migrations/' . $filename;

            $migration->up();
        }
    }
}

/**
 * The person and the subject at once, as on an application billing users.
 *
 * Named `User` because Cashier derives the subscription foreign key from the
 * basename, and `Notifiable` because an adopter's user model carries it.
 */
class User extends ConcreteUser
{
    use Billable;
    use Notifiable;

    protected $table = 'users';
}

/**
 * The gateway, answered from a table and recording every call.
 *
 * `refusedWhenCancelled` reads the row at the moment of the cancel, which is
 * the only way to see that the refusal was written FIRST: read afterwards, a
 * job that cancelled and then wrote would leave the same row behind.
 */
class FakeTrialCardGateway extends TrialCardGateway
{
    /** @var array<string, string|false|null> */
    public array $fingerprints = [];

    /** @var list<string> */
    public array $asked = [];

    /** @var list<string> */
    public array $cancelled = [];

    /** @var array<string, bool> */
    public array $refusedWhenCancelled = [];

    public int $failingCancels = 0;

    public function fingerprintFor(string $subscriptionId): string|false|null
    {
        $this->asked[] = $subscriptionId;

        return $this->fingerprints[$subscriptionId] ?? null;
    }

    public function cancel(string $subscriptionId): void
    {
        $this->refusedWhenCancelled[$subscriptionId] = BillingTrial::query()
            ->where('stripe_subscription_id', $subscriptionId)
            ->whereNotNull('refused_at')
            ->exists();

        if ($this->failingCancels > 0) {
            $this->failingCancels--;

            throw new RuntimeException('Stripe is down.');
        }

        $this->cancelled[] = $subscriptionId;
    }
}
