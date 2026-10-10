<?php

namespace FlutterSdk\MagicStarter\Tests\Console;

use Closure;
use FlutterSdk\MagicStarter\Console\BillingDoctorCommand;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Models\Subscription;
use FlutterSdk\MagicStarter\Models\SubscriptionItem;
use FlutterSdk\MagicStarter\Support\RevenueCatProjectClient;
use FlutterSdk\MagicStarter\Support\StripePriceReader;
use FlutterSdk\MagicStarter\Tests\TestCase;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription as CashierSubscription;
use Laravel\Cashier\SubscriptionItem as CashierSubscriptionItem;
use Stripe\Exception\AuthenticationException;

/**
 * The checks an agent runs before and after applying the manifest.
 *
 * Every secret is a sentinel. RevenueCat returns a webhook's signing secret
 * only in the response to a rotate request, so the list fakes carry none; the
 * one fake that does is in the no-secret test, proving that a remote object
 * holding the secret is never passed through to the output.
 */
class BillingDoctorCommandTest extends TestCase
{
    private const PROJECT = 'proj_test';

    /**
     * The test that boots the application as `billing:doctor` itself, over a
     * catalogue that does not validate.
     */
    private const INVALID_CATALOGUE_TEST = 'test_the_doctor_reports_a_catalogue_that_stopped_boot_as_json';

    /**
     * @var list<string>
     */
    private array $argv = [];

    /**
     * The four product keys, in catalogue order.
     *
     * @var list<string>
     */
    private const KEYS = [
        'pro_monthly',
        'pro_annual',
        'business_monthly',
        'business_annual',
    ];

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set([
            'app.url' => 'https://app.example.test',
            'magic-starter.use_uuids' => true,
            'magic-starter.features' => [Features::billing()],
            'magic-starter.billing' => BillingManifestCommandTest::billing(),
            ...BillingManifestCommandTest::SECRETS,
        ]);

