<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use FlutterSdk\MagicStarter\Actions\WriteEntitlement;
use FlutterSdk\MagicStarter\Contracts\WritesEntitlement;
use FlutterSdk\MagicStarter\Enums\BillingProvider;
use FlutterSdk\MagicStarter\Enums\PlanStatus;
use FlutterSdk\MagicStarter\Support\EntitlementWrite;
use FlutterSdk\MagicStarter\Support\ReadsBillableAttributes;
use FlutterSdk\MagicStarter\Support\StripeSubscriptionState;
use FlutterSdk\MagicStarter\Support\TeamKey;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\AssertionFailedError;

/**
 * The rules more than one billing feeder applies, pinned where they are shared.
 *
 * Three of them had grown a second copy in the application these were extracted
 * from: the granting-status list and the status table in both the Stripe webhook
 * controller and the hourly reconciler, and the key-shape check in the
 * reconciler and the store re-read job. Every copy was correct on the day it was
 * written, and the reconciler's docblock even CITED the controller's array as the
 * list it was kept in step with, which is a claim no code can enforce.
 *
 * The cost is concrete rather than stylistic. Adding `paused` to one granting
 * list would leave the other revoking every paused subscription on its next
 * hourly run, against every affected customer at once, on a schedule, with no
 * failing test anywhere: each copy has its own passing tests, and they would
 * both keep passing while disagreeing.
 *
 * The attribute readers are here for the same reason and one more. The package
 * ships the provenance COLUMNS and not the model that casts them, so a consumer
 * may hand back an enum, a Carbon and a real boolean, or a string, a string and
 * an integer, for the same three columns. Every reader in the package has to
 * answer the same either way, which is why the assertions below run each reader
 * against a model with NO casts and a model WITH them and require the same
 * value: a suite that only ever fixtures the cast model certifies a decode that
 * silently changes shape on every adopter who did not cast.
 */
class SharedBillingRulesTest extends TestCase
{
    /**
     * The instant every date assertion below is written against.
     */
    private const STORED_AT = '2026-08-20 10:00:00';

    /**
     * Every file that must not carry its own copy, and the members it must not
     * redeclare.
     *
     * Deliberately UNTYPED: this file is the guard that checks the package
     * ships no typed class constant anywhere, and typing this one would be the
     * one exception nobody could point at.
     *
     * @var array<string, array<int, string>>
     */
    private const MUST_NOT_REDECLARE = [
        'src/Http/Controllers/StripeWebhookController.php' => [
            'GRANTING_STATUSES',
            'function grants(',
            'function planStatusFor(',
            'function planForPrice(',
        ],
        'src/Console/ReconcileBillingEntitlements.php' => [
            'GRANTING_STATUSES',
            'function grants(',
            'function planStatusFor(',
            'function planForPrice(',
            'function looksLikeOne(',
        ],
        'src/Jobs/SyncRevenueCatEntitlement.php' => [
            'function looksLikeOne(',
        ],
    ];

    public function test_no_feeder_carries_its_own_copy_of_a_shared_rule(): void
    {
        foreach (self::MUST_NOT_REDECLARE as $path => $members) {
            $absolute = self::packageRoot() . '/' . $path;

            // Vacuity guards, and there are three because the failure they
            // prevent is silent. A renamed or deleted path satisfies every
            // assertion below, since `assertStringNotContainsString()` is happy
            // to accept an empty haystack as "the member is absent". The
            // existence check comes FIRST because `file_get_contents()` on a
            // missing path raises a warning rather than reaching the assertion
            // after it, so a message about vacuity would never be the one
            // printed. The `class ` check then catches a path that exists and is
            // not the source file it was supposed to be.
            $this->assertFileExists(
                $absolute,
                "{$path} is not there, so the redeclaration guard would pass on an empty haystack. "
                . 'Either the file moved and this list needs updating, or it was deleted and the '
                . 'rule it was being held to no longer has an owner.',
            );

            $source = file_get_contents($absolute);

            $this->assertIsString($source, "{$path} could not be read; the redeclaration guard would be vacuous.");
            $this->assertStringContainsString('class ', (string) $source);

            foreach ($members as $member) {
                $this->assertStringNotContainsString(
                    $member,
                    (string) $source,
                    "{$path} declares [{$member}] itself. That rule is shared through "
                    . 'StripeSubscriptionState or TeamKey, and a second copy is free to disagree with '
                    . 'the first with every test on both sides still passing.',
                );
            }
        }
    }

