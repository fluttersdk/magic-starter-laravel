<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use FlutterSdk\MagicStarter\Enums\BillingEventType;
use FlutterSdk\MagicStarter\Enums\BillingProvider;
use FlutterSdk\MagicStarter\Enums\BillingSource;
use FlutterSdk\MagicStarter\Events\Billing\BillingOutcome;
use FlutterSdk\MagicStarter\Events\Billing\CheckoutStarted;
use FlutterSdk\MagicStarter\Events\Billing\EntitlementApplied;
use FlutterSdk\MagicStarter\Events\Billing\EntitlementSynced;
use FlutterSdk\MagicStarter\Events\Billing\GrantAdded;
use FlutterSdk\MagicStarter\Events\Billing\GrantExpired;
use FlutterSdk\MagicStarter\Events\Billing\GrantRevoked;
use FlutterSdk\MagicStarter\Events\Billing\InvoiceRefunded;
use FlutterSdk\MagicStarter\Events\Billing\SubscriptionResumed;
use FlutterSdk\MagicStarter\Events\Billing\TrialEnded;
use FlutterSdk\MagicStarter\Events\Billing\TrialExtended;
use FlutterSdk\MagicStarter\Models\BillingEvent;
use FlutterSdk\MagicStarter\Support\BillingEventRecorder;
use FlutterSdk\MagicStarter\Support\MigrationHelper;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use ReflectionProperty;
use RuntimeException;

/**
 * Locks the recorder's promise to billing: a row and an event for every
 * outcome, and never a failure that reaches the caller's transaction.
 *
 * No `RefreshDatabase` here on purpose. Each test gets a fresh in-memory
 * database, so "outside a transaction" really is level zero and a caller's
 * `DB::transaction()` is the real outermost one, which is what the savepoint
 * and after-commit assertions are about.
 *
 * SQLite does not poison a transaction after a failed statement the way
 * PostgreSQL does (25P02), so a surviving outer commit on SQLite proves the
 * catch, not the savepoint. The transaction-level test is the structural proof
 * that the insert runs one level below its caller, which is a SAVEPOINT on
 * every driver that has them.
 */
class BillingEventRecorderTest extends TestCase
{
    /**
     * Every outcome event a listener on {@see BillingOutcome} received.
     *
     * @var list<BillingOutcome>
     */
    private array $dispatched = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The missing-table warning is once per PROCESS, so a test that wants
        // to see it has to start from a process that has not warned yet.
        (new ReflectionProperty(BillingEventRecorder::class, 'warnedMissingTable'))->setValue(null, false);

        $this->migrate('create_users_table.php');

