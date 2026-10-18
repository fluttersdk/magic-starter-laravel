<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Models\BillingTrial;
use FlutterSdk\MagicStarter\Models\ProcessedWebhookEvent;
use FlutterSdk\MagicStarter\Notifications\PaymentFailedNotification;
use FlutterSdk\MagicStarter\Notifications\TrialEndingNotification;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\RoutesNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Cashier\Billable;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription as CashierSubscription;
use Laravel\Cashier\SubscriptionItem as CashierSubscriptionItem;

/**
 * Locks the two lifecycle mails the Stripe webhook sends: the trial-ending notice on
 * `customer.subscription.trial_will_end` and the declined-renewal notice on `invoice.payment_failed`.
 *
 * Every guard has a test that delivers the one payload it exists to refuse, so deleting or loosening any
 * single guard turns exactly one test red:
 *
 *  1. The flags: both mails are off by default, and a handler that ignored its flag would mail every adopter
 *     who also lets Stripe send its own reminder.
 *  2. trial_will_end fires immediately when a trial ends early (status `active`) and Stripe redelivers events,
 *     so the status, the strictly-future `trial_end`, the `default` type and the refused-trial checks each
 *     kill a mutant that mails a trial which is not about to end.
 *  3. The cancelled variant: `cancel_at_period_end` alone, and `cancel_at` alone at the trial end (the
 *     boundary, so a `<` mutant dies) are both a cancellation; a `cancel_at` after the trial end is not.
 *  4. invoice.payment_failed fires per retry and for invoices a person already saw fail inline, so
 *     `attempt_count`, `billing_reason`, `amount_due` and the subscription id each have their own refusal.
 *  5. The recipient: a team's mail reaches its owner and no other member.
 *  6. The transaction: a replayed event id mails nothing, and a mail queued from a delivery that rolls back
 *     never goes out. The second half is asserted on the real `sync` queue WITHOUT a fake, because the fake
 *     records at once and ignores `afterCommit`; under the fake only the flag itself can be asserted.
 *  7. The locale: the recipient's own preference, not the app locale, travels on the notification.
 */
class StripeLifecycleMailTest extends TestCase
{
    /**
     * The webhook signing secret every payload in this file is signed with.
     */
    protected const WEBHOOK_SECRET = 'whsec_test_secret';

    /**
     * The instant every payload's `created` field counts from: 2026-08-22 12:00:00 UTC.
     */
    protected const EVENT_AT = 1787400000;

    /**
     * The Stripe customer id the billable fixture carries.
     */
    protected const STRIPE_ID = 'cus_lifecycle_test';

    /**
     * The Stripe subscription id the seeded `default` subscription and its invoices carry.
     */
    protected const SUBSCRIPTION_ID = 'sub_lifecycle_test';

    /**
     * How many NotificationSending events the real sender raised in this test.
     */
    private int $notificationsSending = 0;