    /**
     * The feeder invariant, and proof that the thing enforcing it has teeth.
     *
     * The invariant is that no feeder pairs a non-granting status with a
     * non-null tier. {@see \FlutterSdk\MagicStarter\Actions\WriteEntitlement}'s
     * rule 2 depends on it and has no guard for its absence, and rule 2's own
     * tests SUPPLY the pairing rather than checking it, so a feeder that revoked
     * without nulling the tier would pass every test in the package.
     *
     * It is enforced by {@see FeederInvariantWriter}, a decorator installed over
     * the contract in all three writing feeders' own suites, so every revocation
     * scenario those files already drive checks it. That is the coverage; this
     * test is the thing that stops the coverage from being imaginary. An assertion
     * that never fires is indistinguishable from one that passes, so here the
     * decorator is handed a violating claim directly and required to reject it.
     *
     * The three suites it is installed in, by execution and not by inspection:
     * `tests/Http/Controllers/StripeWebhookTest.php`,
     * `tests/Jobs/SyncRevenueCatEntitlementTest.php`,
     * `tests/Console/ReconcileBillingEntitlementsTest.php`. The RevenueCat
     * webhook controller is not among them because it writes no entitlement at
     * all; it verifies, claims an event id and dispatches.
     */
    public function test_the_feeder_invariant_guard_rejects_a_revocation_that_keeps_its_tier(): void
    {
        $accepted = new class implements WritesEntitlement
        {
            public function write(EntitlementWrite $write): bool
            {
                return true;
            }
        };

        $guard = new FeederInvariantWriter($accepted);
        FeederInvariantWriter::reset();

        // A proper revocation: the status grants nothing and the tier is gone.
        $this->assertTrue($guard->write($this->claim(PlanStatus::CANCELED, null)));

        // A grant: the tier is named and the status grants, so the invariant has
        // nothing to say about it.
        $this->assertTrue($guard->write($this->claim(PlanStatus::ACTIVE, 'pro')));

        $this->assertSame(2, FeederInvariantWriter::$checked, 'The guard did not see the claims it was handed.');

        // The violation: revoking while still naming a tier.
        try {
            $guard->write($this->claim(PlanStatus::CANCELED, 'pro'));

            $this->fail('The guard accepted a claim that revokes while still naming a tier.');
        } catch (AssertionFailedError $failure) {
            $this->assertStringContainsString('FEEDER INVARIANT VIOLATED', $failure->getMessage());
        }
    }

    /**
     * One claim, for the invariant test above.
     */
    private function claim(PlanStatus $status, ?string $plan): EntitlementWrite
    {
        return new EntitlementWrite(
            billable: new ConcreteUser,
            plan: $plan,
            status: $status,
            provider: BillingProvider::STRIPE,
            eventAt: CarbonImmutable::parse(self::STORED_AT),
            authoritative: true,
        );
    }

    /**
     * Where the package's own source lives, for the guard above.
     *
     * Derived from this file's location rather than from `base_path()`, which
     * under Testbench points at the skeleton application and not at the package.
     * A guard reading its feeder files from there would find nothing and pass on
     * an empty haystack, which is exactly what the `assertIsString()` call above
     * is there to catch.
     */
    private static function packageRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    public function test_the_granting_statuses_are_the_three_stripe_grants_under(): void
    {
        // Pinned as a VALUE, not just as a shared reference: the point of the
        // shared table is that changing it changes both feeders, so the table
        // itself is the thing worth a failing test when it moves.
        $this->assertSame(
            ['active', 'trialing', 'past_due'],
            StripeSubscriptionState::GRANTING_STATUSES,
        );

        $this->assertTrue(StripeSubscriptionState::grants('past_due'));
        $this->assertFalse(StripeSubscriptionState::grants('canceled'));
        $this->assertFalse(StripeSubscriptionState::grants('paused'));
    }

