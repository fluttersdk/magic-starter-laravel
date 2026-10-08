<?php

namespace FlutterSdk\MagicStarter\Tests\Http;

use FlutterSdk\MagicStarter\Contracts\ReportsUsage;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Models\Team;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeamUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use FlutterSdk\MagicStarter\Traits\HasTeams;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Gate;

/**
 * The PRODUCER half of the billing wire contract, pinned byte for byte.
 *
 * The client decoders (`magic_payments`) are tested against JSON files, and a
 * file somebody typed by hand certifies a shape this backend may never emit.
 * These fixtures are rendered by the real endpoints for a real subject instead,
 * so a consumer test reading them reads what production sends, and any change
 * to either response fails here until the committed file is regenerated on
 * purpose and reviewed as a wire change.
 *
 * The bytes are the response body exactly as Laravel encodes it. That is the
 * point rather than a convenience: `[]` and `{}` decode to the same PHP array
 * and are opposite things to a client decoding a map, so only the encoded body
 * can carry the difference.
 *
 * To regenerate after an intended wire change:
 *
 *     MAGIC_STARTER_REGENERATE_WIRE_FIXTURES=1 vendor/bin/phpunit --filter WireFixturesTest
 *
 * then review the diff under `tests/Fixtures/wire/` and copy the files into the
 * consumer packages' fixtures.
 */
class WireFixturesTest extends TestCase
{
    /**
     * The environment flag that rewrites the committed fixtures.
     */
    private const REGENERATE_FLAG = 'MAGIC_STARTER_REGENERATE_WIRE_FIXTURES';

    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::reset();

        config([
            'database.default' => 'testing',
            'database.connections.testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'magic-starter.models.user' => WireFixtureUser::class,
            'magic-starter.models.team' => WireFixtureTeam::class,
            'magic-starter.models.membership' => ConcreteTeamUser::class,
            'magic-starter.route_prefix' => '',
            'magic-starter.features' => [
                Features::teams(),
                Features::billing(),
            ],
            'magic-starter.billing.billable' => 'team',
            'magic-starter.billing.tier_order' => [
                'free',
                'pro',
                'business',
            ],
            'magic-starter.billing.tiers' => [
                'free' => [
                    'name' => 'Free',
                    'features' => ['One of everything'],
                    'recommended' => false,
                ],
                'pro' => [
                    'name' => 'Pro',
                    'features' => ['Rather more of everything'],
                    'recommended' => true,
                    'limits' => ['seats' => 10],
                ],
                'business' => [
                    'name' => 'Business',
                    'features' => ['All of it'],
                    'recommended' => false,
                ],
            ],
            // Every product shape the wire can carry: a subscription sold on
            // every rail, one sold on the card rail with a derived store price,
            // one sold on the stores only, and a one-off purchase with no tier.
            'magic-starter.billing.products' => [
                'pro_monthly' => [
                    'type' => 'subscription',
                    'tier' => 'pro',
                    'cycle' => 'monthly',
                    'prices' => [
                        'web' => [
                            'USD' => 2900,
                            'TRY' => 99900,
                        ],
                    ],
                    'refs' => [
                        'stripe_price' => 'price_pro_monthly',
                        'app_store' => 'com.example.pro.monthly',
                        'play' => 'pro:monthly',
                    ],
                ],
                'pro_annual' => [
                    'type' => 'subscription',
                    'tier' => 'pro',
                    'cycle' => 'annual',
                    'prices' => [
                        'web' => [
                            'USD' => 29000,
                        ],
                    ],
                    'refs' => [
                        'stripe_price' => 'price_pro_annual',
                    ],
                ],
                'business_monthly' => [
                    'type' => 'subscription',
                    'tier' => 'business',
                    'cycle' => 'monthly',
                    'refs' => [
                        'app_store' => 'com.example.business.monthly',
                    ],
                ],
                'credits_100' => [
                    'type' => 'consumable',
                    'credits' => 100,
                    'prices' => [
                        'web' => [
                            'USD' => 500,
                        ],
                    ],
                ],
            ],
            'auth.providers.users' => [
                'driver' => 'eloquent',
                'model' => WireFixtureUser::class,
            ],
            // The harness shim the other billing tests carry: Sanctum's token
            // driver is not registered in a Testbench skeleton, so the guard
            // NAME points at the session driver.
            'auth.guards.sanctum' => [
                'driver' => 'session',
                'provider' => 'users',
            ],
        ]);

        $this->app->bind(ReportsUsage::class, fn (): ReportsUsage => new WireFixtureUsageReporter);

        $this->app['router']->setRoutes(new RouteCollection);
        $this->app->forgetInstance(GateContract::class);
        Gate::clearResolvedInstance(GateContract::class);

        (new MagicStarterServiceProvider($this->app))->boot();

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        MagicStarter::reset();