    /**
     * Configure the application before its providers BOOT; see StripeWebhookTest::defineEnvironment().
     *
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        MagicStarter::reset();

        $app['config']->set([
            'database.default' => 'testing',
            'database.connections.testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'app.locale' => 'en',
            'magic-starter.features' => [Features::billing()],
            'magic-starter.billing.billable' => 'user',
            'magic-starter.models.user' => LifecycleMailUser::class,
            'auth.providers.users.model' => LifecycleMailUser::class,
            'magic-starter.billing.products' => [
                'pro_monthly' => [
                    'type' => 'subscription',
                    'tier' => 'pro',
                    'cycle' => 'monthly',
                    'refs' => ['stripe_price' => 'price_pro'],
                ],
            ],
            'magic-starter.billing.tier_order' => ['free', 'pro'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The parent registers its fixture models after boot; everything below resolves from config.
        MagicStarter::reset();

        // Set here, not in defineEnvironment(): Cashier's shallow mergeConfigFrom would drop `tolerance`.
        config(['cashier.webhook.secret' => static::WEBHOOK_SECRET]);

        // The provider's register() ran before defineEnvironment(); re-driven so Cashier resolves the billable.
        (new MagicStarterServiceProvider($this->app))->register();

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        Cashier::useCustomerModel('App\\Models\\User');
        Cashier::useSubscriptionModel(CashierSubscription::class);
        Cashier::useSubscriptionItemModel(CashierSubscriptionItem::class);
        MagicStarter::reset();

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // customer.subscription.trial_will_end
    // -------------------------------------------------------------------------

    public function test_a_trial_about_to_end_mails_the_user_billable_after_commit(): void
    {
        Notification::fake();
        config(['magic-starter.billing.trial_ending_notification' => true]);

        $billable = $this->createBillable();
        $trialEnd = now()->addDays(3)->getTimestamp();

        $this->postSignedWebhook($this->trialWillEndEvent('evt_trial_end', trialEnd: $trialEnd))->assertOk();

        Notification::assertSentTimes(TrialEndingNotification::class, 1);
        Notification::assertSentTo(
            $billable,
            TrialEndingNotification::class,
            fn (TrialEndingNotification $notification): bool => $notification->tier === 'pro'
                && $notification->endsAt->getTimestamp() === $trialEnd
                && $notification->cancelling === false
                && $notification->afterCommit === true
                && $notification->locale === 'en',
        );
    }

    public function test_a_trial_set_to_cancel_at_period_end_gets_the_cancelled_variant(): void
    {
        $this->assertCancelling(true, [
            'cancel_at_period_end' => true,
        ]);
    }

    /**
     * A Billing Portal cancel may set only `cancel_at`, and API basil deprecates `cancel_at_period_end`. The
     * value equals the trial end, which is the boundary a `<` mutant gets wrong.
     */
    public function test_a_cancel_at_on_the_trial_end_gets_the_cancelled_variant(): void
    {
        $trialEnd = now()->addDays(3)->getTimestamp();

        $this->assertCancelling(true, [
            'cancel_at_period_end' => false,
            'cancel_at' => $trialEnd,
        ], $trialEnd);
    }

    public function test_a_cancel_at_after_the_trial_end_charges_at_the_trial_end(): void
    {
        $trialEnd = now()->addDays(3)->getTimestamp();

        $this->assertCancelling(false, [
            'cancel_at_period_end' => false,
            'cancel_at' => $trialEnd + 86400,
        ], $trialEnd);
    }

    /**
     * The mail goes to the person who can act on it: the team's owner, never a member.
     */
    public function test_a_team_billable_mails_its_owner_only(): void
    {
        Notification::fake();
        config(['magic-starter.billing.trial_ending_notification' => true]);

        [$owner, $member] = $this->createTeamBillable();

        $this->postSignedWebhook($this->trialWillEndEvent('evt_trial_end_team'))->assertOk();

        Notification::assertSentTimes(TrialEndingNotification::class, 1);
        Notification::assertSentTo($owner, TrialEndingNotification::class);
        Notification::assertNotSentTo($member, TrialEndingNotification::class);
    }

    public function test_the_trial_ending_mail_is_off_by_default(): void
    {
        Notification::fake();
        $this->createBillable();

        $this->postSignedWebhook($this->trialWillEndEvent('evt_trial_end_off'))->assertOk();

        Notification::assertNothingSent();
    }

    /**
     * Stripe fires trial_will_end immediately when a trial is ended early, and the subscription is then active.
     */
    public function test_a_trial_ended_early_mails_nothing(): void
    {
        Notification::fake();
        config(['magic-starter.billing.trial_ending_notification' => true]);
        $this->createBillable();

        $this->postSignedWebhook($this->trialWillEndEvent('evt_trial_end_early', status: 'active'))->assertOk();

        Notification::assertNothingSent();
    }

    public function test_a_trial_end_in_the_past_or_now_mails_nothing(): void
    {
        Notification::fake();
        config(['magic-starter.billing.trial_ending_notification' => true]);
        $this->createBillable();
        $this->freezeTime();

        $this->postSignedWebhook($this->trialWillEndEvent(
            'evt_trial_end_past',
            trialEnd: now()->subMinute()->getTimestamp(),
        ))->assertOk();
        $this->postSignedWebhook($this->trialWillEndEvent(
            'evt_trial_end_now',
            trialEnd: now()->getTimestamp(),
        ))->assertOk();

        Notification::assertNothingSent();
    }

