<?php

namespace FlutterSdk\MagicStarter\Tests\Campaigns;

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Models\Campaign;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;

/**
 * `GET billing/campaign`: the active campaign for the caller's billable, or
 * `{"data": null}`.
 *
 * The window is the clock's, not a write's: the rows are cached, and the cache
 * is filtered by now() on every read, so a campaign starts and ends without
 * anything being saved. A save or a delete is what retires the cached rows.
 */
class CampaignEndpointTest extends TestCase
{
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
            'magic-starter.use_uuids' => true,
            'magic-starter.models.user' => CampaignEndpointUser::class,
            'magic-starter.models.team' => CampaignEndpointTeam::class,
            'magic-starter.models.membership' => ConcreteTeamUser::class,
            'magic-starter.defaults.locale' => 'en',
            'magic-starter.supported_locales' => [
                'en',
                'tr',
            ],
            'magic-starter.billing.tier_order' => [
                'free',
                'pro',
            ],
            'magic-starter.billing.tiers' => [
                'free' => ['name' => 'Free'],
                'pro' => ['name' => 'Pro'],
            ],
            'magic-starter.billing.products' => [
                'pro_monthly' => [
                    'type' => 'subscription',
                    'tier' => 'pro',
                    'cycle' => 'monthly',
                    'refs' => ['stripe_price' => 'price_pro_monthly'],
                ],
                'pro_annual' => [
                    'type' => 'subscription',
                    'tier' => 'pro',
                    'cycle' => 'annual',
                    'refs' => ['stripe_price' => 'price_pro_annual'],
                ],
            ],
            'auth.providers.users' => [
                'driver' => 'eloquent',
                'model' => CampaignEndpointUser::class,
            ],
            // The harness shim the other billing tests carry: Sanctum's token
            // driver is not registered in a Testbench skeleton.
            'auth.guards.sanctum' => [
                'driver' => 'session',
                'provider' => 'users',
            ],
        ]);

        $this->createSchema();

        Carbon::setTestNow(Carbon::parse('2026-11-03 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        MagicStarter::reset();

        parent::tearDown();
    }

    public function test_the_route_is_absent_while_campaigns_are_off(): void
    {
        $this->bootRoutes('team', campaigns: false);

        $owner = $this->ownerOfFreeTeam();
        $this->createCampaign();

        $this->campaignFor($owner)->assertNotFound();
    }

    public function test_a_caller_with_no_current_team_gets_a_404(): void
    {
        $this->bootRoutes('team');

        $user = $this->createUser('no-team@example.test');

        $this->campaignFor($user)->assertNotFound();
    }

    public function test_an_active_campaign_reaches_a_free_owner_as_the_committed_wire_fixture(): void
    {
        $this->bootRoutes('team');

        $owner = $this->ownerOfFreeTeam();
        $this->createCampaign();

        $fixture = json_decode(
            (string) file_get_contents(__DIR__ . '/../Fixtures/wire/billing-campaign.json'),
            true,
        );

        $this->assertSame($fixture, $this->campaignFor($owner)->assertOk()->json());
    }

    public function test_the_clock_starts_and_ends_the_campaign_without_a_write(): void
    {
        $this->bootRoutes('team');

        $owner = $this->ownerOfFreeTeam();
        $this->createCampaign();

        DB::enableQueryLog();

        $this->travelTo(Carbon::parse('2026-10-31 23:59:59'));
        $this->campaignFor($owner)->assertOk()->assertExactJson(['data' => null]);

        $this->travelTo(Carbon::parse('2026-11-01 00:00:00'));
        $this->campaignFor($owner)->assertOk()->assertJsonPath('data.id', 'camp-1');

        $this->travelTo(Carbon::parse('2026-11-07 23:59:59'));
        $this->campaignFor($owner)->assertOk()->assertJsonPath('data.id', 'camp-1');

        $this->travelTo(Carbon::parse('2026-11-08 00:00:00'));
        $this->campaignFor($owner)->assertOk()->assertExactJson(['data' => null]);

        $writes = array_filter(
            DB::getQueryLog(),
            static fn (array $query): bool => preg_match('/^\s*(insert|update|delete)/i', $query['query']) === 1,
        );

        $this->assertSame([], array_values($writes));
    }

    public function test_a_member_who_does_not_own_the_team_gets_null(): void
    {
        $this->bootRoutes('team');

        $owner = $this->ownerOfFreeTeam();
        $team = $owner->currentTeam;
        $member = $this->createUser('member@example.test');
        $team->users()->attach($member->getKey(), ['role' => 'editor']);
        $member->forceFill(['current_team_id' => $team->getKey()])->save();
        $this->createCampaign();

        $this->campaignFor($member)->assertOk()->assertExactJson(['data' => null]);
        $this->campaignFor($owner)->assertOk()->assertJsonPath('data.id', 'camp-1');
    }

    public function test_a_paying_or_trialing_team_gets_null_and_a_lapsed_one_gets_the_campaign(): void
    {
        $this->bootRoutes('team');

        $owner = $this->ownerOfFreeTeam();
        $team = $owner->currentTeam;
        $this->createCampaign();

        $team->forceFill(['plan' => 'pro', 'plan_status' => 'active'])->save();
        $this->campaignFor($owner)->assertOk()->assertExactJson(['data' => null]);

        $team->forceFill(['plan' => 'pro', 'plan_status' => 'trialing'])->save();
        $this->campaignFor($owner)->assertOk()->assertExactJson(['data' => null]);

        // A cancelled plan holds the floor again, so the offer is back.
        $team->forceFill(['plan' => 'pro', 'plan_status' => 'canceled'])->save();
        $this->campaignFor($owner)->assertOk()->assertJsonPath('data.id', 'camp-1');
    }

    public function test_under_the_user_billable_the_caller_is_the_billable_and_sees_the_campaign(): void
    {
        $this->bootRoutes('user');

        $user = $this->createUser('solo@example.test');
        $this->createCampaign();

        $this->campaignFor($user)->assertOk()->assertJsonPath('data.id', 'camp-1');

        $user->forceFill(['plan' => 'pro', 'plan_status' => 'active'])->save();

        $this->campaignFor($user)->assertOk()->assertExactJson(['data' => null]);
    }

    public function test_a_disabled_campaign_is_never_served(): void
    {
        $this->bootRoutes('team');

        $owner = $this->ownerOfFreeTeam();
        $this->createCampaign(['enabled' => false]);

        $this->campaignFor($owner)->assertOk()->assertExactJson(['data' => null]);
    }

    public function test_saving_or_deleting_a_campaign_changes_the_payload_at_once(): void
    {
        $this->bootRoutes('team');

        $owner = $this->ownerOfFreeTeam();
        $campaign = $this->createCampaign();

        $this->campaignFor($owner)->assertJsonPath('data.headline.en', 'Pro is 20% off this week');

        $campaign->forceFill(['headline' => ['en' => 'Last days of the Pro offer']])->save();

        $this->campaignFor($owner)->assertJsonPath('data.headline.en', 'Last days of the Pro offer');

        $campaign->forceFill(['enabled' => false])->save();

        $this->campaignFor($owner)->assertExactJson(['data' => null]);

        $campaign->forceFill(['enabled' => true])->save();
        $this->campaignFor($owner)->assertJsonPath('data.id', 'camp-1');

        $campaign->delete();

        $this->campaignFor($owner)->assertExactJson(['data' => null]);
    }

    /**
     * The rows are read once and then served from the cache: a write that
     * bypasses model events is not seen until a model write retires them.
     */
    public function test_the_rows_are_cached_until_a_model_write(): void
    {
        $this->bootRoutes('team');

        $owner = $this->ownerOfFreeTeam();
        $campaign = $this->createCampaign();

        $this->campaignFor($owner)->assertJsonPath('data.id', 'camp-1');

        DB::table('campaigns')->update(['enabled' => false]);

        $this->campaignFor($owner)->assertJsonPath('data.id', 'camp-1');

        $campaign->refresh()->forceFill(['name' => 'Renamed'])->save();

        $this->campaignFor($owner)->assertExactJson(['data' => null]);
    }

    public function test_a_locale_staff_left_blank_takes_the_default_locales_text(): void
    {
        $this->bootRoutes('team');

        $owner = $this->ownerOfFreeTeam();
        $this->createCampaign([
            'headline' => ['en' => 'English only'],
            'body' => ['en' => 'Body in English'],
        ]);

        $this->campaignFor($owner)
            ->assertJsonPath('data.headline', ['en' => 'English only', 'tr' => 'English only'])
            ->assertJsonPath('data.body', ['en' => 'Body in English', 'tr' => 'Body in English']);
    }

    public function test_an_amount_discount_names_its_currency(): void
    {
        $this->bootRoutes('team');

        $owner = $this->ownerOfFreeTeam();
        $this->createCampaign([
            'discount_type' => 'amount',
            'discount_value' => 500,
            'discount_currency' => 'USD',
        ]);

        $this->campaignFor($owner)->assertJsonPath('data.discount', [
            'type' => 'amount',
            'value' => 500,
            'currency' => 'USD',
        ]);
    }

    private function campaignFor(Model $user): TestResponse
    {
        return $this->actingAs($user->fresh(), 'sanctum')->getJson('/billing/campaign');
    }

    /**
     * The fixture's campaign, keyed `camp-1`, with [$overrides] on top.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function createCampaign(array $overrides = []): Campaign
    {
        $campaign = new Campaign;
        $campaign->forceFill([
            'id' => 'camp-1',
            'name' => 'Pro week',
            'starts_at' => Carbon::parse('2026-11-01 00:00:00'),
            'ends_at' => Carbon::parse('2026-11-08 00:00:00'),
            'enabled' => true,
            'products' => ['pro_monthly', 'pro_annual'],
            'headline' => [
                'en' => 'Pro is 20% off this week',
                'tr' => 'Pro bu hafta %20 indirimli',
            ],
            'body' => [
                'en' => 'Upgrade before Sunday to keep the price.',
                'tr' => 'Fiyatı korumak için pazardan önce yükselt.',
            ],
            'stripe_promotion_code_id' => 'promo_campaign',
            'discount_type' => 'percent',
            'discount_value' => 20,
            'discount_currency' => null,
            ...$overrides,
        ])->save();

        return $campaign;
    }

    private function ownerOfFreeTeam(): CampaignEndpointUser
    {
        $owner = $this->createUser('owner@example.test');

        $team = CampaignEndpointTeam::query()->create([
            'user_id' => $owner->getKey(),
            'name' => 'Free Team',
            'personal_team' => false,
        ]);
        $team->users()->attach($owner->getKey(), ['role' => 'owner']);
        $owner->forceFill(['current_team_id' => $team->getKey()])->save();

        return $owner->fresh();
    }

    private function createUser(string $email): CampaignEndpointUser
    {
        return CampaignEndpointUser::query()->create([
            'name' => 'Campaign Reader',
            'email' => $email,
        ]);
    }

    /**
     * Re-register the package's routes and drop the resolved gate, so the
     * billable and the feature set under test are the ones the routes see.
     */
    private function bootRoutes(string $billable, bool $campaigns = true): void
    {
        config([
            'magic-starter.features' => array_values(array_filter([
                Features::teams(),
                Features::billing(),
                $campaigns ? Features::campaigns() : null,
            ])),
            'magic-starter.billing.billable' => $billable,
        ]);

        $this->app['router']->setRoutes(new RouteCollection);
        $this->app->forgetInstance(GateContract::class);
        Gate::clearResolvedInstance(GateContract::class);

        (new MagicStarterServiceProvider($this->app))->boot();
    }

    private function createSchema(): void
    {
        $schema = app('db.schema');

        $schema->create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->uuid('current_team_id')->nullable();
            $table->string('plan')->nullable();
            $table->string('plan_status')->nullable();
            $table->string('plan_provider')->nullable();
            $table->timestamps();
        });

        $schema->create('teams', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('name');
            $table->boolean('personal_team')->default(false);
            $table->string('plan')->nullable();
            $table->string('plan_status')->nullable();
            $table->string('plan_provider')->nullable();
            $table->timestamps();
        });

        $schema->create('team_user', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->uuid('user_id');
            $table->string('role')->nullable();
            $table->timestamps();
        });

        $this->artisan('migrate', [
            '--path' => __DIR__ . '/../../database/migrations/create_campaigns_table.php',
            '--realpath' => true,
        ]);
    }
}

class CampaignEndpointUser extends Model implements AuthenticatableContract
{
    use AuthenticatableTrait;
    use Authorizable;
    use ConditionallyUsesUuids;
    use HasTeams;

    protected $table = 'users';

    protected $guarded = [];
}

class CampaignEndpointTeam extends Team
{
    protected $table = 'teams';

    /**
     * Emptied because the package's own Team declares a four-key `$fillable`.
     */
    protected $fillable = [];

    protected $guarded = [];
}