    public function test_an_unknown_stripe_status_cannot_entitle_by_accident(): void
    {
        $this->assertSame(
            PlanStatus::ACTIVE,
            StripeSubscriptionState::planStatusFor('active'),
        );
        $this->assertSame(
            PlanStatus::EXPIRED,
            StripeSubscriptionState::planStatusFor('incomplete_expired'),
        );

        // The property that matters for a word Stripe adds next year: it falls
        // through to the neutral decoder, which lands on a non-granting status.
        $this->assertFalse(
            StripeSubscriptionState::planStatusFor('some_future_status')->grants(),
        );
    }

    public function test_a_price_id_of_zero_is_looked_up_rather_than_discarded(): void
    {
        // The two copies had already drifted here: one guarded with `! $priceId`,
        // which is true for the string '0'. No Stripe price looks like that, so
        // nothing was broken; two copies of one rule disagreeing about an edge
        // case is how they always start.
        config()->set('magic-starter.billing.prices', ['0' => 'pro']);

        $this->assertSame('pro', StripeSubscriptionState::planForPrice('0'));
        $this->assertNull(StripeSubscriptionState::planForPrice(''));
        $this->assertNull(StripeSubscriptionState::planForPrice(null));
        $this->assertNull(StripeSubscriptionState::planForPrice('price_unmapped'));
    }

    /**
     * An empty key in the map cannot sell a tier, and a real one beside it still
     * does.
     *
     * The map is normally assembled from the environment, so one unset variable
     * is all it takes to write `'' => 'pro'` into it. The lookup's own guard
     * already refuses an empty price id, which is exactly why the second half of
     * this test exists: asserting only `planForPrice('')` passes whether or not
     * the map is filtered, and the entry survives to be found by the REVERSE
     * lookup a checkout makes, where the empty string would come back as the
     * price that sells a paid tier.
     */
    public function test_an_empty_price_id_cannot_sell_a_paid_tier(): void
    {
        config()->set('magic-starter.billing.prices', [
            '' => 'pro',
            'price_business' => 'business',
        ]);

        $this->assertNull(StripeSubscriptionState::planForPrice(''));
        $this->assertSame('business', StripeSubscriptionState::planForPrice('price_business'));

        // The half that distinguishes a filtered map from an unfiltered one.
        $this->assertSame(['price_business' => 'business'], StripeSubscriptionState::prices());
        $this->assertFalse(array_search('pro', StripeSubscriptionState::prices(), true));
    }

    public function test_a_billable_key_is_judged_against_this_deployments_switch(): void
    {
        config()->set('magic-starter.use_uuids', true);
        $this->assertTrue(TeamKey::looksLikeOne('a26c03f7-e668-4d5f-bb45-f573e1d87088'));
        $this->assertFalse(TeamKey::looksLikeOne('42'));

        // The half a hardcoded UUID check would have broken: a deployment on
        // integer keys must not have every legitimate id refused.
        config()->set('magic-starter.use_uuids', false);
        $this->assertTrue(TeamKey::looksLikeOne('42'));
        $this->assertFalse(TeamKey::looksLikeOne('a26c03f7-e668-4d5f-bb45-f573e1d87088'));
    }