        parent::tearDown();
    }

    /**
     * GET billing for a team on `pro_annual` through Stripe, usage reporter bound.
     */
    public function test_the_entitlement_wire_matches_the_committed_fixture(): void
    {
        $this->assertMatchesWireFixture('billing.json', $this->fixtureBody('/billing'));
    }

    /**
     * GET billing/plans for the same catalogue.
     */
    public function test_the_plans_wire_matches_the_committed_fixture(): void
    {
        $this->assertMatchesWireFixture('billing-plans.json', $this->fixtureBody('/billing/plans'));
    }

    /**
     * Render one endpoint for the fixture team's owner, as the raw body.
     */
    private function fixtureBody(string $path): string
    {
        $owner = WireFixtureUser::query()->create([
            'name' => 'Wire Owner',
            'email' => 'wire-owner@example.test',
        ]);

        $team = WireFixtureTeam::query()->create([
            'user_id' => $owner->getKey(),
            'name' => 'Wire Team',
            'personal_team' => false,
            'stripe_id' => 'cus_wire_fixture',
            'plan' => 'pro',
            'plan_status' => 'active',
            'plan_provider' => 'stripe',
            'plan_provider_status' => 'active',
            'plan_product_id' => 'price_pro_annual',
            'plan_renews' => '1',
            'plan_current_period_end' => '2027-01-01 00:00:00',
            'plan_source_event_at' => '2026-01-01 00:00:00',
        ]);
        $team->users()->attach($owner->getKey(), ['role' => 'owner']);
        $owner->forceFill(['current_team_id' => $team->getKey()])->save();

        $response = $this->actingAs($owner->fresh(), 'sanctum')->getJson($path);
        $response->assertOk();

        return (string) $response->getContent();
    }

    /**
     * Compare a rendered body with its committed fixture, or rewrite the
     * fixture when the regenerate flag is set.
     *
     * A missing fixture FAILS rather than being written on first run: a test
     * that creates its own expectation passes on the run that matters least.
     */
    private function assertMatchesWireFixture(string $file, string $body): void
    {
        $path = __DIR__ . '/../Fixtures/wire/' . $file;
        $contents = $body . "\n";

        if (getenv(self::REGENERATE_FLAG) === '1') {
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }

            file_put_contents($path, $contents);
        }

        $this->assertFileExists(
            $path,
            "No committed wire fixture [{$file}]; run with " . self::REGENERATE_FLAG . '=1 to write it.',
        );

        $this->assertSame(
            file_get_contents($path),
            $contents,
            "The [{$file}] wire changed. If that is intended, regenerate with "
            . self::REGENERATE_FLAG . '=1 and ship the diff to the consumer fixtures.',
        );
    }

    private function createSchema(): void
    {
        $schema = app('db.schema');

        $schema->create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->uuid('current_team_id')->nullable();
            $table->timestamps();
        });

        $schema->create('teams', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('name');
            $table->boolean('personal_team')->default(false);
            $table->string('stripe_id')->nullable();
            $table->string('plan')->nullable();
            $table->string('plan_status')->nullable();
            $table->string('plan_provider')->nullable();
            $table->string('plan_provider_status')->nullable();
            $table->string('plan_product_id')->nullable();
            $table->string('plan_manage_url')->nullable();
            $table->string('plan_renews')->nullable();
            $table->string('plan_current_period_end')->nullable();
            $table->string('plan_grace_period_ends_at')->nullable();
            $table->string('plan_source_event_at')->nullable();
            $table->timestamps();
        });

        $schema->create('team_user', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->uuid('user_id');
            $table->string('role')->nullable();
            $table->timestamps();
        });
    }
}

class WireFixtureUser extends Model implements AuthenticatableContract
{
    use AuthenticatableTrait;
    use Authorizable;
    use ConditionallyUsesUuids;
    use HasTeams;

    protected $table = 'users';

    protected $guarded = [];
}

/**
 * The billable team, carrying the one Cashier read the entitlement makes.
 */
class WireFixtureTeam extends Team
{
    protected $table = 'teams';

    /**
     * Emptied because the package's own Team declares a four-key `$fillable`,
     * which would otherwise drop every provenance column set below.
     */
    protected $fillable = [];

    protected $guarded = [];

    public function hasStripeId(): bool
    {
        return $this->getAttribute('stripe_id') !== null;
    }
}

/**
 * A consumer's usage reporter, so `allowances` carries a populated map.
 */
class WireFixtureUsageReporter implements ReportsUsage
{
    /**
     * @return array<string, array{used: int, limit: int|null}>
     */
    public function forBillable(Model $billable): array
    {
        return [
            'seats' => [
                'used' => 3,
                'limit' => 10,
            ],
        ];
    }
}