        Event::listen(BillingOutcome::class, function (BillingOutcome $event): void {
            $this->dispatched[] = $event;
        });
    }

    public function test_the_recorder_is_a_container_singleton(): void
    {
        $this->assertSame(
            $this->app->make(BillingEventRecorder::class),
            $this->app->make(BillingEventRecorder::class),
        );
    }

    public function test_outside_a_transaction_the_row_is_written_and_the_event_dispatched_once(): void
    {
        $this->migrate('create_billing_events_table.php');
        $user = $this->makeUser();

        $event = $this->recorder()->record(
            BillingEventType::ENTITLEMENT_APPLIED,
            BillingSource::WEBHOOK,
            $user,
            provider: BillingProvider::STRIPE,
            reason: 'plan_changed',
            externalId: 'evt_123',
            properties: ['plan_status' => 'active'],
            actor: $user,
        );

        $this->assertTrue($event->exists);
        $this->assertSame(1, BillingEvent::query()->count());

        $fresh = BillingEvent::query()->findOrFail($event->getKey());
        $this->assertSame(BillingEventType::ENTITLEMENT_APPLIED, $fresh->type);
        $this->assertSame(BillingSource::WEBHOOK, $fresh->source);
        $this->assertSame(BillingProvider::STRIPE, $fresh->provider);
        $this->assertSame($user->getMorphClass(), $fresh->billable_type);
        $this->assertSame((string) $user->getKey(), $fresh->billable_id);
        $this->assertSame((string) $user->getKey(), (string) $fresh->actor_user_id);
        $this->assertSame('plan_changed', $fresh->reason);
        $this->assertSame('evt_123', $fresh->external_id);
        $this->assertSame(['plan_status' => 'active'], $fresh->properties);
        $this->assertNotNull($fresh->created_at);

        $this->assertCount(1, $this->dispatched);
        $this->assertInstanceOf(EntitlementApplied::class, $this->dispatched[0]);
        $this->assertSame($event, $this->dispatched[0]->record());
    }

    public function test_a_refusal_without_a_billable_records_null_billable_columns(): void
    {
        $this->migrate('create_billing_events_table.php');

        $event = $this->recorder()->record(
            BillingEventType::REQUEST_REFUSED,
            BillingSource::REQUEST,
            null,
            reason: 'no_billable',
        );

        $fresh = BillingEvent::query()->findOrFail($event->getKey());
        $this->assertNull($fresh->billable_type);
        $this->assertNull($fresh->billable_id);
        $this->assertNull($fresh->provider);
        $this->assertCount(1, $this->dispatched);
    }

    public function test_a_caller_transaction_that_rolls_back_keeps_neither_the_row_nor_the_event(): void
    {
        $this->migrate('create_billing_events_table.php');
        $user = $this->makeUser();

        try {
            DB::transaction(function () use ($user): void {
                $this->recorder()->record(BillingEventType::CHECKOUT_STARTED, BillingSource::REQUEST, $user);

                throw new RuntimeException('The caller failed after recording.');
            });
            $this->fail('The caller transaction must throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The caller failed after recording.', $exception->getMessage());
        }

        $this->assertSame(0, BillingEvent::query()->count());
        $this->assertSame([], $this->dispatched);
    }

    public function test_the_event_waits_for_the_caller_commit(): void
    {
        $this->migrate('create_billing_events_table.php');
        $user = $this->makeUser();

        DB::transaction(function () use ($user): void {
            $this->recorder()->record(BillingEventType::CHECKOUT_STARTED, BillingSource::REQUEST, $user);

            $this->assertSame([], $this->dispatched);
        });

        $this->assertCount(1, $this->dispatched);
    }

    public function test_a_throwing_listener_is_reported_and_never_reaches_the_caller(): void
    {
        $this->migrate('create_billing_events_table.php');
        $user = $this->makeUser();
        Exceptions::fake();
        Event::listen(CheckoutStarted::class, static function (): void {
            throw new RuntimeException('The listener failed.');
        });

        $standalone = $this->recorder()->record(BillingEventType::CHECKOUT_STARTED, BillingSource::REQUEST, $user);

        $afterRecording = [];
        DB::transaction(function () use ($user, &$afterRecording): void {
            $this->recorder()->record(BillingEventType::CHECKOUT_STARTED, BillingSource::REQUEST, $user);

            // A later after-commit callback of the caller still runs.
            DB::afterCommit(static function () use (&$afterRecording): void {
                $afterRecording[] = 'ran';
            });
        });

        $this->assertTrue($standalone->exists);
        $this->assertSame(2, BillingEvent::query()->count());
        $this->assertSame(['ran'], $afterRecording);
        Exceptions::assertReportedCount(2);
        Exceptions::assertReported(
            static fn (RuntimeException $exception): bool => $exception->getMessage() === 'The listener failed.',
        );
    }

    public function test_the_insert_runs_one_transaction_level_below_its_caller(): void
    {
        $this->migrate('create_billing_events_table.php');
        $levels = [];
        BillingEvent::creating(static function () use (&$levels): void {
            $levels[] = DB::transactionLevel();
        });

        $this->recorder()->record(BillingEventType::PORTAL_OPENED, BillingSource::REQUEST, null);

        DB::transaction(function () use (&$levels): void {
            $outer = DB::transactionLevel();

            $this->recorder()->record(BillingEventType::PORTAL_OPENED, BillingSource::REQUEST, null);

            $levels[] = $outer;
        });

        // [standalone insert, nested insert, the caller's own level]
        $this->assertSame(1, $levels[0]);
        $this->assertSame($levels[2] + 1, $levels[1]);
        $this->assertSame(2, $levels[1]);
    }

    public function test_a_failed_insert_inside_a_caller_transaction_is_logged_and_the_caller_still_commits(): void
    {
        $this->createRefusingBillingEventsTable();
        Schema::create('caller_rows', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        $user = $this->makeUser();
        Log::spy();

        DB::transaction(function () use ($user): void {
            $event = $this->recorder()->record(
                BillingEventType::ENTITLEMENT_APPLIED,
                BillingSource::WEBHOOK,
                $user,
                reason: 'plan_changed',
            );

            $this->assertFalse($event->exists);

            // The caller keeps writing after the failed insert, which on
            // PostgreSQL only works because the failure stayed in its savepoint.
            DB::table('caller_rows')->insert(['name' => 'entitlement']);
        });

        $this->assertSame(1, DB::table('caller_rows')->count());
        $this->assertSame(0, DB::table('billing_events')->count());
        $this->assertCount(1, $this->dispatched);
        $this->assertInstanceOf(EntitlementApplied::class, $this->dispatched[0]);

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context) use ($user): bool {
                return $message === 'A billing event row could not be written; billing continues.'
                    && $context['type'] === 'entitlement_applied'
                    && $context['reason'] === 'plan_changed'
                    && $context['billable_type'] === $user->getMorphClass()
                    && $context['billable_id'] === (string) $user->getKey()
                    && str_contains($context['exception'], 'refuse_every_insert');
            });
    }

    public function test_a_missing_table_warns_once_dispatches_and_a_later_call_writes_the_row(): void
    {
        Log::spy();
        $recorder = $this->recorder();

        $first = $recorder->record(BillingEventType::TRIAL_RECORDED, BillingSource::WEBHOOK, null);
        $second = $recorder->record(BillingEventType::TRIAL_RECORDED, BillingSource::WEBHOOK, null);

        $this->assertFalse($first->exists);
        $this->assertFalse($second->exists);
        $this->assertCount(2, $this->dispatched);
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(static fn (string $message, array $context): bool => $context['table'] === 'billing_events');
        Log::shouldNotHaveReceived('error');

        // A worker started before the migration recovers once it has run.
        $this->migrate('create_billing_events_table.php');

        $third = $recorder->record(BillingEventType::TRIAL_RECORDED, BillingSource::WEBHOOK, null);

        $this->assertTrue($third->exists);
        $this->assertSame(1, BillingEvent::query()->count());
        $this->assertCount(3, $this->dispatched);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_the_actor_is_the_authenticated_user_when_none_is_passed(): void
    {
        $this->migrate('create_billing_events_table.php');
        $user = $this->makeUser();
        $this->actingAs($user);

        $event = $this->recorder()->record(BillingEventType::SUBSCRIPTION_CANCELLED, BillingSource::REQUEST, $user);

        $this->assertSame((string) $user->getKey(), (string) $event->actor_user_id);
    }

    public function test_an_actor_that_is_not_the_user_model_is_not_recorded(): void
    {
        $this->migrate('create_billing_events_table.php');
        $this->actingAs(new GenericUser(['id' => 42]));

        $event = $this->recorder()->record(BillingEventType::SUBSCRIPTION_SWAPPED, BillingSource::REQUEST, null);

        $this->assertNull($event->actor_user_id);
        $this->assertNull(BillingEvent::query()->findOrFail($event->getKey())->actor_user_id);
    }

    public function test_every_type_dispatches_its_own_event_exactly_once_to_an_interface_listener(): void
    {
        $this->migrate('create_billing_events_table.php');

        foreach (BillingEventType::cases() as $type) {
            $this->dispatched = [];

            $event = $this->recorder()->record($type, BillingSource::RECONCILE, null);

            $this->assertCount(1, $this->dispatched, $type->value);
            $this->assertInstanceOf($type->eventClass(), $this->dispatched[0], $type->value);
            $this->assertSame($event, $this->dispatched[0]->record(), $type->value);
        }

        $this->assertSame(count(BillingEventType::cases()), BillingEvent::query()->count());
        $this->assertCount(
            count(BillingEventType::cases()),
            array_unique(array_map(
                static fn (BillingEventType $type): string => $type->eventClass(),
                BillingEventType::cases(),
            )),
        );
    }

    /**
     * The eight operator outcomes each own an event class, none is a refusal,
     * and each leaves a success line, so an admin action is as visible in the
     * log as a webhook one.
     */
    public function test_each_admin_outcome_dispatches_its_own_event_and_logs_a_success_line(): void
    {
        $this->migrate('create_billing_events_table.php');

        $admin = [
            [BillingEventType::GRANT_ADDED, GrantAdded::class],
            [BillingEventType::GRANT_REVOKED, GrantRevoked::class],
            [BillingEventType::GRANT_EXPIRED, GrantExpired::class],
            [BillingEventType::TRIAL_EXTENDED, TrialExtended::class],
            [BillingEventType::TRIAL_ENDED, TrialEnded::class],
            [BillingEventType::SUBSCRIPTION_RESUMED, SubscriptionResumed::class],
            [BillingEventType::INVOICE_REFUNDED, InvoiceRefunded::class],
            [BillingEventType::ENTITLEMENT_SYNCED, EntitlementSynced::class],
        ];

        Log::spy();

        foreach ($admin as [$type, $class]) {
            $this->dispatched = [];

            $this->recorder()->record($type, BillingSource::ADMIN, null);

            $this->assertFalse($type->isRefusal(), $type->value);
            $this->assertSame($class, $type->eventClass(), $type->value);
            $this->assertCount(1, $this->dispatched, $type->value);
            $this->assertInstanceOf($class, $this->dispatched[0], $type->value);
        }

        Log::shouldHaveReceived('info')->times(count($admin));
        $this->assertSame(count($admin), BillingEvent::query()->where('source', 'admin')->count());
    }

    public function test_a_success_is_logged_once_after_the_caller_commits_and_a_refusal_adds_no_line(): void
    {
        $this->migrate('create_billing_events_table.php');
        $user = $this->makeUser();
        Log::spy();

        DB::transaction(function () use ($user): void {
            $this->recorder()->record(
                BillingEventType::ENTITLEMENT_APPLIED,
                BillingSource::WEBHOOK,
                $user,
                provider: BillingProvider::STRIPE,
                reason: 'plan_changed',
                externalId: 'evt_9',
            );

            Log::shouldNotHaveReceived('info');
        });

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(static function (string $message, array $context) use ($user): bool {
                return $context === [
                    'type' => 'entitlement_applied',
                    'source' => 'webhook',
                    'provider' => 'stripe',
                    'reason' => 'plan_changed',
                    'billable_type' => $user->getMorphClass(),
                    'billable_id' => (string) $user->getKey(),
                    'external_id' => 'evt_9',
                ];
            });

        $this->recorder()->record(BillingEventType::DELIVERY_REFUSED, BillingSource::WEBHOOK, $user);

        Log::shouldHaveReceived('info')->once();
    }

    private function recorder(): BillingEventRecorder
    {
        return $this->app->make(BillingEventRecorder::class);
    }

    /**
     * A `billing_events` with every real column plus one NOT NULL column the
     * recorder never fills, so every insert fails at the database.
     */
    private function createRefusingBillingEventsTable(): void
    {
        Schema::create('billing_events', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);
            $table->string('type');
            $table->string('source');
            $table->string('provider')->nullable();
            $table->string('billable_type')->nullable();
            $table->string('billable_id')->nullable();
            $table->string('actor_user_id')->nullable();
            $table->string('reason')->nullable();
            $table->string('external_id')->nullable();
            $table->json('properties')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->string('refuse_every_insert');
        });
    }

    private function makeUser(): ConcreteUser
    {
        return ConcreteUser::query()->create([
            'name' => 'Billing Person',
            'email' => 'billing-' . uniqid() . '@example.com',
            'password' => 'secret',
        ]);
    }

    private function migrate(string $file): void
    {
        (require __DIR__ . '/../../database/migrations/' . $file)->up();
    }
}