    /**
     * All four readers answer the same off a cast model and an uncast one.
     *
     * The two fixtures carry byte-identical RAW attributes, the way a driver
     * hands a row back, and differ only in whether the model casts them. Any
     * reader that agreed with only one of them would be a wire shape that
     * depends on a decision the adopter makes, which is the defect the trait
     * exists to remove.
     */
    public function test_every_reader_agrees_across_a_cast_and_an_uncast_model(): void
    {
        $reader = new BillableAttributeReader;

        $uncast = $this->rawBillable(UncastBillable::class);
        $cast = $this->rawBillable(CastingBillable::class);

        // The disarming limb, and it is not decoration: every assertion below
        // would pass against two fixtures that decode identically, so the test
        // would certify a decode it never exercised. These four reads are the
        // proof that the two models really do hand back different shapes for the
        // same raw row.
        $this->assertSame(1, $uncast->getAttribute('plan_renews'));
        $this->assertTrue($cast->getAttribute('plan_renews'));
        $this->assertIsString($uncast->getAttribute('plan_source_event_at'));
        $this->assertInstanceOf(CarbonInterface::class, $cast->getAttribute('plan_source_event_at'));
        $this->assertIsString($uncast->getAttribute('plan'));
        $this->assertInstanceOf(FixtureTier::class, $cast->getAttribute('plan'));

        $this->assertSame('pro', $reader->readString($uncast, 'plan'));
        $this->assertSame('pro', $reader->readString($cast, 'plan'));

        $this->assertSame(true, $reader->readBoolean($uncast, 'plan_renews'));
        $this->assertSame(true, $reader->readBoolean($cast, 'plan_renews'));

        $this->assertSame(
            $reader->readDate($uncast, 'plan_current_period_end')?->toIso8601String(),
            $reader->readDate($cast, 'plan_current_period_end')?->toIso8601String(),
        );

        $this->assertSame(
            $reader->readStoredEventAt($uncast)?->toIso8601String(),
            $reader->readStoredEventAt($cast)?->toIso8601String(),
        );

        // Absent reads as absent on both, rather than as an empty string, a
        // false, or the epoch.
        $this->assertNull($reader->readString($uncast, 'plan_product_id'));
        $this->assertNull($reader->readString($cast, 'plan_product_id'));
        $this->assertNull($reader->readBoolean($uncast, 'plan_product_id'));
        $this->assertNull($reader->readBoolean($cast, 'plan_product_id'));
        $this->assertNull($reader->readDate($uncast, 'plan_grace_period_ends_at'));
        $this->assertNull($reader->readDate($cast, 'plan_grace_period_ends_at'));
    }

    /**
     * The TYPES, and not just the values, because the wire carries the type.
     *
     * A stored `1` read as `1` and a stored ISO string read as a string both
     * satisfy "the field resolves", and both are a client-visible change: a
     * decoder expecting a boolean gets a number, and a caller with a
     * `?CarbonInterface` parameter gets a TypeError on a payment path.
     */
    public function test_a_stored_integer_reads_as_a_bool_and_a_stored_iso_string_as_an_instant(): void
    {
        $reader = new BillableAttributeReader;

        $billable = $this->rawBillable(UncastBillable::class, [
            'plan_renews' => 1,
            'plan_current_period_end' => '2026-08-20T10:00:00+00:00',
        ]);

        $renews = $reader->readBoolean($billable, 'plan_renews');
        $periodEnd = $reader->readDate($billable, 'plan_current_period_end');

        $this->assertTrue($renews);
        $this->assertNotSame(1, $renews);
        $this->assertInstanceOf(CarbonInterface::class, $periodEnd);
        $this->assertSame('2026-08-20T10:00:00+00:00', $periodEnd->toIso8601String());

        // A stored zero is a real false and not an absence: the column saying
        // "this will not renew" is an answer, and null would report it as
        // unknown to every client.
        $this->assertFalse($reader->readBoolean(
            $this->rawBillable(UncastBillable::class, ['plan_renews' => 0]),
            'plan_renews',
        ));
    }

    /**
     * An unparseable stored date RAISES rather than reading as absent.
     *
     * The assertion the arbitration suite cannot supply, because every case
     * there seeds a valid timestamp, so a defensive reader returning null passes
     * all of them. Null is not the safe answer here: the ordering rule reads
     * absent as "this rail has never written, so the incoming write applies",
     * which is the one conclusion a corrupt row cannot support.
     */
    public function test_an_unparseable_stored_date_raises_rather_than_reading_as_absent(): void
    {
        $reader = new BillableAttributeReader;

        $billable = $this->rawBillable(UncastBillable::class, [
            'plan_source_event_at' => 'definitely-not-a-timestamp',
        ]);

        $this->expectException(InvalidFormatException::class);

        $reader->readStoredEventAt($billable);
    }

