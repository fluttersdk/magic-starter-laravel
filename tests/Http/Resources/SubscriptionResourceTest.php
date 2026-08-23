<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Resources;

use FlutterSdk\MagicStarter\Enums\BillingProvider;
use FlutterSdk\MagicStarter\Enums\PlanStatus;
use FlutterSdk\MagicStarter\Http\Resources\SubscriptionResource;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The billing wire, asserted off the model this package actually ships for.
 *
 * Which is a model that casts NOTHING. The package ships the provenance columns
 * and not the model that owns them, so the one consumer these fields were ported
 * from casts `plan` to its own tier enum, `plan_renews` to `boolean` and the two
 * period columns to `datetime`, and every fixture built from a model like that
 * certifies a decode it never exercised. A resource reading the typed properties
 * directly passes such a suite while putting a driver's `1` on the wire where the
 * client decodes a boolean, and while reaching `?->toIso8601String()` on a plain
 * string, which is a 500 on the billing screen rather than a wrong field.
 *
 * So the assertions below are `assertSame` on the VALUE and therefore on the
 * type, off a raw row, and one of them runs the same raw row through a model that
 * casts everything and requires a byte-identical wire.
 */
class SubscriptionResourceTest extends TestCase
{
    /**
     * The instant every period assertion below is written against.
     */
    private const PERIOD_END = '2026-09-01 12:00:00';

    private const PERIOD_END_ISO = '2026-09-01T12:00:00+00:00';

    /**
     * Every field resolves off a raw row, and the four that carry a TYPE carry it.
     */
    public function test_the_whole_wire_resolves_off_a_model_that_casts_nothing(): void
    {
        $billable = $this->rawSubject();

        // The disarming limb. Every assertion below would also pass against a
        // fixture whose model had already decoded these columns, which is the
        // fixture that hides the defect this resource exists to remove: read the
        // two raw reads first and prove the row really is undecoded.
        $this->assertSame(1, $billable->getAttribute('plan_renews'));
        $this->assertIsString($billable->getAttribute('plan_current_period_end'));

        $wire = $this->wire($billable);

        $this->assertSame([
            'plan',
            'plan_status',
            'subscribed',
            'renews',
            'provider',
            'provider_status',
            'product_id',
            'manage_via',
            'manage_url',
            'current_period_end',
            'trial_ends_at',
            'grace_period_ends_at',
        ], array_keys($wire));

        // A tier allowance is a consumer's gate, computed from a catalogue this
        // package does not have. It left with the port rather than shipping as a
        // field no adopter could fill.
        $this->assertArrayNotHasKey('ai_analysis_trials_remaining', $wire);

        // The four the step exists for. `assertSame` and not `assertEquals`,
        // because `1 == true` and `'2026-09-01T12:00:00+00:00' == $carbon` are
        // both true and both a client-visible change.
        $this->assertSame('pro', $wire['plan']);
        $this->assertSame(true, $wire['renews']);
        $this->assertSame(self::PERIOD_END_ISO, $wire['current_period_end']);
        $this->assertSame(null, $wire['grace_period_ends_at']);

        // The other half of "ISO strings or null": a grace window that exists
        // reaches the wire as a string and not as an instant.
        $grace = $this->wire($this->rawSubject(overrides: [
            'plan_grace_period_ends_at' => '2026-09-05 08:30:00',
        ]));
        $this->assertSame('2026-09-05T08:30:00+00:00', $grace['grace_period_ends_at']);

        // And the fields that are not fragile, so the port is not asserted only
        // where it was converted.
        $this->assertSame('active', $wire['plan_status']);
        $this->assertSame(true, $wire['subscribed']);
        $this->assertSame('stripe', $wire['provider']);
        $this->assertSame('trialing', $wire['provider_status']);
        $this->assertSame('price_pro', $wire['product_id']);
        $this->assertSame('none', $wire['manage_via']);
        $this->assertSame(null, $wire['manage_url']);
        $this->assertSame(null, $wire['trial_ends_at']);
    }