    public function test_a_trial_of_another_subscription_type_mails_nothing(): void
    {
        Notification::fake();
        config(['magic-starter.billing.trial_ending_notification' => true]);
        $this->createBillable();

        $this->postSignedWebhook($this->trialWillEndEvent(
            'evt_trial_end_seats',
            subscriptionType: 'seats',
        ))->assertOk();

        Notification::assertNothingSent();
    }

    /**
     * A trial the card check refused and finished is over for this package; it is not about to end.
     */
    public function test_a_refused_trial_mails_nothing(): void
    {
        Notification::fake();
        config(['magic-starter.billing.trial_ending_notification' => true]);
        $billable = $this->createBillable();

        BillingTrial::query()->create([
            'user_id' => $billable->getKey(),
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'stripe_subscription_id' => static::SUBSCRIPTION_ID,
            'subscription_created_at' => now()->subDays(11),
            'checked_at' => now()->subDays(11),
            'refused_at' => now()->subDays(11),
        ]);

        $this->postSignedWebhook($this->trialWillEndEvent(
            'evt_trial_end_refused',
            metadata: [BillingTrial::USER_METADATA_KEY => (string) $billable->getKey()],
        ))->assertOk();

        Notification::assertNothingSent();
    }

    /**
     * A refusal whose cancel is still owed (no `checked_at`) is not converting either. A short trial can meet
     * trial_will_end while the card check runs, and the person then gets TrialRefusedNotification, not this.
     */
    public function test_a_refused_trial_whose_cancel_is_still_owed_mails_nothing(): void
    {
        Notification::fake();
        config(['magic-starter.billing.trial_ending_notification' => true]);
        $billable = $this->createBillable();

        BillingTrial::query()->create([
            'user_id' => $billable->getKey(),
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'stripe_subscription_id' => static::SUBSCRIPTION_ID,
            'subscription_created_at' => now()->subDays(1),
            'refused_at' => now()->subMinutes(5),
        ]);

        $this->postSignedWebhook($this->trialWillEndEvent(
            'evt_trial_end_refusal_owed',
            metadata: [BillingTrial::USER_METADATA_KEY => (string) $billable->getKey()],
        ))->assertOk();

        Notification::assertNothingSent();
    }

    public function test_an_unknown_customer_mails_nothing_and_answers_200(): void
    {
        Notification::fake();
        config(['magic-starter.billing.trial_ending_notification' => true]);
        $this->createBillable();

        $this->postSignedWebhook($this->trialWillEndEvent(
            'evt_trial_end_stranger',
            customer: 'cus_nobody',
        ))->assertOk();

        Notification::assertNothingSent();
    }

    public function test_an_unmapped_price_mails_nothing_and_answers_200(): void
    {
        Notification::fake();
        config(['magic-starter.billing.trial_ending_notification' => true]);
        $this->createBillable();

        $this->postSignedWebhook($this->trialWillEndEvent(
            'evt_trial_end_unmapped',
            priceId: 'price_unknown',
        ))->assertOk();

        Notification::assertNothingSent();
    }

    public function test_a_replayed_trial_will_end_mails_once(): void
    {
        Notification::fake();
        config(['magic-starter.billing.trial_ending_notification' => true]);
        $this->createBillable();

        $event = $this->trialWillEndEvent('evt_trial_end_replay');

        $this->postSignedWebhook($event)->assertOk();
        $this->postSignedWebhook($event)->assertOk();

        Notification::assertSentTimes(TrialEndingNotification::class, 1);
    }

