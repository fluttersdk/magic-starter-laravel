<?php

namespace FlutterSdk\MagicStarter\Tests\Jobs;

use Closure;
use FlutterSdk\MagicStarter\Console\ReconcileBillingEntitlements;
use FlutterSdk\MagicStarter\Contracts\WritesEntitlement;
use FlutterSdk\MagicStarter\Enums\TrialRefusalReason;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Jobs\CheckTrialCard;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\BillingTrial;
use FlutterSdk\MagicStarter\Models\Subscription;
use FlutterSdk\MagicStarter\Models\SubscriptionItem;
use FlutterSdk\MagicStarter\Notifications\TrialRefusedNotification;
use FlutterSdk\MagicStarter\Support\RevenueCatClient;
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

    /**
     * On the `sync` queue `attempts()` is always 1, so the attempt count alone
     * never gives up and the sweep re-dispatches the row forever. A row older
     * than six hours is given up on whatever the attempt, keeping the trial.
     *
     * The five-hour row is the control: it is still asked again.
     */
    public function test_a_row_older_than_six_hours_gives_up_on_its_first_attempt(): void
    {
        Log::spy();

        $user = $this->makeUser();
        $young = $this->trial($user, $user, 'sub_five_hours', minutesAgo: 5 * 60);
        $old = $this->trial($user, $user, 'sub_seven_hours', minutesAgo: 7 * 60);

        $this->runJob($young)->assertReleased(60);
        $this->assertNull($young->refresh()->checked_at);

        $this->runJob($old)->assertNotReleased();

        $old->refresh();
        $this->assertNotNull($old->checked_at);
        $this->assertNull($old->refused_at);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => ($context['reason'] ?? null)
                === 'trial_card_never_attached' && ($context['billing_trial_id'] ?? null) === $old->getKey());
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
        $this->assertSame(TrialRefusalReason::DUPLICATE, $later->refusal_reason);
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
        $this->assertSame(TrialRefusalReason::CARD_REUSED, $later->refusal_reason);
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
        $this->assertSame(TrialRefusalReason::CARD_REUSED, $later->refresh()->refusal_reason);
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
     * An owed refusal is cancelled only on Stripe's LIVE word, never on the
     * local row's.
     *
     * The local row here still says `trialing`, which is exactly what it would
     * say had a webhook been missed, while Stripe reports the subscription
     * paying. The refusal is withdrawn rather than the customer cancelled, and
     * said loudly, because it means a cancel failed for longer than the trial.
     * Reading the local row would cancel a paying subscription.
     */
    public function test_an_owed_refusal_stripe_reports_paying_is_withdrawn_whatever_the_local_row_says(): void
    {
        Log::spy();

        $user = $this->makeUser();
        $this->trial($user, $user, 'sub_kept_paying', minutesAgo: 10);
        $later = $this->trial($user, $user, 'sub_converted', minutesAgo: 5);

        $this->gateway->fingerprints = [
            'sub_kept_paying' => 'fp_one',
            'sub_converted' => 'fp_two',
        ];
        $this->owe($later);

        $this->gateway->statuses['sub_converted'] = 'active';

        $this->runJob($later);

        $later->refresh();
        $this->assertNull($later->refused_at);
        $this->assertNull($later->refusal_reason);
        $this->assertNotNull($later->checked_at);
        $this->assertSame([], $this->gateway->cancelled);
        $this->assertSame(['sub_converted'], $this->gateway->statusAsked);
        $this->assertSame(
            'trialing',
            Subscription::query()->where('stripe_id', 'sub_converted')->value('stripe_status'),
            'The local row was meant to be the stale one.',
        );

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context): bool => ($context['reason'] ?? null)
                === 'refused_trial_converted' && ($context['stripe_status'] ?? null) === 'active');
    }

    /**
     * A subscription Stripe has already ended needs no cancel: the refusal is
     * stamped finished as it stands, and nothing is sent to Stripe but the read.
     */
    #[DataProvider('endedStatuses')]
    public function test_an_owed_refusal_stripe_already_ended_is_finished_without_a_cancel(string $status): void
    {
        $user = $this->makeUser();
        $this->trial($user, $user, 'sub_first', minutesAgo: 10);
        $later = $this->trial($user, $user, 'sub_ended', minutesAgo: 5);

        $this->gateway->fingerprints = [
            'sub_first' => 'fp_one',
            'sub_ended' => 'fp_two',
        ];
        $this->owe($later);

        $this->gateway->statuses['sub_ended'] = $status;

        $this->runJob($later);

        $later->refresh();
        $this->assertNotNull($later->refused_at);
        $this->assertSame(TrialRefusalReason::DUPLICATE, $later->refusal_reason);
        $this->assertNotNull($later->checked_at);
        $this->assertSame([], $this->gateway->cancelled);
        $this->assertNull(Subscription::query()->where('stripe_id', 'sub_ended')->first());
    }

    /**
     * A NEW refusal is gated the same way: the walk refuses on the local row,
     * and the cancel still waits for Stripe to say `trialing`. A subscription
     * whose conversion the local row has not caught up with is kept.
     */
    public function test_a_fresh_refusal_is_not_cancelled_while_stripe_says_it_is_paying(): void
    {
        $user = $this->makeUser();
        $this->trial($user, $user, 'sub_first_trial', minutesAgo: 10);
        $later = $this->trial($user, $user, 'sub_paid_in_stripe', minutesAgo: 5);

        $this->gateway->fingerprints = [
            'sub_first_trial' => 'fp_one',
            'sub_paid_in_stripe' => 'fp_two',
        ];
        $this->gateway->statuses['sub_paid_in_stripe'] = 'past_due';

        $this->runJob($later);

        $later->refresh();
        $this->assertNull($later->refused_at);
        $this->assertNotNull($later->checked_at);
        $this->assertSame([], $this->gateway->cancelled);
        $this->assertNotNull(Subscription::query()->where('stripe_id', 'sub_paid_in_stripe')->first());
    }

    /**
     * A retry after a lock timeout already holds the fingerprint, so it goes
     * straight to the decision rather than paying for the same Stripe read.
     */
    public function test_a_stored_fingerprint_is_not_read_again(): void
    {
        $first = $this->makeUser();
        $second = $this->makeUser();
        $this->trial($first, $first, 'sub_read_a', minutesAgo: 10)
            ->forceFill(['card_fingerprint' => 'fp_stored', 'checked_at' => Carbon::now()])
            ->save();
        $later = $this->trial($second, $second, 'sub_read_b', minutesAgo: 5);
        $later->forceFill(['card_fingerprint' => 'fp_stored'])->save();

        $this->runJob($later);

        $this->assertSame([], $this->gateway->asked);
        $this->assertSame(TrialRefusalReason::CARD_REUSED, $later->refresh()->refusal_reason);
        $this->assertSame(['sub_read_b'], $this->gateway->cancelled);
    }

    /**
     * Another job can decide a row while this one is reading its card, which
     * happens outside the lock. The decision is re-read under the lock and
     * left alone: a second walk would refuse a trial the first run kept.
     */
    public function test_a_row_decided_while_its_card_was_read_is_left_alone(): void
    {
        $first = $this->makeUser();
        $second = $this->makeUser();
        $this->trial($first, $first, 'sub_race_a', minutesAgo: 10)
            ->forceFill(['card_fingerprint' => 'fp_race', 'checked_at' => Carbon::now()])
            ->save();
        $later = $this->trial($second, $second, 'sub_race_b', minutesAgo: 5);

        $this->gateway->fingerprints['sub_race_b'] = 'fp_race';
        $this->gateway->whileReading = function (string $subscriptionId): void {
            BillingTrial::query()
                ->where('stripe_subscription_id', $subscriptionId)
                ->update(['checked_at' => Carbon::now()]);
        };

        $this->runJob($later);

        $later->refresh();
        $this->assertNull($later->refused_at);
        $this->assertSame('fp_race', $later->card_fingerprint);
        $this->assertSame([], $this->gateway->statusAsked);
        $this->assertSame([], $this->gateway->cancelled);
        Notification::assertNothingSent();
    }

    /**
     * Two later duplicates on one subject both lose their local rows, and the
     * subject is handed back to the survivor once, not once per duplicate.
     */
    public function test_two_duplicates_on_one_subject_reproject_it_once(): void
    {
        $revenueCat = $this->app->make(RevenueCatClient::class);
        $writer = $this->app->make(WritesEntitlement::class);

        $reconciler = new class($revenueCat, $writer) extends ReconcileBillingEntitlements
        {
            /** @var list<string> */
            public array $reconciled = [];

            public function reconcileStripeSubject(Model $billable): void
            {
                $this->reconciled[] = (string) $billable->getKey();

                parent::reconcileStripeSubject($billable);
            }
        };
        $this->app->instance(ReconcileBillingEntitlements::class, $reconciler);

        $user = $this->makeUser();
        $survivor = $this->trial($user, $user, 'sub_twice_a', minutesAgo: 10);
        $this->trial($user, $user, 'sub_twice_b', minutesAgo: 5);
        $this->trial($user, $user, 'sub_twice_c', minutesAgo: 3);

        $this->gateway->fingerprints = [
            'sub_twice_a' => 'fp_twice_a',
            'sub_twice_b' => 'fp_twice_b',
            'sub_twice_c' => 'fp_twice_c',
        ];

        $this->runJob($survivor);

        $this->assertSame(['sub_twice_b', 'sub_twice_c'], $this->gateway->cancelled);
        $this->assertSame([(string) $user->getKey()], $reconciler->reconciled);
        $this->assertSame('sub_twice_a', $user->fresh()?->subscription('default')?->stripe_id);
    }

    /**
     * A card-reused refusal whose person has since been deleted has nobody to
     * mail, and is still refused and cancelled.
     */
    public function test_a_card_reused_refusal_without_a_person_mails_nobody(): void
    {
        $first = $this->makeUser();
        $second = $this->makeUser();
        $earlier = $this->trial($first, $first, 'sub_orphan_a', minutesAgo: 10);
        $later = $this->trial($second, $second, 'sub_orphan_b', minutesAgo: 5);
        $later->forceFill(['user_id' => null])->save();

        $this->gateway->fingerprints = [
            'sub_orphan_a' => 'fp_orphan',
            'sub_orphan_b' => 'fp_orphan',
        ];

        $this->runInOrder(true, $earlier, $later);

        $this->assertSame(TrialRefusalReason::CARD_REUSED, $later->refresh()->refusal_reason);
        $this->assertSame(['sub_orphan_b'], $this->gateway->cancelled);
        Notification::assertNothingSent();
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

        $this->assertSame(TrialRefusalReason::CARD_REUSED, $later->refresh()->refusal_reason);

        return $second;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function endedStatuses(): array
    {
        return [
            'canceled' => ['canceled'],
            'incomplete_expired' => ['incomplete_expired'],
        ];
    }

    /**
     * Leave [$later] refused with its cancel owed: the first cancel throws, as
     * a Stripe outage on the job's last attempt would.
     */
    private function owe(BillingTrial $later): void
    {
        $this->gateway->failingCancels = 1;

        try {
            $this->runJob($later);
            $this->fail('The failed cancel was swallowed.');
        } catch (RuntimeException $failure) {
            $this->assertSame('Stripe is down.', $failure->getMessage());
        }

        $later->refresh();
        $this->assertNotNull($later->refused_at);
        $this->assertNull($later->checked_at);

        $this->gateway->statusAsked = [];
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

        $trial = new BillingTrial;
        $trial->forceFill([
            'user_id' => $user->getKey(),
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'stripe_subscription_id' => $subscriptionId,
            'subscription_created_at' => $createdAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        return $trial;
    }

    private function createSchema(): void
    {
        foreach ([
            'create_users_table.php',
            'add_cashier_customer_columns_to_billable_table.php',
            'add_entitlement_provenance_to_billable_table.php',
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

    /**
     * Stripe's live status per subscription; an unlisted one is `trialing`.
     *
     * @var array<string, string>
     */
    public array $statuses = [];

    /** @var list<string> */
    public array $statusAsked = [];

    /**
     * Run while a card is being read, which is where a concurrent job can
     * decide the row out from under this one.
     *
     * @var (Closure(string): void)|null
     */
    public ?Closure $whileReading = null;

    public function fingerprintFor(string $subscriptionId): string|false|null
    {
        $this->asked[] = $subscriptionId;

        if ($this->whileReading !== null) {
            ($this->whileReading)($subscriptionId);
        }

        return $this->fingerprints[$subscriptionId] ?? null;
    }

    public function status(string $subscriptionId): string
    {
        $this->statusAsked[] = $subscriptionId;

        return $this->statuses[$subscriptionId] ?? 'trialing';
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