    /**
     * The same raw row, read through a model that casts every column, is the same
     * wire.
     *
     * Both shapes are legitimate and the client cannot see which one an adopter
     * chose, so the two wires have to be identical rather than merely equivalent.
     * The `plan_status` and `plan_provider` halves are the ones a direct property
     * read would lose: a consumer casting either to an enum hands
     * {@see PlanStatus::fromWire()} an enum instance where it declares `?string`,
     * which is a TypeError on the billing screen and not a wrong field.
     */
    public function test_the_same_row_reads_identically_off_a_model_that_casts_everything(): void
    {
        $uncast = $this->rawSubject();
        $cast = $this->rawSubject(CastingSubject::class);

        // The disarming limb again, and here it is the whole point: the two
        // models really do hand back different shapes for identical raw
        // attributes, so an identical wire is a decode and not a coincidence.
        $this->assertSame(1, $uncast->getAttribute('plan_renews'));
        $this->assertTrue($cast->getAttribute('plan_renews'));
        $this->assertIsString($uncast->getAttribute('plan'));
        $this->assertInstanceOf(SubjectTier::class, $cast->getAttribute('plan'));
        $this->assertIsString($uncast->getAttribute('plan_provider'));
        $this->assertInstanceOf(BillingProvider::class, $cast->getAttribute('plan_provider'));

        $this->assertSame($this->wire($uncast), $this->wire($cast));

        // Stated on the cast side too, so the shared value is pinned rather than
        // only compared: two wires agreeing on `1` would satisfy the line above.
        $this->assertSame(true, $this->wire($cast)['renews']);
        $this->assertSame(self::PERIOD_END_ISO, $this->wire($cast)['current_period_end']);
        $this->assertSame('pro', $this->wire($cast)['plan']);
    }

    /**
     * A billable holding nothing says so, rather than being handed a free tier.
     *
     * The port dropped the tier reader that answered "free" for a revoked NULL,
     * because naming a free tier is naming a tier and the package has no
     * vocabulary to name one from. So `plan` is nullable on this wire, and the
     * `subscribed` half has to survive that: a null tier is not subscribed even
     * while the lifecycle column still says `active`, which is the row a rail
     * leaves behind when it revokes a grant it did not make.
     */
    public function test_a_null_plan_reaches_the_wire_as_null_rather_than_as_a_free_tier(): void
    {
        $revoked = $this->wire($this->rawSubject(overrides: [
            'plan' => null,
            'plan_status' => 'canceled',
        ]));

        $this->assertNull($revoked['plan']);
        $this->assertSame(false, $revoked['subscribed']);
        $this->assertNotContains('free', $revoked, 'No tier word this package does not own may reach the wire.');

        $stillActive = $this->wire($this->rawSubject(overrides: ['plan' => null]));

        $this->assertNull($stillActive['plan']);
        $this->assertSame('active', $stillActive['plan_status']);
        $this->assertSame(false, $stillActive['subscribed']);

        // And the mirror: a tier that is still owed while the charge is retried
        // stays subscribed, which is what makes the null check above a tier
        // question rather than a status one.
        $this->assertSame(true, $this->wire($this->rawSubject(overrides: [
            'plan_status' => 'past_due',
        ]))['subscribed']);
    }

    /**
     * An unknown renewal state is reported as unknown, not as a refusal.
     */
    public function test_an_absent_renewal_flag_reaches_the_wire_as_null_rather_than_false(): void
    {
        $this->assertNull($this->wire($this->rawSubject(overrides: ['plan_renews' => null]))['renews']);

        // A stored zero is an answer, and the wire must not report it as unknown.
        $this->assertSame(false, $this->wire($this->rawSubject(overrides: ['plan_renews' => 0]))['renews']);
    }

    /**
     * `manage_via` is a function of the rail, and `manage_url` only of a store.
     */
    public function test_the_management_destination_is_a_function_of_the_rail(): void
    {
        $store = $this->wire($this->rawSubject(overrides: [
            'plan_provider' => 'app_store',
            'plan_manage_url' => 'https://apps.apple.com/account/subscriptions',
        ]));
        $this->assertSame('app_store', $store['manage_via']);
        $this->assertSame('https://apps.apple.com/account/subscriptions', $store['manage_url']);

        $this->assertSame('play_store', $this->wire($this->rawSubject(overrides: [
            'plan_provider' => 'play_store',
        ]))['manage_via']);

        // A stale destination on a non-store row must not reach a client that
        // would open it.
        $stripe = $this->wire($this->rawSubject(overrides: [
            'plan_manage_url' => 'https://apps.apple.com/account/subscriptions',
        ]));
        $this->assertSame(null, $stripe['manage_url']);

        // No Stripe customer, no portal: the surface named here has to be one the
        // portal endpoint can actually answer for.
        $this->assertSame('none', $stripe['manage_via']);

        foreach (['manual', 'none', 'something_a_newer_release_sells_through'] as $provider) {
            $this->assertSame('none', $this->wire($this->rawSubject(overrides: [
                'plan_provider' => $provider,
            ]))['manage_via']);
        }
    }