    /**
     * Built inside a webhook request, the mail has no person's request locale to borrow: the recipient's own
     * preference is what it is pinned to.
     */
    public function test_the_mail_is_pinned_to_the_recipients_preferred_locale(): void
    {
        Notification::fake();
        config([
            'magic-starter.billing.trial_ending_notification' => true,
            'magic-starter.models.user' => LifecycleMailLocaleUser::class,
        ]);
        MagicStarter::reset();
        (new MagicStarterServiceProvider($this->app))->register();

        $billable = $this->createBillable(LifecycleMailLocaleUser::class);

        $this->postSignedWebhook($this->trialWillEndEvent('evt_trial_end_tr'))->assertOk();

        $this->assertSame('en', app()->getLocale());
        Notification::assertSentTo(
            $billable,
            TrialEndingNotification::class,
            fn (TrialEndingNotification $notification): bool => $notification->locale === 'tr',
        );
    }

    // -------------------------------------------------------------------------
    // invoice.payment_failed
    // -------------------------------------------------------------------------

    public function test_a_first_failed_renewal_mails_the_billable_after_commit(): void
    {
        Notification::fake();
        config(['magic-starter.billing.payment_failed_notification' => true]);
        $billable = $this->createBillable();
        $this->seedActiveSubscription();

        $this->postSignedWebhook($this->paymentFailedEvent('evt_payment_failed'))->assertOk();

        Notification::assertSentTimes(PaymentFailedNotification::class, 1);
        Notification::assertSentTo(
            $billable,
            PaymentFailedNotification::class,
            fn (PaymentFailedNotification $notification): bool => $notification->tier === 'pro'
                && $notification->afterCommit === true
                && $notification->locale === 'en',
        );
    }

    /**
     * Stripe fires invoice.payment_failed on every retry; only the first one is news.
     */
    public function test_a_retried_payment_failure_mails_nothing(): void
    {
        Notification::fake();
        config(['magic-starter.billing.payment_failed_notification' => true]);
        $this->createBillable();
        $this->seedActiveSubscription();

        $this->postSignedWebhook($this->paymentFailedEvent('evt_payment_failed_retry', attemptCount: 2))
            ->assertOk();

        Notification::assertNothingSent();
    }

    /**
     * A declined plan swap is reported inline to the person who asked for it, and a `subscription_create`
     * invoice is paid inside Checkout: neither is a renewal.
     */
    public function test_a_failed_payment_that_is_not_a_renewal_mails_nothing(): void
    {
        Notification::fake();
        config(['magic-starter.billing.payment_failed_notification' => true]);
        $this->createBillable();
        $this->seedActiveSubscription();

        $this->postSignedWebhook($this->paymentFailedEvent(
            'evt_payment_failed_create',
            billingReason: 'subscription_create',
        ))->assertOk();
        $this->postSignedWebhook($this->paymentFailedEvent(
            'evt_payment_failed_update',
            billingReason: 'subscription_update',
        ))->assertOk();

        Notification::assertNothingSent();
    }

    public function test_a_failed_zero_amount_invoice_mails_nothing(): void
    {
        Notification::fake();
        config(['magic-starter.billing.payment_failed_notification' => true]);
        $this->createBillable();
        $this->seedActiveSubscription();

        $this->postSignedWebhook($this->paymentFailedEvent('evt_payment_failed_zero', amountDue: 0))->assertOk();

        Notification::assertNothingSent();
    }

    /**
     * An invoice for any subscription but the billable's `default` one is not the plan this mail talks about.
     */
    public function test_a_failed_invoice_for_another_subscription_mails_nothing(): void
    {
        Notification::fake();
        config(['magic-starter.billing.payment_failed_notification' => true]);
        $this->createBillable();
        $this->seedActiveSubscription();

        $this->postSignedWebhook($this->paymentFailedEvent(
            'evt_payment_failed_other',
            subscriptionId: 'sub_someone_else',
        ))->assertOk();

        Notification::assertNothingSent();
    }

    public function test_the_payment_failed_mail_is_off_by_default(): void
    {
        Notification::fake();
        $this->createBillable();
        $this->seedActiveSubscription();

        $this->postSignedWebhook($this->paymentFailedEvent('evt_payment_failed_off'))->assertOk();

        Notification::assertNothingSent();
    }