        if ($this->name() === self::INVALID_CATALOGUE_TEST) {
            $app['config']->set('magic-starter.billing.plans', []);
        }
    }

    protected function setUp(): void
    {
        // The provider decides at boot whether this process is the doctor, so
        // the command line has to say so before the application exists.
        $this->argv = $_SERVER['argv'] ?? [];

        if ($this->name() === self::INVALID_CATALOGUE_TEST) {
            $_SERVER['argv'] = ['artisan', BillingDoctorCommand::NAME, '--json'];
        }

        parent::setUp();

        Sleep::fake();
        $this->app->instance(StripePriceReader::class, $this->stripe($this->stripePrices()));

        // The doctor reports a missing audit table as an error, so a run that is
        // about something else starts from a schema that has it.
        (require __DIR__ . '/../../database/migrations/create_billing_events_table.php')->up();
        (require __DIR__ . '/../../database/migrations/create_billing_grants_table.php')->up();
    }

    public function test_a_complete_configuration_passes_its_local_checks(): void
    {
        $doctor = $this->doctor();

        $this->assertSame(0, $doctor['exit']);
        $this->assertTrue($doctor['report']['ok']);
        $this->assertSame('ok', $doctor['checks']['catalogue.valid']['status']);
        $this->assertSame('ok', $doctor['checks']['revenuecat.hmac_secret']['status']);
        $this->assertSame('ok', $doctor['checks']['stripe.price.pro_annual']['status']);
        $this->assertSame('ok', $doctor['checks']['billable.uuid']['status']);
        $this->assertSame('ok', $doctor['checks']['schema.subscription_keys']['status']);
        $this->assertArrayNotHasKey('revenuecat.remote', $doctor['checks']);
    }

    protected function tearDown(): void
    {
        $_SERVER['argv'] = $this->argv;

        // Statics on Cashier, so they outlive the application this test booted.
        Cashier::useSubscriptionModel(CashierSubscription::class);
        Cashier::useSubscriptionItemModel(CashierSubscriptionItem::class);

        parent::tearDown();
    }

    /**
     * A catalogue that fails validation stops every other process at boot, so
     * the doctor is the one place it can be read back. It has to arrive as the
     * same JSON an agent parses for any other finding, not as a stack trace.
     */
    public function test_the_doctor_reports_a_catalogue_that_stopped_boot_as_json(): void
    {
        $doctor = $this->doctor();

        $this->assertSame(1, $doctor['exit']);
        $this->assertFalse($doctor['report']['ok']);
        $this->assertSame('error', $doctor['checks']['catalogue.valid']['status']);
        $this->assertStringContainsString(
            'magic-starter.billing.plans',
            $doctor['checks']['catalogue.valid']['message'],
        );
    }

    public function test_a_sellable_product_without_a_stripe_price_is_an_error(): void
    {
        config(['magic-starter.billing.products.pro_annual.refs.stripe_price' => null]);

        $doctor = $this->doctor();

        $this->assertSame(1, $doctor['exit']);
        $this->assertFalse($doctor['report']['ok']);
        $this->assertSame('error', $doctor['checks']['stripe.price.pro_annual']['status']);
        $this->assertStringContainsString(
            'CASHIER_PRICE_PRO_ANNUAL',
            $doctor['checks']['stripe.price.pro_annual']['message'],
        );
    }

    /**
     * A product kept only to map an old price has no Stripe price or store id to
     * be asked for: the checks that demand them are for what is sold.
     */
    public function test_a_product_kept_for_mapping_is_not_asked_for_a_price_or_store_ids(): void
    {
        config([
            'magic-starter.billing.products.pro_monthly_2025' => [
                'type' => 'subscription',
                'tier' => 'pro',
                'cycle' => 'monthly',
                'sellable' => false,
                'refs' => ['stripe_price' => null],
            ],
        ]);

        $doctor = $this->doctor();

        $this->assertSame(0, $doctor['exit']);
        $this->assertSame('ok', $doctor['checks']['catalogue.valid']['status']);
        $this->assertArrayNotHasKey('stripe.price.pro_monthly_2025', $doctor['checks']);
        $this->assertArrayNotHasKey('store.pro_monthly_2025', $doctor['checks']);
        $this->assertArrayHasKey('stripe.price.pro_monthly', $doctor['checks']);
        $this->assertArrayHasKey('store.pro_monthly', $doctor['checks']);
    }

    public function test_a_configured_store_rail_without_an_hmac_secret_is_an_error(): void
    {
        config(['magic-starter.billing.revenuecat.webhook_secret' => '']);

        $doctor = $this->doctor();

        $this->assertSame(1, $doctor['exit']);
        $this->assertSame('error', $doctor['checks']['revenuecat.hmac_secret']['status']);
    }

    public function test_a_subscription_on_no_store_is_an_error_while_the_store_rail_is_on(): void
    {
        config([
            'magic-starter.billing.products.pro_monthly.refs.app_store' => null,
            'magic-starter.billing.products.pro_monthly.refs.play' => null,
            'magic-starter.billing.products.pro_annual.refs.play' => null,
        ]);

        $doctor = $this->doctor();

        $this->assertSame('error', $doctor['checks']['store.pro_monthly']['status']);
        $this->assertSame('warning', $doctor['checks']['store.pro_annual']['status']);
        $this->assertSame('ok', $doctor['checks']['store.business_annual']['status']);
    }

    public function test_integer_billable_keys_and_a_daily_store_sweep_are_warnings(): void
    {
        config([
            'magic-starter.use_uuids' => false,
            'magic-starter.billing.reconcile.cadence' => 'daily',
        ]);

        $doctor = $this->doctor();

        $this->assertSame(0, $doctor['exit']);
        $this->assertSame('warning', $doctor['checks']['billable.uuid']['status']);
        $this->assertSame('warning', $doctor['checks']['reconcile.cadence']['status']);
    }

    public function test_remote_agrees_with_a_project_that_matches_the_manifest(): void
    {
        $this->fakeRevenueCat();

        $doctor = $this->doctor(['--remote' => true]);

        $this->assertSame(0, $doctor['exit'], json_encode($doctor['report'], JSON_PRETTY_PRINT) ?: '');
        $this->assertSame('ok', $doctor['checks']['revenuecat.app.play_store']['status']);
        $this->assertSame('ok', $doctor['checks']['revenuecat.product.pro_sub:annual']['status']);
        $this->assertSame('ok', $doctor['checks']['revenuecat.entitlement.business']['status']);
        $this->assertSame('ok', $doctor['checks']['revenuecat.offering.default']['status']);
        $this->assertSame('ok', $doctor['checks']['revenuecat.package.business_annual']['status']);
        $this->assertSame('ok', $doctor['checks']['revenuecat.webhook']['status']);
        $this->assertSame('ok', $doctor['checks']['stripe.remote.price.pro_annual']['status']);
        $this->assertSame('agent_check', $doctor['checks']['app_store.remote']['status']);
        $this->assertStringStartsWith('asc ', $doctor['checks']['app_store.remote']['command']);
        $this->assertSame('agent_check', $doctor['checks']['play.remote']['status']);
        $this->assertStringStartsWith('gplay ', $doctor['checks']['play.remote']['command']);
    }

    public function test_remote_reports_a_package_missing_from_revenuecat(): void
    {
        $this->fakeRevenueCat(packages: ['pro_monthly', 'pro_annual', 'business_monthly']);

        $doctor = $this->doctor(['--remote' => true]);

        $this->assertSame(1, $doctor['exit']);
        $this->assertSame('error', $doctor['checks']['revenuecat.package.business_annual']['status']);
        $this->assertSame('ok', $doctor['checks']['revenuecat.package.pro_monthly']['status']);
    }

    public function test_remote_reports_a_webhook_delivering_elsewhere(): void
    {
        $this->fakeRevenueCat(webhookUrl: 'https://elsewhere.example.test/hook');

        $doctor = $this->doctor(['--remote' => true]);

        $this->assertSame(1, $doctor['exit']);
        $this->assertSame('error', $doctor['checks']['revenuecat.webhook']['status']);
    }

    /**
     * RevenueCat returns the signing secret only in the response to a rotate
     * request, so a list read without it says nothing about signing. Calling
     * that an error would fail every correctly signed webhook; the toggle is a
     * dashboard fact a person confirms.
     */
    public function test_remote_leaves_hmac_signing_to_a_person_to_confirm(): void
    {
        $this->fakeRevenueCat();

        $doctor = $this->doctor(['--remote' => true]);

        $this->assertSame(0, $doctor['exit']);
        $this->assertSame('ok', $doctor['checks']['revenuecat.webhook']['status']);
        $this->assertSame('agent_check', $doctor['checks']['revenuecat.webhook.hmac']['status']);
        $this->assertStringContainsString('HMAC', $doctor['checks']['revenuecat.webhook.hmac']['message']);
    }

    public function test_remote_reports_a_lookup_key_resolving_to_another_price(): void
    {
        $this->fakeRevenueCat();
        $prices = $this->stripePrices();
        $prices['pro_annual']['id'] = 'price_somebody_else';
        $prices['business_monthly']['unit_amount'] = 9800;
        unset($prices['business_annual']);
        $this->app->instance(StripePriceReader::class, $this->stripe($prices));

        $doctor = $this->doctor(['--remote' => true]);

        $this->assertSame(1, $doctor['exit']);
        $this->assertSame('error', $doctor['checks']['stripe.remote.price.pro_annual']['status']);
        $this->assertSame('error', $doctor['checks']['stripe.remote.price.business_monthly']['status']);
        $this->assertSame('error', $doctor['checks']['stripe.remote.price.business_annual']['status']);
        $this->assertSame('ok', $doctor['checks']['stripe.remote.price.pro_monthly']['status']);
    }

    public function test_remote_without_a_v2_key_is_an_error_naming_the_variable(): void
    {
        config(['magic-starter.billing.revenuecat.api_v2_key' => null]);
        Http::fake();

        $doctor = $this->doctor(['--remote' => true]);

        $this->assertSame(1, $doctor['exit']);
        $this->assertSame('error', $doctor['checks']['revenuecat.remote']['status']);
        $this->assertStringContainsString('REVENUECAT_API_V2_KEY', $doctor['checks']['revenuecat.remote']['message']);
        Http::assertNothingSent();
    }

    public function test_remote_only_ever_reads(): void
    {
        $this->fakeRevenueCat();

        $this->doctor(['--remote' => true]);

        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    }

    public function test_no_secret_reaches_any_output(): void
    {
        $outputs = [];

        $secrets = BillingManifestCommandTest::SECRETS;
        $this->fakeRevenueCat(webhookFields: [
            'signing_secret' => $secrets['magic-starter.billing.revenuecat.webhook_secret'],
        ]);
        Artisan::call(BillingDoctorCommand::NAME, ['--remote' => true, '--json' => true]);
        $outputs[] = Artisan::output();
        Artisan::call(BillingDoctorCommand::NAME, ['--remote' => true]);
        $outputs[] = Artisan::output();
        Artisan::call(BillingDoctorCommand::NAME, ['--json' => true]);
        $outputs[] = Artisan::output();
        Artisan::call(BillingDoctorCommand::NAME);
        $outputs[] = Artisan::output();

        // A failing RevenueCat whose error body echoes the secret: Laravel's
        // RequestException message carries the body, so passing it through
        // would print what RevenueCat printed.
        Http::swap(new Factory);
        Http::fake([
            '*' => Http::response([
                'message' => 'broken',
                'webhook_secret' => $secrets['magic-starter.billing.revenuecat.webhook_secret'],
                'key' => $secrets['magic-starter.billing.revenuecat.api_v2_key'],
            ], 400),
        ]);
        Artisan::call(BillingDoctorCommand::NAME, ['--remote' => true, '--json' => true]);
        $outputs[] = Artisan::output();
        Artisan::call(BillingDoctorCommand::NAME, ['--remote' => true]);
        $outputs[] = Artisan::output();

        $this->assertStringContainsString('revenuecat.remote', $outputs[4]);

        foreach ($outputs as $output) {
            foreach (BillingManifestCommandTest::SECRETS as $secret) {
                $this->assertStringNotContainsString($secret, $output);
            }
        }
    }

    public function test_the_human_report_names_each_failing_check(): void
    {
        config(['magic-starter.billing.products.pro_annual.refs.stripe_price' => null]);

        $this->artisan(BillingDoctorCommand::NAME)
            ->expectsOutputToContain('stripe.price.pro_annual')
            ->assertExitCode(1);
    }

    public function test_the_human_report_labels_a_warning_without_failing(): void
    {
        config(['magic-starter.use_uuids' => false]);

        $this->artisan(BillingDoctorCommand::NAME)
            ->expectsOutputToContain('WARNING')
            ->assertExitCode(0);
    }

    /**
     * Cashier's own bigint tables under the package's UUID-keyed models are the
     * mismatch that 500s every Stripe webhook, and the finding names the switch
     * that fixes it.
     */
    public function test_cashier_tables_under_uuid_keyed_package_models_are_an_error_naming_the_switch(): void
    {
        Cashier::useSubscriptionModel(Subscription::class);
        Cashier::useSubscriptionItemModel(SubscriptionItem::class);
        $this->createSubscriptionTables(uuid: false);

        $doctor = $this->doctor();

        $this->assertSame(1, $doctor['exit']);
        $this->assertSame('error', $doctor['checks']['schema.subscription_keys']['status']);
        $this->assertStringContainsString('subscriptions.id', $doctor['checks']['schema.subscription_keys']['message']);
        $this->assertStringContainsString(
            'subscription_items.id',
            $doctor['checks']['schema.subscription_keys']['message'],
        );
        $this->assertStringContainsString(
            'MAGIC_STARTER_PACKAGE_SUBSCRIPTION_MODELS=false',
            $doctor['checks']['schema.subscription_keys']['message'],
        );
    }

    /**
     * The same tables under Cashier's own models, which is what the switch
     * leaves in place, pass.
     */
    public function test_cashier_tables_under_cashiers_own_models_pass(): void
    {
        Cashier::useSubscriptionModel(CashierSubscription::class);
        Cashier::useSubscriptionItemModel(CashierSubscriptionItem::class);
        $this->createSubscriptionTables(uuid: false);

        $doctor = $this->doctor();

        $this->assertSame(0, $doctor['exit']);
        $this->assertSame('ok', $doctor['checks']['schema.subscription_keys']['status']);
    }

    /**
     * The reverse: the package's UUID tables under an integer-keyed model, which
     * is the switch turned off on the wrong application.
     */
    public function test_uuid_tables_under_integer_keyed_models_are_an_error_naming_use_uuids(): void
    {
        Cashier::useSubscriptionModel(CashierSubscription::class);
        Cashier::useSubscriptionItemModel(CashierSubscriptionItem::class);
        $this->createSubscriptionTables(uuid: true);

        $doctor = $this->doctor();

        $this->assertSame(1, $doctor['exit']);
        $this->assertSame('error', $doctor['checks']['schema.subscription_keys']['status']);
        $this->assertStringContainsString(
            'magic-starter.use_uuids',
            $doctor['checks']['schema.subscription_keys']['message'],
        );
    }

    /**
     * A catalogue that offers a trial needs the `billing_trials` table, because
     * checkout and the plans endpoint read it for every trial product; without
     * it both fail on a missing table. Reported as an error naming the
     * migration, and as fine once the table exists.
     */
    public function test_a_trial_product_without_the_billing_trials_table_is_an_error(): void
    {
        config(['magic-starter.billing.products.pro_monthly.trial_days' => 14]);

        $doctor = $this->doctor();

        $this->assertSame(1, $doctor['exit']);
        $this->assertSame('error', $doctor['checks']['schema.billing_trials']['status']);
        $this->assertStringContainsString('billing_trials', $doctor['checks']['schema.billing_trials']['message']);
        $this->assertStringContainsString('pro_monthly', $doctor['checks']['schema.billing_trials']['message']);

        // The disarming limb: the same catalogue with the table present.
        Schema::create('billing_trials', function (Blueprint $table): void {
            $table->uuid('id')->primary();
        });

        $doctor = $this->doctor();

        $this->assertSame(0, $doctor['exit']);
        $this->assertSame('ok', $doctor['checks']['schema.billing_trials']['status']);
    }

    /**
     * The history table is written by every billing outcome, and a recorder
     * that finds it missing logs one warning and skips the row, so a deployment
     * that never published the migration loses its audit trail without a
     * failure anywhere. Reported as an error naming the migration, and as fine
     * once the table exists.
     */
    public function test_a_missing_billing_events_table_is_an_error(): void
    {
        Schema::drop('billing_events');

        $doctor = $this->doctor();

        $this->assertSame(1, $doctor['exit']);
        $this->assertSame('error', $doctor['checks']['schema.billing_events']['status']);
        $this->assertStringContainsString(
            'create_billing_events_table.php',
            $doctor['checks']['schema.billing_events']['message'],
        );

        // The disarming limb: the same configuration with the table present.
        (require __DIR__ . '/../../database/migrations/create_billing_events_table.php')->up();

        $doctor = $this->doctor();

        $this->assertSame(0, $doctor['exit']);
        $this->assertSame('ok', $doctor['checks']['schema.billing_events']['status']);
    }

    /**
     * A grant the panel cannot record is a grant nobody can end, so the missing
     * table is an error naming the migration, and fine once it exists.
     */
    public function test_a_missing_billing_grants_table_is_an_error(): void
    {
        Schema::drop('billing_grants');

        $doctor = $this->doctor();

        $this->assertSame(1, $doctor['exit']);
        $this->assertSame('error', $doctor['checks']['schema.billing_grants']['status']);
        $this->assertStringContainsString(
            'create_billing_grants_table.php',
            $doctor['checks']['schema.billing_grants']['message'],
        );

        (require __DIR__ . '/../../database/migrations/create_billing_grants_table.php')->up();

        $doctor = $this->doctor();

        $this->assertSame(0, $doctor['exit']);
        $this->assertSame('ok', $doctor['checks']['schema.billing_grants']['status']);
    }

    public function test_an_unreadable_schema_leaves_the_billing_grants_check_a_warning(): void
    {
        config([
            'database.connections.unreachable' => [
                'driver' => 'sqlite',
                'database' => '/nonexistent/magic-starter-doctor.sqlite',
                'prefix' => '',
            ],
            'database.default' => 'unreachable',
        ]);

        $doctor = $this->doctor();

        $this->assertSame('warning', $doctor['checks']['schema.billing_grants']['status']);
        $this->assertStringNotContainsString('/nonexistent', $doctor['checks']['schema.billing_grants']['message']);
    }

    public function test_an_empty_sandbox_allowlist_is_not_reported(): void
    {
        $this->assertArrayNotHasKey('revenuecat.sandbox_allowlist', $this->doctor()['checks']);
    }

    public function test_a_sandbox_allowlist_is_reported_by_count_and_never_by_id(): void
    {
        config(['magic-starter.billing.revenuecat.sandbox_app_user_ids' => ['team-secret-1', 'team-secret-2']]);

        $doctor = $this->doctor();

        $this->assertSame(0, $doctor['exit']);
        $check = $doctor['checks']['revenuecat.sandbox_allowlist'];
        $this->assertSame('ok', $check['status']);
        $this->assertStringContainsString('2', $check['message']);
        $this->assertStringNotContainsString('team-secret', $check['message']);
    }

    /**
     * With sandbox accepted for everybody the list narrows nothing, so it is a
     * sign somebody expects it to protect something it does not.
     */
    public function test_a_sandbox_allowlist_beside_accept_sandbox_is_a_warning(): void
    {
        config([
            'magic-starter.billing.revenuecat.accept_sandbox' => true,
            'magic-starter.billing.revenuecat.sandbox_app_user_ids' => ['team-1'],
        ]);

        $doctor = $this->doctor();

        $this->assertSame(0, $doctor['exit']);
        $check = $doctor['checks']['revenuecat.sandbox_allowlist'];
        $this->assertSame('warning', $check['status']);
        $this->assertStringContainsString('accept_sandbox', $check['message']);
    }

    public function test_an_unreadable_schema_leaves_the_billing_events_check_a_warning(): void
    {
        config([
            'database.connections.unreachable' => [
                'driver' => 'sqlite',
                'database' => '/nonexistent/magic-starter-doctor.sqlite',
                'prefix' => '',
            ],
            'database.default' => 'unreachable',
        ]);

        $doctor = $this->doctor();

        $this->assertSame(0, $doctor['exit']);
        $this->assertSame('warning', $doctor['checks']['schema.billing_events']['status']);
        $this->assertStringContainsString(
            'could not be looked for',
            $doctor['checks']['schema.billing_events']['message'],
        );
        $this->assertStringNotContainsString('/nonexistent', $doctor['checks']['schema.billing_events']['message']);
    }

    /**
     * A catalogue that offers no trial never reads the table, so its absence is
     * not a finding at all: an adopter who sells without trials is not asked to
     * run a migration nothing uses.
     */
    public function test_a_catalogue_without_a_trial_is_silent_about_the_billing_trials_table(): void
    {
        $this->assertFalse(Schema::hasTable('billing_trials'));

        $doctor = $this->doctor();

        $this->assertSame(0, $doctor['exit']);
        $this->assertArrayNotHasKey('schema.billing_trials', $doctor['checks']);
    }

    /**
     * A database the doctor cannot reach is a warning that says the comparison
     * did not happen, not a stack trace in place of the JSON an agent parses.
     */
    public function test_an_unreadable_schema_is_a_warning_not_a_crash(): void
    {
        config([
            'database.connections.unreachable' => [
                'driver' => 'sqlite',
                'database' => '/nonexistent/magic-starter-doctor.sqlite',
                'prefix' => '',
            ],
            'database.default' => 'unreachable',
        ]);

        $doctor = $this->doctor();

        $this->assertSame(0, $doctor['exit']);
        $this->assertSame('warning', $doctor['checks']['schema.subscription_keys']['status']);
        $this->assertStringContainsString(
            'could not be read',
            $doctor['checks']['schema.subscription_keys']['message'],
        );
    }

    /**
     * With a trial product configured, an unreachable database leaves the
     * trials table unknowable: a warning naming what was not checked, by
     * exception class alone, never the connection's path.
     */
    public function test_an_unreadable_schema_leaves_the_trials_check_a_warning(): void
    {
        config([
            'magic-starter.billing.products.pro_monthly.trial_days' => 14,
            'database.connections.unreachable' => [
                'driver' => 'sqlite',
                'database' => '/nonexistent/magic-starter-doctor.sqlite',
                'prefix' => '',
            ],
            'database.default' => 'unreachable',
        ]);

        $doctor = $this->doctor();

        $this->assertSame(0, $doctor['exit']);
        $this->assertSame('warning', $doctor['checks']['schema.billing_trials']['status']);
        $this->assertStringContainsString(
            'could not be looked for',
            $doctor['checks']['schema.billing_trials']['message'],
        );
        $this->assertStringContainsString(
            'trial eligibility was not checked',
            $doctor['checks']['schema.billing_trials']['message'],
        );
        $this->assertStringNotContainsString('/nonexistent', $doctor['checks']['schema.billing_trials']['message']);
    }

    /**
     * A rate the catalogue accepts can still leave the store less than one
     * part per million to divide by. The doctor has to name that as a finding
     * an agent can parse, and read nothing that depends on the manifest.
     */
    public function test_a_manifest_that_cannot_be_built_is_reported_as_a_finding(): void
    {
        config(['magic-starter.billing.pricing.commission.rate' => 0.9999999]);

        $doctor = $this->doctor(['--remote' => true]);

        $this->assertSame(1, $doctor['exit']);
        $this->assertSame('ok', $doctor['checks']['catalogue.valid']['status']);
        $this->assertSame('error', $doctor['checks']['manifest.build']['status']);
        $this->assertStringContainsString('(LogicException)', $doctor['checks']['manifest.build']['message']);
        $this->assertStringContainsString(
            'magic-starter.billing.pricing.commission.rate',
            $doctor['checks']['manifest.build']['message'],
        );
        $this->assertSame(['catalogue.valid', 'manifest.build'], array_keys($doctor['checks']));
    }

    /**
     * A web-only deployment never turns the store rail on, so the RevenueCat
     * variables are nobody's requirement and the project is never read.
     */
    public function test_a_web_only_catalogue_asks_nothing_of_revenuecat(): void
    {
        foreach (self::KEYS as $key) {
            config(["magic-starter.billing.products.{$key}.refs" => ['stripe_price' => "price_{$key}"]]);
        }

        config([
            'magic-starter.billing.revenuecat.secret_api_key' => null,
            'magic-starter.billing.revenuecat.webhook_secret' => null,
            'magic-starter.billing.revenuecat.api_v2_key' => null,
            'magic-starter.billing.revenuecat.project_id' => null,
        ]);
        Http::fake();

        $doctor = $this->doctor(['--remote' => true]);

        $this->assertSame(0, $doctor['exit'], json_encode($doctor['report'], JSON_PRETTY_PRINT) ?: '');
        $this->assertSame('ok', $doctor['checks']['env.REVENUECAT_SECRET_API_KEY']['status']);
        $this->assertSame(
            'REVENUECAT_SECRET_API_KEY is not set and nothing needs it.',
            $doctor['checks']['env.REVENUECAT_SECRET_API_KEY']['message'],
        );
        $this->assertSame('ok', $doctor['checks']['env.REVENUECAT_API_V2_KEY']['status']);
        $this->assertSame('The store rail is off.', $doctor['checks']['revenuecat.hmac_secret']['message']);
        $this->assertSame([], $this->checksStartingWith($doctor, 'store.'));
        $this->assertSame([], $this->checksStartingWith($doctor, 'revenuecat.remote'));
        $this->assertArrayNotHasKey('app_store.remote', $doctor['checks']);
        $this->assertArrayNotHasKey('play.remote', $doctor['checks']);
        $this->assertSame('ok', $doctor['checks']['stripe.remote.price.pro_annual']['status']);
        Http::assertNothingSent();
    }

    /**
     * A store-only deployment has no Stripe price to compare, so neither the
     * Stripe secret nor a Stripe read is asked for.
     */
    public function test_a_store_only_catalogue_asks_nothing_of_stripe(): void
    {
        foreach (self::KEYS as $key) {
            $product = BillingManifestCommandTest::billing()['products'][$key];

            config([
                "magic-starter.billing.products.{$key}.prices" => ['app_store' => $product['prices']['web']],
                "magic-starter.billing.products.{$key}.refs.stripe_price" => null,
            ]);
        }

        config([
            'cashier.secret' => null,
            'cashier.webhook.secret' => null,
        ]);
        $this->fakeRevenueCat();
        $stripe = $this->stripe($this->stripePrices());
        $this->app->instance(StripePriceReader::class, $stripe);

        $doctor = $this->doctor(['--remote' => true]);

        $this->assertSame(0, $doctor['exit'], json_encode($doctor['report'], JSON_PRETTY_PRINT) ?: '');
        $this->assertSame(
            'STRIPE_SECRET is not set and nothing needs it.',
            $doctor['checks']['env.STRIPE_SECRET']['message'],
        );
        $this->assertSame([], $this->checksStartingWith($doctor, 'stripe.'));
        $this->assertSame('ok', $doctor['checks']['revenuecat.product.pro_sub:annual']['status']);
        $this->assertSame([], $stripe->reads);
    }

    public function test_remote_without_a_stripe_secret_reports_stripe_unreadable_without_asking(): void
    {
        config(['cashier.secret' => null]);
        $this->fakeRevenueCat();
        $stripe = $this->stripe($this->stripePrices());
        $this->app->instance(StripePriceReader::class, $stripe);

        $doctor = $this->doctor(['--remote' => true]);

        $this->assertSame(1, $doctor['exit']);
        $this->assertSame('error', $doctor['checks']['stripe.remote']['status']);
        $this->assertSame(
            'Stripe cannot be read: STRIPE_SECRET is not set.',
            $doctor['checks']['stripe.remote']['message'],
        );
        $this->assertSame([], $this->checksStartingWith($doctor, 'stripe.remote.price.'));
        $this->assertSame([], $stripe->reads);
    }

    /**
     * Stripe's refusal message can quote the key it was given, so the finding
     * carries the exception's class and status and never its message.
     */
    public function test_remote_reports_a_stripe_refusal_by_class_and_status_only(): void
    {
        $this->fakeRevenueCat();
        $this->app->instance(StripePriceReader::class, new class extends StripePriceReader
        {
            public function byLookupKeys(array $lookupKeys): array
            {
                throw AuthenticationException::factory('Invalid API Key provided: SENTINEL-stripe-secret', 401);
            }
        });

        $doctor = $this->doctor(['--remote' => true]);

        $this->assertSame(1, $doctor['exit']);
        $this->assertSame(
            'Stripe refused the price read (AuthenticationException, HTTP 401).',
            $doctor['checks']['stripe.remote']['message'],
        );
        $this->assertStringNotContainsString(
            'SENTINEL-stripe-secret',
            (string) json_encode($doctor['report']),
        );
        $this->assertSame('ok', $doctor['checks']['revenuecat.webhook']['status']);
    }

    public function test_remote_reports_a_stripe_refusal_without_a_status_as_none(): void
    {
        $this->fakeRevenueCat();
        $this->app->instance(StripePriceReader::class, new class extends StripePriceReader
        {
            public function byLookupKeys(array $lookupKeys): array
            {
                throw AuthenticationException::factory('No API key provided.');
            }
        });

        $doctor = $this->doctor(['--remote' => true]);

        $this->assertSame(
            'Stripe refused the price read (AuthenticationException, HTTP none).',
            $doctor['checks']['stripe.remote']['message'],
        );
    }

    public function test_remote_reports_a_price_billed_on_another_interval(): void
    {
        $this->fakeRevenueCat();
        $prices = $this->stripePrices();
        $prices['pro_annual']['interval'] = 'month';
        $prices['business_annual']['interval'] = null;
        $this->app->instance(StripePriceReader::class, $this->stripe($prices));

        $doctor = $this->doctor(['--remote' => true]);

        $this->assertSame(1, $doctor['exit']);
        $this->assertSame(
            'Lookup key [pro_annual]: interval is [month], expected [year].',
            $doctor['checks']['stripe.remote.price.pro_annual']['message'],
        );
        $this->assertSame(
            'Lookup key [business_annual]: interval is [none], expected [year].',
            $doctor['checks']['stripe.remote.price.business_annual']['message'],
        );
    }

    public function test_remote_reports_a_revenuecat_it_cannot_reach(): void
    {
        Http::fake(['*' => Http::failedConnection()]);

        $doctor = $this->doctor(['--remote' => true]);

        $this->assertSame(1, $doctor['exit']);
        $this->assertSame('error', $doctor['checks']['revenuecat.remote']['status']);
        $this->assertSame('RevenueCat could not be reached.', $doctor['checks']['revenuecat.remote']['message']);
        $this->assertSame([], $this->checksStartingWith($doctor, 'revenuecat.app.'));
    }

    /**
     * Without a Play app, every Play product is filed under an app the
     * manifest does not name, which is its own finding per product.
     */
    public function test_remote_reports_a_missing_store_app_and_the_products_it_strands(): void
    {
        $this->fakeRevenueCat(tamper: static function (array $project): array {
            $project['apps'] = [$project['apps'][0]];

            return $project;
        });

        $doctor = $this->doctor(['--remote' => true]);

        $this->assertSame(1, $doctor['exit']);
        $this->assertSame('ok', $doctor['checks']['revenuecat.app.app_store']['status']);
        $this->assertSame(
            'The project has no [play_store] app.',
            $doctor['checks']['revenuecat.app.play_store']['message'],
        );
        $this->assertSame(
            'Product [pro_sub:annual] is not under a [play_store] app.',
            $doctor['checks']['revenuecat.product.pro_sub:annual']['message'],
        );
        $this->assertSame('ok', $doctor['checks']['revenuecat.product.com.example.pro.annual']['status']);
    }

    public function test_remote_reports_products_and_entitlements_the_project_lacks(): void
    {
        $this->fakeRevenueCat(tamper: static function (array $project): array {
            $project['products'] = array_values(array_filter(
                $project['products'],
                static fn (array $product): bool => $product['store_identifier'] !== 'pro_sub:annual',
            ));
            // Business is gone, and Pro no longer carries its annual Play product.
            $project['entitlements'] = [$project['entitlements'][0]];
            $project['entitlements'][0]['products']['items'] = array_values(array_filter(
                $project['entitlements'][0]['products']['items'],
                static fn (array $product): bool => $product['store_identifier'] !== 'pro_sub:annual',
            ));

            return $project;
        });

        $doctor = $this->doctor(['--remote' => true]);

        $this->assertSame(1, $doctor['exit']);
        $this->assertSame(
            'No product [pro_sub:annual] for [pro_annual].',
            $doctor['checks']['revenuecat.product.pro_sub:annual']['message'],
        );
        $this->assertSame(
            'Entitlement [pro] is missing pro_sub:annual.',
            $doctor['checks']['revenuecat.entitlement.pro']['message'],
        );
        $this->assertSame('No entitlement [business].', $doctor['checks']['revenuecat.entitlement.business']['message']);
    }

    public function test_remote_reports_an_offering_that_is_not_current_and_its_package_drift(): void
    {
        $this->fakeRevenueCat(tamper: static function (array $project): array {
            $project['offerings'][0]['is_current'] = false;
            // Pro monthly moved to the end; Pro annual lost its Play product.
            $project['packages'][0]['position'] = 4;
            $project['packages'][1]['products']['items'] = [$project['packages'][1]['products']['items'][0]];

            return $project;
        });

        $doctor = $this->doctor(['--remote' => true]);

        $this->assertSame(1, $doctor['exit']);
        $this->assertSame(
            'The offering exists but is not the current one.',
            $doctor['checks']['revenuecat.offering.default']['message'],
        );
        $this->assertSame('warning', $doctor['checks']['revenuecat.package.pro_monthly']['status']);
        $this->assertSame(
            'Package [pro_monthly] sits at position [4], expected [1].',
            $doctor['checks']['revenuecat.package.pro_monthly']['message'],
        );
        $this->assertSame('error', $doctor['checks']['revenuecat.package.pro_annual']['status']);
        $this->assertSame(
            'Package [pro_annual] is missing pro_sub:annual.',
            $doctor['checks']['revenuecat.package.pro_annual']['message'],
        );
        $this->assertSame('ok', $doctor['checks']['revenuecat.package.business_annual']['status']);
    }

    public function test_remote_without_the_offering_checks_no_package(): void
    {
        $this->fakeRevenueCat(tamper: static function (array $project): array {
            $project['offerings'] = [];

            return $project;
        });

        $doctor = $this->doctor(['--remote' => true]);

        $this->assertSame(1, $doctor['exit']);
        $this->assertSame(
            'No offering [default]; no package can be checked.',
            $doctor['checks']['revenuecat.offering.default']['message'],
        );
        $this->assertSame([], $this->checksStartingWith($doctor, 'revenuecat.package.'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/packages'));
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{exit: int, report: array<string, mixed>, checks: array<string, array<string, mixed>>}
     */
    private function doctor(array $options = []): array
    {
        $exit = Artisan::call(BillingDoctorCommand::NAME, ['--json' => true, ...$options]);
        $report = json_decode(Artisan::output(), true);

        $this->assertIsArray($report);
        $this->assertSame(1, $report['schema_version']);

        $checks = [];

        foreach ($report['checks'] as $check) {
            $this->assertSame([], array_diff(array_keys($check), ['id', 'status', 'message', 'command']));
            $checks[$check['id']] = $check;
        }

        return [
            'exit' => $exit,
            'report' => $report,
            'checks' => $checks,
        ];
    }

    /**
     * Create the two subscription tables keyed by a UUID or, as Cashier's own
     * migrations key them, by an auto-incrementing bigint.
     */
    private function createSubscriptionTables(bool $uuid): void
    {
        foreach (['subscriptions', 'subscription_items'] as $table) {
            Schema::create($table, function (Blueprint $blueprint) use ($uuid): void {
                $uuid ? $blueprint->uuid('id')->primary() : $blueprint->id();
                $blueprint->timestamps();
            });
        }
    }

    /**
     * The ids of the checks under [$prefix].
     *
     * @param  array{checks: array<string, array<string, mixed>>}  $doctor
     * @return list<string>
     */
    private function checksStartingWith(array $doctor, string $prefix): array
    {
        return array_values(array_filter(
            array_keys($doctor['checks']),
            static fn (string $id): bool => str_starts_with($id, $prefix),
        ));
    }

    /**
     * Fake a RevenueCat project that matches the manifest, minus whatever the
     * arguments take away. [$tamper] receives the project's lists keyed by
     * resource (apps, products, entitlements, offerings, packages, webhooks)
     * and answers the lists to serve.
     *
     * @param  list<string>  $packages
     * @param  array<string, mixed>  $webhookFields  Extra fields on the webhook integration.
     * @param  (Closure(array<string, list<array<string, mixed>>>): array<string, list<array<string, mixed>>>)|null  $tamper
     */
    private function fakeRevenueCat(
        array $packages = self::KEYS,
        string $webhookUrl = 'https://app.example.test/webhooks/revenuecat',
        array $webhookFields = [],
        ?Closure $tamper = null,
    ): void {
        $base = RevenueCatProjectClient::BASE_URL . '/projects/' . self::PROJECT;
        $storeIds = static fn (string $key): array => [
            'com.example.' . str_replace('_', '.', $key),
            str_replace('_', '_sub:', $key),
        ];
        $product = static fn (string $storeId): array => [
            'object' => 'product',
            'id' => 'prod_' . $storeId,
            'store_identifier' => $storeId,
            'type' => 'subscription',
            'app_id' => str_contains($storeId, ':') ? 'app_android' : 'app_ios',
        ];
        $allStoreIds = array_merge(...array_map($storeIds, self::KEYS));

        $project = [
            'apps' => [
                [
                    'id' => 'app_ios',
                    'type' => 'app_store',
                ],
                [
                    'id' => 'app_android',
                    'type' => 'play_store',
                ],
            ],
            'products' => array_map($product, $allStoreIds),
            'entitlements' => array_map(
                static fn (string $tier): array => [
                    'object' => 'entitlement',
                    'id' => "entl_{$tier}",
                    'lookup_key' => $tier,
                    'products' => [
                        'object' => 'list',
                        'items' => array_map(
                            $product,
                            array_merge($storeIds("{$tier}_monthly"), $storeIds("{$tier}_annual")),
                        ),
                    ],
                ],
                ['pro', 'business'],
            ),
            'offerings' => [
                [
                    'object' => 'offering',
                    'id' => 'ofrng_default',
                    'lookup_key' => 'default',
                    'is_current' => true,
                ],
            ],
            'packages' => array_map(
                static fn (string $key): array => [
                    'object' => 'package',
                    'id' => "pkg_{$key}",
                    'lookup_key' => $key,
                    'position' => array_search($key, self::KEYS, true) + 1,
                    'products' => [
                        'object' => 'list',
                        'items' => array_map(
                            static fn (string $storeId): array => [
                                'product' => $product($storeId),
                                'eligibility_criteria' => 'all',
                            ],
                            $storeIds($key),
                        ),
                    ],
                ],
                $packages,
            ),
            'webhooks' => [
                [
                    'object' => 'webhook_integration',
                    'id' => 'wh_1',
                    'url' => $webhookUrl,
                    'environment' => 'production',
                    ...$webhookFields,
                ],
            ],
        ];

        if ($tamper !== null) {
            $project = $tamper($project);
        }

        Http::fake([
            "{$base}/apps*" => $this->page($project['apps']),
            "{$base}/products*" => $this->page($project['products']),
            "{$base}/entitlements*" => $this->page($project['entitlements']),
            "{$base}/offerings/ofrng_default/packages*" => $this->page($project['packages']),
            "{$base}/offerings*" => $this->page($project['offerings']),
            "{$base}/integrations/webhooks*" => $this->page($project['webhooks']),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function page(array $items): PromiseInterface
    {
        return Http::response([
            'object' => 'list',
            'items' => $items,
            'next_page' => null,
        ]);
    }

    /**
     * What Stripe holds when every lookup key resolves to the configured price.
     *
     * @return array<string, array<string, mixed>>
     */
    private function stripePrices(): array
    {
        $prices = [];

        foreach (BillingManifestCommandTest::billing()['products'] as $key => $product) {
            $prices[$key] = [
                'id' => $product['refs']['stripe_price'],
                'currency' => 'usd',
                'unit_amount' => $product['prices']['web']['USD'],
                'currency_options' => [
                    'usd' => $product['prices']['web']['USD'],
                    'try' => $product['prices']['web']['TRY'],
                ],
                'interval' => $product['cycle'] === 'annual' ? 'year' : 'month',
            ];
        }

        return $prices;
    }

    /**
     * @param  array<string, array<string, mixed>>  $prices
     */
    private function stripe(array $prices): StripePriceReader
    {
        return new class($prices) extends StripePriceReader
        {
            /**
             * Every read, by the lookup keys it named.
             *
             * @var list<list<string>>
             */
            public array $reads = [];

            /**
             * @param  array<string, array<string, mixed>>  $prices
             */
            public function __construct(private array $prices) {}

            public function byLookupKeys(array $lookupKeys): array
            {
                $this->reads[] = $lookupKeys;

                return array_intersect_key($this->prices, array_flip($lookupKeys));
            }
        };
    }
}