    /**
     * The billing screen's hot path reads local rows and dials nothing.
     *
     * The fixture is the assertion: its `billingPortalUrl()` and its
     * subscription's `currentPeriodEnd()` throw, and both are exactly the
     * accessors a reader decoding a period or a management destination is tempted
     * by. In production they are live Stripe calls, one of them per subscription
     * item, on every render of this resource.
     */
    public function test_nothing_on_this_path_dials_a_payment_rail(): void
    {
        $billable = $this->rawSubject(CashierishSubject::class, ['stripe_id' => 'cus_test']);
        $billable->trialSubscription = $this->fakeSubscription('2026-09-10 00:00:00');

        $wire = $this->wire($billable);

        $this->assertSame('portal', $wire['manage_via']);
        $this->assertSame(self::PERIOD_END_ISO, $wire['current_period_end']);

        // The one Cashier read that stays, and it is a plain column on a local
        // row rather than an accessor that retrieves anything.
        $this->assertSame('2026-09-10T00:00:00+00:00', $wire['trial_ends_at']);

        // A Cashier billable with no subscription of that type is null rather
        // than a fatal, which is the shape a store-rail customer arrives in.
        $withoutSubscription = $this->rawSubject(CashierishSubject::class, ['stripe_id' => 'cus_test']);
        $this->assertSame(null, $this->wire($withoutSubscription)['trial_ends_at']);
    }

    /**
     * Run the resource the way a controller does.
     *
     * @return array<string, mixed>
     */
    private function wire(Model $billable): array
    {
        return (new SubscriptionResource($billable))->toArray(Request::create('/billing'));
    }

    /**
     * A billable holding raw attributes exactly as a driver hands them back.
     *
     * `setRawAttributes()` rather than `forceFill()`, and no database at all:
     * filling would run the model's own set-side casting, so the casting fixture
     * would never be asked to decode anything and the two models would be
     * compared on values one of them had already normalised.
     *
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $overrides
     */
    private function rawSubject(string $modelClass = UncastSubject::class, array $overrides = []): Model
    {
        $billable = new $modelClass;

        $billable->setRawAttributes([
            'id' => 1,
            'plan' => 'pro',
            'plan_status' => 'active',
            'plan_provider' => 'stripe',
            'plan_provider_status' => 'trialing',
            'plan_product_id' => 'price_pro',
            'plan_source_event_at' => self::PERIOD_END,
            'plan_current_period_end' => self::PERIOD_END,
            'plan_renews' => 1,
            'plan_grace_period_ends_at' => null,
            'plan_manage_url' => null,
            ...$overrides,
        ], true);

        return $billable;
    }

    /**
     * A subscription row carrying a raw trial end and a throwing rail accessor.
     */
    private function fakeSubscription(string $trialEndsAt): FakeSubscription
    {
        $subscription = new FakeSubscription;

        $subscription->setRawAttributes([
            'id' => 1,
            'type' => 'default',
            'trial_ends_at' => $trialEndsAt,
        ], true);

        return $subscription;
    }
}

/**
 * A consumer-shaped tier vocabulary, for the casting fixture below.
 *
 * The package deliberately ships no tier enum, so the only way to exercise a
 * model that casts `plan` to one is to declare one here.
 */
enum SubjectTier: string
{
    case FREE = 'free';
    case PRO = 'pro';
}

/**
 * The adopter who cast NOTHING, which is the shape the package actually ships.
 */
class UncastSubject extends Model
{
    protected $table = 'billables';

    protected $guarded = [];
}

/**
 * The adopter who cast every provenance column, the way the one real consumer
 * does, including the two this package's own enums decode.
 */
class CastingSubject extends Model
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
            'plan' => SubjectTier::class,
            'plan_status' => PlanStatus::class,
            'plan_provider' => BillingProvider::class,
            'plan_renews' => 'boolean',
            'plan_current_period_end' => 'datetime',
            'plan_grace_period_ends_at' => 'datetime',
        ];
    }
}

/**
 * A billable carrying Cashier's two tempting accessors, both of which throw.
 *
 * `hasStripeId()` is a local column read and is the one Cashier method this
 * resource may call. `billingPortalUrl()` mints a portal session over the
 * network, so its body here is the assertion that nothing reaches for it.
 */
class CashierishSubject extends Model
{
    public ?FakeSubscription $trialSubscription = null;

    protected $table = 'billables';

    protected $guarded = [];

    public function hasStripeId(): bool
    {
        return $this->getAttribute('stripe_id') !== null;
    }

    public function subscription(string $type = 'default'): ?FakeSubscription
    {
        return $type === 'default' ? $this->trialSubscription : null;
    }

    /**
     * @throws RuntimeException Always. A live Stripe call on a render path.
     */
    public function billingPortalUrl(string $returnUrl = ''): string
    {
        throw new RuntimeException('billingPortalUrl() mints a portal session over the network.');
    }
}

/**
 * A subscription row whose period accessor is a live retrieve per item.
 */
class FakeSubscription extends Model
{
    protected $table = 'subscriptions';

    protected $guarded = [];

    /**
     * @throws RuntimeException Always. A live Stripe call, per subscription item.
     */
    public function currentPeriodEnd(): string
    {
        throw new RuntimeException('currentPeriodEnd() retrieves per subscription item.');
    }
}