    public function test_a_replayed_payment_failure_mails_once(): void
    {
        Notification::fake();
        config(['magic-starter.billing.payment_failed_notification' => true]);
        $this->createBillable();
        $this->seedActiveSubscription();

        $event = $this->paymentFailedEvent('evt_payment_failed_replay');

        $this->postSignedWebhook($event)->assertOk();
        $this->postSignedWebhook($event)->assertOk();

        Notification::assertSentTimes(PaymentFailedNotification::class, 1);
    }

    /**
     * Stripe counts only automatic retries in `attempt_count`: a payer who retries the same invoice by hand fails
     * again at attempt 1, under a new event id (observed on a sandbox test clock). The event-id dedup cannot see
     * that, so the mail is claimed per invoice; the next renewal's invoice is news again.
     */
    public function test_a_manual_retry_of_the_same_invoice_mails_once_and_the_next_invoice_mails_again(): void
    {
        Notification::fake();
        config(['magic-starter.billing.payment_failed_notification' => true]);
        $this->createBillable();
        $this->seedActiveSubscription();

        $this->postSignedWebhook($this->paymentFailedEvent('evt_payment_failed_auto'))->assertOk();
        $this->postSignedWebhook($this->paymentFailedEvent('evt_payment_failed_manual'))->assertOk();

        Notification::assertSentTimes(PaymentFailedNotification::class, 1);

        $this->postSignedWebhook($this->paymentFailedEvent('evt_payment_failed_next', invoiceId: 'in_next_cycle'))
            ->assertOk();

        Notification::assertSentTimes(PaymentFailedNotification::class, 2);
    }

    // -------------------------------------------------------------------------
    // The real deferral, on the sync queue and without a fake
    // -------------------------------------------------------------------------

    public function test_a_committed_delivery_sends_the_mail_on_the_sync_queue(): void
    {
        config([
            'queue.default' => 'sync',
            'magic-starter.billing.trial_ending_notification' => true,
        ]);
        $this->countNotificationSending();
        $this->createBillable();

        $this->postSignedWebhook($this->trialWillEndEvent('evt_trial_end_sync'))->assertOk();

        $this->assertSame(1, $this->notificationsSending);
    }