    /**
     * `storedEventAt()` delegates to the date reader instead of parsing again.
     *
     * Asserted through a subclass that overrides the reader, so the test fails
     * if the delegation is ever unrolled back into a second copy of the same
     * decode. Comparing the two return values would not: two copies agree until
     * one of them is edited, which is the whole failure mode.
     */
    public function test_stored_event_at_delegates_to_the_shared_date_reader(): void
    {
        $probe = new DelegationProbe;

        $probe->readStoredEventAt($this->rawBillable(UncastBillable::class));

        $this->assertSame(['plan_source_event_at'], $probe->columnsRead);
    }

    /**
     * The readers are reachable from something that is not the action.
     *
     * The whole reason for the extraction: they were protected methods on
     * {@see WriteEntitlement}, so a controller,
     * a resource, a job or a command could not call them and would have written
     * its own copy. This is that call, from a class with no relationship to the
     * action at all.
     */
    public function test_the_readers_are_reachable_from_a_class_that_is_not_the_action(): void
    {
        $reader = new BillableAttributeReader;

        $this->assertNotInstanceOf(WriteEntitlement::class, $reader);
        $this->assertSame(
            'stripe',
            $reader->readString($this->rawBillable(UncastBillable::class), 'plan_provider'),
        );
    }

    /**
     * A billable holding raw attributes exactly as a driver hands them back.
     *
     * `setRawAttributes()` rather than `forceFill()`, and no database at all:
     * filling would run the model's own set-side casting, so the cast fixture
     * would never be asked to decode anything and the two models would be
     * compared on values one of them had already normalised.
     *
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $overrides
     */
    private function rawBillable(string $modelClass, array $overrides = []): Model
    {
        $billable = new $modelClass;

        $billable->setRawAttributes([
            'id' => 1,
            'plan' => 'pro',
            'plan_status' => 'active',
            'plan_provider' => 'stripe',
            'plan_source_event_at' => self::STORED_AT,
            'plan_current_period_end' => self::STORED_AT,
            'plan_renews' => 1,
            'plan_grace_period_ends_at' => null,
            'plan_product_id' => null,
            ...$overrides,
        ], true);

        return $billable;
    }
}

/**
 * A consumer-shaped tier vocabulary, for the cast fixture below.
 *
 * The package deliberately ships no tier enum (the vocabulary belongs to the
 * consuming application), so the only way to exercise a model that casts `plan`
 * to one is to declare one here.
 */
enum FixtureTier: string
{
    case FREE = 'free';
    case PRO = 'pro';
    case BUSINESS = 'business';
}

/**
 * The adopter who cast NOTHING, which is the shape the package actually ships.
 */
class UncastBillable extends Model
{
    protected $table = 'billables';

    protected $guarded = [];
}

/**
 * The adopter who cast all three columns, the way the one real consumer does.
 */
class CastingBillable extends Model
{
    protected $table = 'billables';

    protected $guarded = [];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'plan' => FixtureTier::class,
            'plan_source_event_at' => 'datetime',
            'plan_current_period_end' => 'datetime',
            'plan_grace_period_ends_at' => 'datetime',
            'plan_renews' => 'boolean',
        ];
    }
}

/**
 * A reader that is not the action, calling the trait the way a controller will.
 *
 * The trait's methods stay protected, so this is also the shape every future
 * caller takes: use the trait, call it from inside your own class.
 */
class BillableAttributeReader
{
    use ReadsBillableAttributes;

    public function readString(Model $billable, string $attribute): ?string
    {
        return $this->stringAttribute($billable, $attribute);
    }

    public function readDate(Model $billable, string $attribute): ?CarbonInterface
    {
        return $this->dateAttribute($billable, $attribute);
    }

    public function readBoolean(Model $billable, string $attribute): ?bool
    {
        return $this->booleanAttribute($billable, $attribute);
    }

    public function readStoredEventAt(Model $billable): ?CarbonInterface
    {
        return $this->storedEventAt($billable);
    }
}

/**
 * Records which column the date reader was asked for, so the delegation is
 * observable rather than inferred.
 */
class DelegationProbe extends BillableAttributeReader
{
    /**
     * @var list<string>
     */
    public array $columnsRead = [];

    protected function dateAttribute(Model $billable, string $attribute): ?CarbonInterface
    {
        $this->columnsRead[] = $attribute;

        return parent::dateAttribute($billable, $attribute);
    }
}