    /**
     * Posted inside an outer transaction, the webhook's own transaction is a savepoint; rolling the outer one
     * back is the delivery Stripe will retry, and it must not have sent the mail it would send again.
     */
    public function test_a_rolled_back_delivery_sends_nothing(): void
    {
        config([
            'queue.default' => 'sync',
            'magic-starter.billing.trial_ending_notification' => true,
        ]);
        $this->countNotificationSending();
        $this->createBillable();

        DB::beginTransaction();

        $this->postSignedWebhook($this->trialWillEndEvent('evt_trial_end_rollback'))->assertOk();

        $this->assertSame(1, ProcessedWebhookEvent::query()->where('event_id', 'evt_trial_end_rollback')->count());
        $this->assertSame(0, $this->notificationsSending);

        DB::rollBack();

        $this->assertSame(0, ProcessedWebhookEvent::query()->where('event_id', 'evt_trial_end_rollback')->count());
        $this->assertSame(0, $this->notificationsSending);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Deliver a trial_will_end with the given cancellation fields and assert the variant it produced.
     *
     * @param  array<string, mixed>  $fields
     */
    private function assertCancelling(bool $cancelling, array $fields, ?int $trialEnd = null): void
    {
        Notification::fake();
        config(['magic-starter.billing.trial_ending_notification' => true]);
        $billable = $this->createBillable();

        $this->postSignedWebhook($this->trialWillEndEvent(
            'evt_trial_end_' . Str::random(6),
            trialEnd: $trialEnd,
            fields: $fields,
        ))->assertOk();

        Notification::assertSentTimes(TrialEndingNotification::class, 1);
        Notification::assertSentTo(
            $billable,
            TrialEndingNotification::class,
            fn (TrialEndingNotification $notification): bool => $notification->cancelling === $cancelling,
        );
    }

    /**
     * Count into {@see self::$notificationsSending} the NotificationSending events the real sender raises, one
     * per channel per notifiable.
     */
    private function countNotificationSending(): void
    {
        Event::listen(NotificationSending::class, function (): void {
            $this->notificationsSending++;
        });
    }

    /**
     * Build the billing schema the way a consumer's `migrate` does; see StripeWebhookTest::createSchema().
     */
    private function createSchema(): void
    {
        $this->runMigration('create_users_table.php');
        $this->runMigration('add_cashier_customer_columns_to_billable_table.php');
        $this->runMigration('add_entitlement_provenance_to_billable_table.php');
        $this->runMigration('add_billing_currency_to_billable_table.php');
        $this->runMigration('create_subscriptions_table.php');
        $this->runMigration('create_subscription_items_table.php');
        $this->runMigration('create_processed_webhook_events_table.php');
        $this->runMigration('create_billing_trials_table.php');
        $this->runMigration('create_billing_events_table.php');
    }

    private function runMigration(string $filename): void
    {
        $migration = require __DIR__ . '/../../../database/migrations/' . $filename;

        $migration->up();
    }

    /**
     * The user billable acting as the Stripe customer.
     *
     * @param  class-string<LifecycleMailUser>  $model
     */
    private function createBillable(string $model = LifecycleMailUser::class): LifecycleMailUser
    {
        return $model::query()->create([
            'name' => 'Payer',
            'email' => 'payer@example.test',
            'password' => 'secret',
            'stripe_id' => static::STRIPE_ID,
        ]);
    }

    /**
     * Switch the billable subject to a team carrying the Stripe customer, owned by one user with a second member.
     *
     * The provider is re-registered so Cashier resolves the team, and the customer-columns migration runs again
     * because it adds `stripe_id` to whichever table the billable subject names.
     *
     * @return array{0: LifecycleMailUser, 1: LifecycleMailUser}
     */
    private function createTeamBillable(): array
    {
        config([
            'magic-starter.features' => [Features::teams(), Features::billing()],
            'magic-starter.billing.billable' => 'team',
            'magic-starter.models.team' => ConcreteTeam::class,
        ]);
        MagicStarter::reset();
        (new MagicStarterServiceProvider($this->app))->register();

        $this->runMigration('create_teams_table.php');
        $this->runMigration('create_team_user_table.php');
        $this->runMigration('add_cashier_customer_columns_to_billable_table.php');

        $owner = LifecycleMailUser::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => 'secret',
        ]);
        $member = LifecycleMailUser::query()->create([
            'name' => 'Member',
            'email' => 'member@example.test',
            'password' => 'secret',
        ]);

        $team = (new ConcreteTeam)->forceFill([
            'user_id' => $owner->getKey(),
            'name' => 'Billing team',
            'personal_team' => false,
            'stripe_id' => static::STRIPE_ID,
        ]);
        $team->save();
        $team->users()->attach($member->getKey(), [
            'role' => 'member',
        ]);

        return [
            $owner,
            $member,
        ];
    }

    /**
     * Sync a local `default` Cashier subscription row on the `pro` price through a real delivery.
     */
    private function seedActiveSubscription(): void
    {
        $this->postSignedWebhook(
            $this->subscriptionEvent(
                'evt_seed_' . Str::random(6),
                'customer.subscription.created',
                'price_pro',
                'active',
            ),
        )->assertOk();
    }

    /**
     * Build a customer.subscription.trial_will_end payload; `trial_end` defaults to three days from now.
     *
     * @param  array<string, string>  $metadata
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function trialWillEndEvent(
        string $eventId,
        ?int $trialEnd = null,
        string $status = 'trialing',
        string $priceId = 'price_pro',
        string $customer = self::STRIPE_ID,
        string $subscriptionType = 'default',
        array $metadata = [],
        array $fields = [],
    ): array {
        return $this->subscriptionEvent(
            $eventId,
            'customer.subscription.trial_will_end',
            $priceId,
            $status,
            customer: $customer,
            subscriptionType: $subscriptionType,
            metadata: $metadata,
            fields: [
                'trial_end' => $trialEnd ?? now()->addDays(3)->getTimestamp(),
                ...$fields,
            ],
        );
    }

    /**
     * Build a Stripe subscription event payload; see StripeWebhookTest::subscriptionEvent().
     *
     * @param  array<string, string>  $metadata
     * @param  array<string, mixed>  $fields  Merged into the subscription object.
     * @return array<string, mixed>
     */
    private function subscriptionEvent(
        string $eventId,
        string $type,
        string $priceId,
        string $status,
        int $created = self::EVENT_AT,
        string $customer = self::STRIPE_ID,
        string $subscriptionType = 'default',
        array $metadata = [],
        array $fields = [],
    ): array {
        return [
            'id' => $eventId,
            'type' => $type,
            'created' => $created,
            'data' => [
                'object' => [
                    'id' => static::SUBSCRIPTION_ID,
                    'customer' => $customer,
                    'status' => $status,
                    'metadata' => [
                        'type' => $subscriptionType,
                        ...$metadata,
                    ],
                    'items' => [
                        'data' => [
                            [
                                'id' => 'si_' . static::SUBSCRIPTION_ID,
                                'quantity' => 1,
                                'price' => [
                                    'id' => $priceId,
                                    'product' => 'prod_lifecycle_test',
                                ],
                            ],
                        ],
                    ],
                    ...$fields,
                ],
            ],
        ];
    }

    /**
     * Build an invoice.payment_failed payload for a renewal of the seeded subscription.
     *
     * The subscription id sits at `parent.subscription_details.subscription`, where API basil and later carry it.
     *
     * @return array<string, mixed>
     */
    private function paymentFailedEvent(
        string $eventId,
        int $attemptCount = 1,
        string $billingReason = 'subscription_cycle',
        int $amountDue = 1900,
        string $subscriptionId = self::SUBSCRIPTION_ID,
        string $invoiceId = 'in_lifecycle_test',
    ): array {
        return $this->invoiceEvent($eventId, 'invoice.payment_failed', self::EVENT_AT + 60, [
            'id' => $invoiceId,
            'attempt_count' => $attemptCount,
            'billing_reason' => $billingReason,
            'amount_due' => $amountDue,
            'parent' => [
                'subscription_details' => [
                    'subscription' => $subscriptionId,
                ],
            ],
        ]);
    }

    /**
     * Build a Stripe invoice event payload of the given type.
     *
     * @param  array<string, mixed>  $fields  Merged into the invoice object.
     * @return array<string, mixed>
     */
    private function invoiceEvent(
        string $eventId,
        string $type,
        int $created = self::EVENT_AT,
        array $fields = [],
    ): array {
        return [
            'id' => $eventId,
            'type' => $type,
            'created' => $created,
            'data' => [
                'object' => [
                    'id' => 'in_lifecycle_test',
                    'customer' => static::STRIPE_ID,
                    ...$fields,
                ],
            ],
        ];
    }

    /**
     * POST a Stripe-signed webhook payload to the package's endpoint.
     *
     * @param  array<string, mixed>  $event
     */
    private function postSignedWebhook(array $event): TestResponse
    {
        $payload = (string) json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", static::WEBHOOK_SECRET);

        return $this->call(
            'POST',
            'stripe/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            ],
            $payload,
        );
    }
}

/**
 * The billable user, routable by mail so the real sender can deliver to it.
 *
 * Its own class rather than StripeWebhookTest's `User`, which lives in that file and is not autoloadable when
 * this file runs alone. Cashier derives the subscriptions foreign key from the basename, and the package's
 * subscriptions migration derives it the same way, so the two agree by construction.
 */
class LifecycleMailUser extends ConcreteUser
{
    use Billable;
    use RoutesNotifications;

    protected $table = 'users';
}

/**
 * A recipient who prefers Turkish while the application speaks English.
 */
class LifecycleMailLocaleUser extends LifecycleMailUser implements HasLocalePreference
{
    public function preferredLocale(): string
    {
        return 'tr';
    }
}
