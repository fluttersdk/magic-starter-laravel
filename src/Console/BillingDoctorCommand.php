<?php

namespace FlutterSdk\MagicStarter\Console;

use FlutterSdk\MagicStarter\Enums\BillingChannel;
use FlutterSdk\MagicStarter\Models\BillingTrial;
use FlutterSdk\MagicStarter\Support\BillingCatalogue;
use FlutterSdk\MagicStarter\Support\BillingManifest;
use FlutterSdk\MagicStarter\Support\PriceTable;
use FlutterSdk\MagicStarter\Support\RevenueCatProjectClient;
use FlutterSdk\MagicStarter\Support\StoreRailConfiguration;
use FlutterSdk\MagicStarter\Support\StripePriceReader;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Laravel\Cashier\Cashier;
use LogicException;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Throwable;

/**
 * Check that the billing configuration, and with `--remote` the vendors, hold
 * what the manifest says they must.
 *
 * Every finding is a check with a stable id an agent can act on: `ok`,
 * `warning`, `error`, or `agent_check` for vendor state this package cannot read
 * (App Store Connect, Play Console), which names the command to run instead.
 * Any `error` exits 1.
 *
 * NOTHING IS ECHOED FROM A SECRET OR A VENDOR RESPONSE. Every check is
 * assembled here from catalogue ids, lookup keys, store identifiers and HTTP
 * status codes; a secret is reported as set or not set. A remote failure is
 * reported by its status and never by its exception message, because
 * Laravel's RequestException carries the response body, and RevenueCat's
 * rotate response carries the webhook's signing secret.
 *
 * An invalid catalogue reaches this command rather than a stack trace: the
 * billing gate stops boot on it in every other process, and in this one only
 * logs it, so `catalogue.valid` is where it is read back.
 */
class BillingDoctorCommand extends Command
{
    public const NAME = 'billing:doctor';

    public const OK = 'ok';

    public const WARNING = 'warning';

    public const ERROR = 'error';

    public const AGENT_CHECK = 'agent_check';

    /**
     * Integer column types as {@see \Illuminate\Database\Schema\Builder::getColumnType()}
     * names them on SQLite, MySQL, MariaDB, PostgreSQL and SQL Server.
     *
     * @var list<string>
     */
    private const INTEGER_COLUMN_TYPES = [
        'integer',
        'int',
        'bigint',
        'mediumint',
        'smallint',
        'tinyint',
        'int2',
        'int4',
        'int8',
    ];

    /**
     * @var string
     */
    protected $signature = self::NAME . '
        {--json : Print the checks as JSON}
        {--remote : Also read Stripe and RevenueCat and diff them against the manifest}';

    /**
     * @var string
     */
    protected $description = 'Check the billing configuration and, with --remote, the vendor state against it';

    /**
     * @var list<array{id: string, status: string, message: string, command?: string}>
     */
    private array $checks = [];

    public function handle(RevenueCatProjectClient $revenueCat, StripePriceReader $stripe): int
    {
        // Reset per run: Artisan reuses one command instance within a process.
        $this->checks = [];

        // 1. Nothing else can be read from a catalogue that does not validate,
        //    or from a manifest that could not be built from it.
        $manifest = $this->checkCatalogue() ? $this->buildManifest() : null;

        if ($manifest !== null) {
            // 2. What this deployment's own configuration says.
            $this->checkEnvironment($manifest['env']);
            $this->checkHmacSecret();
            $this->checkStripePrices();
            $this->checkStoreIds();
            $this->checkBillableKeys();
            $this->checkSubscriptionKeys();
            $this->checkTrialsTable();
            $this->checkReconcileCadence();

            // 3. What the vendors hold, read and never written.
            if ($this->option('remote')) {
                $this->checkStripeRemote($stripe, $manifest['stripe']['products']);
                $this->checkRevenueCatRemote($revenueCat, $manifest['revenuecat']);
                $this->addAgentChecks();
            }
        }

        return $this->report();
    }

    private function checkCatalogue(): bool
    {
        try {
            BillingCatalogue::validate();
        } catch (LogicException $refusal) {
            // The message is the catalogue's own, built from config keys and
            // product names; it is the finding.
            $this->check('catalogue.valid', self::ERROR, $refusal->getMessage());

            return false;
        }

        $this->check('catalogue.valid', self::OK, 'The catalogue validates.');

        return true;
    }

    /**
     * The manifest, or null with a finding when building it failed.
     *
     * Every check below reads it, and an agent parsing `--json` gets nothing
     * it can act on from a stack trace. The message is safe to print: the
     * manifest is assembled from the catalogue and reads no secret value.
     *
     * @return array<string, mixed>|null
     */
    private function buildManifest(): ?array
    {
        try {
            return BillingManifest::build();
        } catch (Throwable $failure) {
            $this->check('manifest.build', self::ERROR, sprintf(
                'The manifest could not be built (%s): %s',
                class_basename($failure),
                $failure->getMessage(),
            ));

            return null;
        }
    }

    /**
     * @param  array<string, string>  $env  The manifest's `present|absent` map.
     */
    private function checkEnvironment(array $env): void
    {
        $needed = [
            'STRIPE_SECRET' => [$this->sellsOnTheCardRail(), self::ERROR, 'a product sells on the card rail'],
            'STRIPE_WEBHOOK_SECRET' => [$this->sellsOnTheCardRail(), self::ERROR, 'a product sells on the card rail'],
            'REVENUECAT_SECRET_API_KEY' => [
                $this->listsStoreIds(),
                self::WARNING,
                'store ids are listed, but the store rail stays off without it',
            ],
            'REVENUECAT_API_V2_KEY' => [
                StoreRailConfiguration::railIsConfigured(),
                self::WARNING,
                'billing:doctor --remote reads the RevenueCat project with it',
            ],
            'REVENUECAT_PROJECT_ID' => [
                StoreRailConfiguration::railIsConfigured(),
                self::WARNING,
                'billing:doctor --remote reads the RevenueCat project with it',
            ],
        ];

        foreach ($needed as $key => [$required, $severity, $reason]) {
            if (($env[$key] ?? 'absent') === 'present') {
                $this->check("env.{$key}", self::OK, "{$key} is set.");

                continue;
            }

            $required
                ? $this->check("env.{$key}", $severity, "{$key} is not set; {$reason}.")
                : $this->check("env.{$key}", self::OK, "{$key} is not set and nothing needs it.");
        }
    }

    private function checkHmacSecret(): void
    {
        if (StoreRailConfiguration::isMisconfigured()) {
            $this->check(
                'revenuecat.hmac_secret',
                self::ERROR,
                'REVENUECAT_WEBHOOK_SECRET is not set while the store rail is on, so the webhook route is withheld. '
                . 'Enable HMAC signing on the RevenueCat webhook and copy the secret it shows.',
            );

            return;
        }

        $this->check(
            'revenuecat.hmac_secret',
            self::OK,
            StoreRailConfiguration::railIsConfigured()
                ? 'REVENUECAT_WEBHOOK_SECRET is set.'
                : 'The store rail is off.',
        );
    }

    private function checkStripePrices(): void
    {
        foreach ($this->webSellable() as $product) {
            $id = "stripe.price.{$product['key']}";
            $envKey = BillingManifest::stripePriceEnvKey($product['key']);

            $product['refs']['stripe_price'] === null
                ? $this->check($id, self::ERROR, sprintf(
                    'No Stripe price; create one with lookup key [%s] and set %s to its id.',
                    $product['key'],
                    $envKey,
                ))
                : $this->check($id, self::OK, "{$envKey} is set.");
        }
    }

    private function checkStoreIds(): void
    {
        if (! StoreRailConfiguration::railIsConfigured()) {
            return;
        }

        foreach (BillingManifest::subscriptions() as $product) {
            $id = "store.{$product['key']}";
            $missing = array_keys(array_filter(
                [
                    'refs.app_store' => $product['refs'][BillingChannel::APP_STORE->value],
                    'refs.play' => $product['refs'][BillingChannel::PLAY->value],
                ],
                static fn (?string $ref): bool => $ref === null,
            ));

            match (count($missing)) {
                0 => $this->check($id, self::OK, 'Sold on both stores.'),
                1 => $this->check($id, self::WARNING, "No {$missing[0]}; it is not sold on that store."),
                default => $this->check($id, self::ERROR, 'No store id at all while the store rail is on.'),
            };
        }
    }

    private function checkBillableKeys(): void
    {
        if (config('magic-starter.use_uuids')) {
            $this->check('billable.uuid', self::OK, 'Billable keys are UUIDs.');

            return;
        }

        $this->check(
            'billable.uuid',
            self::WARNING,
            'Billable keys are sequential integers. The key is the RevenueCat app_user_id, so it is guessable and '
            . 'collides between environments sharing a project; UUID keys (use_uuids) are recommended.',
        );
    }

    /**
     * Whether each subscription table's key column is the type the model
     * Cashier writes it through mints.
     *
     * The mismatch this exists for is an application whose tables came from
     * Cashier's own migrations (bigint keys) under the package's models with
     * use_uuids on: every subscription write is refused by the database, so the
     * Stripe webhook answers 500 until Stripe gives up. Only the schema is read;
     * a database the doctor cannot reach is reported by exception class alone,
     * since a connection message can carry a host or a user.
     */
    private function checkSubscriptionKeys(): void
    {
        try {
            $mismatches = array_values(array_filter([
                $this->subscriptionKeyMismatch(new Cashier::$subscriptionModel),
                $this->subscriptionKeyMismatch(new Cashier::$subscriptionItemModel),
            ]));
        } catch (Throwable $failure) {
            $this->check('schema.subscription_keys', self::WARNING, sprintf(
                'The subscription tables could not be read (%s), so their keys were not compared with the models.',
                class_basename($failure),
            ));

            return;
        }

        $mismatches === []
            ? $this->check(
                'schema.subscription_keys',
                self::OK,
                'Every subscription table present is keyed the way its model writes it.',
            )
            : $this->check('schema.subscription_keys', self::ERROR, implode(' ', $mismatches));
    }

    /**
     * The finding for one model's table, or null when the table is absent or
     * agrees with the model.
     */
    private function subscriptionKeyMismatch(Model $model): ?string
    {
        $schema = $model->getConnection()->getSchemaBuilder();

        if (! $schema->hasTable($model->getTable())) {
            return null;
        }

        $column = $model->getTable() . '.' . $model->getKeyName();
        $type = strtolower($schema->getColumnType($model->getTable(), $model->getKeyName()));
        $integerColumn = in_array($type, self::INTEGER_COLUMN_TYPES, true);

        if ($integerColumn === $model->getIncrementing()) {
            return null;
        }

        return $integerColumn
            ? "{$column} is an integer column and its model writes a UUID key, so every subscription write fails; "
                . 'the table came from Cashier\'s own migrations, so set MAGIC_STARTER_PACKAGE_SUBSCRIPTION_MODELS=false.'
            : "{$column} is a [{$type}] column and its model expects an auto-incrementing integer, so every "
                . 'subscription write fails; the table came from the package\'s UUID migrations, so turn '
                . 'magic-starter.use_uuids on and leave MAGIC_STARTER_PACKAGE_SUBSCRIPTION_MODELS true.';
    }

    /**
     * Whether the `billing_trials` table exists while some product offers a
     * trial.
     *
     * Checkout and the plans endpoint read the table for every trial product,
     * and without it they offer nobody a trial: the catalogue promises one and
     * every customer is charged on day one, with only a log line to say why.
     * A catalogue offering none never reads it, so its absence
     * is not reported at all: nobody is asked to run a migration nothing uses.
     * An unreachable database is reported by exception class alone, for the
     * reason {@see self::checkSubscriptionKeys()} gives.
     */
    private function checkTrialsTable(): void
    {
        $trialProducts = BillingCatalogue::trialProductKeys();

        if ($trialProducts === []) {
            return;
        }

        $model = new BillingTrial;

        try {
            $exists = $model->getConnection()->getSchemaBuilder()->hasTable($model->getTable());
        } catch (Throwable $failure) {
            $this->check('schema.billing_trials', self::WARNING, sprintf(
                'The %s table could not be looked for (%s), so trial eligibility was not checked.',
                $model->getTable(),
                class_basename($failure),
            ));

            return;
        }

        $exists
            ? $this->check('schema.billing_trials', self::OK, "The {$model->getTable()} table exists.")
            : $this->check('schema.billing_trials', self::ERROR, sprintf(
                'The %s table is missing while [%s] offer a trial, so no trial is offered to anybody; '
                . 'publish the package migration create_billing_trials_table.php and migrate.',
                $model->getTable(),
                implode(', ', $trialProducts),
            ));
    }

    private function checkReconcileCadence(): void
    {
        $cadence = config('magic-starter.billing.reconcile.cadence', 'daily');
        $cadence = is_string($cadence) ? $cadence : 'daily';

        if (StoreRailConfiguration::railIsConfigured() && $cadence === 'daily') {
            $this->check(
                'reconcile.cadence',
                self::WARNING,
                'The reconciler runs daily while the store rail is on; RevenueCat abandons a delivery within about '
                . 'three hours, so set MAGIC_STARTER_BILLING_RECONCILE_CADENCE=hourly.',
            );

            return;
        }

        $this->check('reconcile.cadence', self::OK, "The reconciler runs on [{$cadence}].");
    }

    /**
     * @param  list<array<string, mixed>>  $products  The manifest's `stripe.products`.
     */
    private function checkStripeRemote(StripePriceReader $stripe, array $products): void
    {
        $expected = array_merge([], ...array_column($products, 'prices'));

        if ($expected === []) {
            return;
        }

        if (! $this->isSet('cashier.secret')) {
            $this->check('stripe.remote', self::ERROR, 'Stripe cannot be read: STRIPE_SECRET is not set.');

            return;
        }

        try {
            $remote = $stripe->byLookupKeys(array_column($expected, 'lookup_key'));
        } catch (ApiErrorException $failure) {
            $this->check('stripe.remote', self::ERROR, sprintf(
                'Stripe refused the price read (%s, HTTP %s).',
                class_basename($failure),
                $failure->getHttpStatus() ?? 'none',
            ));

            return;
        }

        foreach ($expected as $price) {
            $this->checkStripePrice($price, $remote[$price['lookup_key']] ?? null);
        }
    }

    /**
     * @param  array<string, mixed>  $expected  One manifest price.
     * @param  array<string, mixed>|null  $remote  {@see StripePriceReader::byLookupKeys()} entry.
     */
    private function checkStripePrice(array $expected, ?array $remote): void
    {
        $key = $expected['lookup_key'];
        $id = "stripe.remote.price.{$key}";

        if ($remote === null) {
            $this->check($id, self::ERROR, "No active Stripe price has lookup key [{$key}].");

            return;
        }

        $problems = [];
        $configured = BillingCatalogue::product($key)['refs']['stripe_price'] ?? null;

        if ($configured !== null && $configured !== $remote['id']) {
            $problems[] = sprintf(
                'lookup key resolves to [%s] while %s holds [%s]',
                $remote['id'],
                $expected['env_key'],
                $configured,
            );
        }

        if ($remote['interval'] !== $expected['recurring']['interval']) {
            $problems[] = sprintf(
                'interval is [%s], expected [%s]',
                $remote['interval'] ?? 'none',
                $expected['recurring']['interval'],
            );
        }

        $amounts = [$expected['currency'] => $expected['unit_amount']];

        foreach ((array) $expected['currency_options'] as $currency => $option) {
            $amounts[$currency] = $option['unit_amount'];
        }

        foreach ($amounts as $currency => $amount) {
            $actual = $currency === $remote['currency']
                ? $remote['unit_amount']
                : ($remote['currency_options'][$currency] ?? null);

            if ($actual !== $amount) {
                $problems[] = sprintf(
                    '%s is [%s], expected [%d]',
                    strtoupper($currency),
                    $actual ?? 'missing',
                    $amount,
                );
            }
        }

        $problems === []
            ? $this->check($id, self::OK, "Lookup key [{$key}] matches.")
            : $this->check($id, self::ERROR, "Lookup key [{$key}]: " . implode('; ', $problems) . '.');
    }

    /**
     * @param  array<string, mixed>  $manifest  The manifest's `revenuecat` section.
     */
    private function checkRevenueCatRemote(RevenueCatProjectClient $client, array $manifest): void
    {
        if (! $this->listsStoreIds() && ! StoreRailConfiguration::railIsConfigured()) {
            return;
        }

        // 1. Read everything first, so a failure is one finding rather than a
        //    half-diffed project.
        try {
            $apps = $client->apps();
            $products = $client->products();
            $entitlements = $client->entitlements();
            $offering = $this->firstWhere($client->offerings(), 'lookup_key', BillingManifest::OFFERING);
            $packages = $offering === null ? [] : $client->packages((string) $offering['id']);
            $webhooks = $client->webhookIntegrations();
        } catch (RequestException $failure) {
            $this->check('revenuecat.remote', self::ERROR, sprintf(
                'RevenueCat answered HTTP %d; check REVENUECAT_API_V2_KEY scopes and REVENUECAT_PROJECT_ID.',
                $failure->response->status(),
            ));

            return;
        } catch (ConnectionException) {
            $this->check('revenuecat.remote', self::ERROR, 'RevenueCat could not be reached.');

            return;
        } catch (RuntimeException $refusal) {
            // The client's own refusal, naming a variable and never a value.
            $this->check('revenuecat.remote', self::ERROR, $refusal->getMessage());

            return;
        }

        // 2. Diff each manifest object against what came back.
        $appTypes = array_column($apps, 'type', 'id');
        $this->diffApps($manifest['products'], $appTypes);
        $this->diffProducts($manifest['products'], $products, $appTypes);
        $this->diffEntitlements($manifest['entitlements'], $entitlements);
        $this->diffOffering($manifest['offering'], $offering, $packages);
        $this->diffWebhook($manifest['webhook']['url'], $webhooks);
    }

    /**
     * @param  list<array<string, mixed>>  $expected
     * @param  array<array-key, mixed>  $appTypes  App id => type.
     */
    private function diffApps(array $expected, array $appTypes): void
    {
        foreach (array_unique(array_column($expected, 'app')) as $type) {
            in_array($type, $appTypes, true)
                ? $this->check("revenuecat.app.{$type}", self::OK, "A [{$type}] app exists.")
                : $this->check("revenuecat.app.{$type}", self::ERROR, "The project has no [{$type}] app.");
        }
    }

    /**
     * @param  list<array<string, mixed>>  $expected
     * @param  list<array<string, mixed>>  $remote
     * @param  array<array-key, mixed>  $appTypes
     */
    private function diffProducts(array $expected, array $remote, array $appTypes): void
    {
        foreach ($expected as $product) {
            $identifier = $product['store_identifier'];
            $id = "revenuecat.product.{$identifier}";
            $found = $this->firstWhere($remote, 'store_identifier', $identifier);

            if ($found === null) {
                $this->check($id, self::ERROR, "No product [{$identifier}] for [{$product['key']}].");

                continue;
            }

            ($appTypes[$found['app_id'] ?? ''] ?? null) === $product['app']
                ? $this->check($id, self::OK, "Product [{$identifier}] exists.")
                : $this->check($id, self::ERROR, "Product [{$identifier}] is not under a [{$product['app']}] app.");
        }
    }

    /**
     * @param  list<array<string, mixed>>  $expected
     * @param  list<array<string, mixed>>  $remote
     */
    private function diffEntitlements(array $expected, array $remote): void
    {
        foreach ($expected as $entitlement) {
            $id = "revenuecat.entitlement.{$entitlement['lookup_key']}";
            $found = $this->firstWhere($remote, 'lookup_key', $entitlement['lookup_key']);

            if ($found === null) {
                $this->check($id, self::ERROR, "No entitlement [{$entitlement['lookup_key']}].");

                continue;
            }

            $attached = array_column($this->listItems($found['products'] ?? null), 'store_identifier');
            $this->checkAttached(
                $id,
                "Entitlement [{$entitlement['lookup_key']}]",
                $entitlement['products'],
                $attached,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $expected  The manifest's `revenuecat.offering`.
     * @param  array<string, mixed>|null  $offering
     * @param  list<array<string, mixed>>  $packages
     */
    private function diffOffering(array $expected, ?array $offering, array $packages): void
    {
        $id = 'revenuecat.offering.' . BillingManifest::OFFERING;

        if ($offering === null) {
            $this->check(
                $id,
                self::ERROR,
                'No offering [' . BillingManifest::OFFERING . ']; no package can be checked.',
            );

            return;
        }

        ($offering['is_current'] ?? false) === true
            ? $this->check($id, self::OK, 'The offering exists and is current.')
            : $this->check($id, self::ERROR, 'The offering exists but is not the current one.');

        foreach ($expected['packages'] as $package) {
            $id = "revenuecat.package.{$package['lookup_key']}";
            $found = $this->firstWhere($packages, 'lookup_key', $package['lookup_key']);

            if ($found === null) {
                $this->check($id, self::ERROR, "No package [{$package['lookup_key']}] in the offering.");

                continue;
            }

            $attached = array_column(
                array_column($this->listItems($found['products'] ?? null), 'product'),
                'store_identifier',
            );

            if (array_diff($package['products'], $attached) !== []) {
                $this->checkAttached($id, "Package [{$package['lookup_key']}]", $package['products'], $attached);

                continue;
            }

            ($found['position'] ?? null) === $package['position']
                ? $this->check($id, self::OK, "Package [{$package['lookup_key']}] carries its products.")
                : $this->check($id, self::WARNING, sprintf(
                    'Package [%s] sits at position [%s], expected [%d].',
                    $package['lookup_key'],
                    is_int($found['position'] ?? null) ? $found['position'] : 'none',
                    $package['position'],
                ));
        }
    }

    /**
     * @param  list<array<string, mixed>>  $webhooks
     */
    private function diffWebhook(string $url, array $webhooks): void
    {
        $found = $this->firstWhere($webhooks, 'url', $url);

        if ($found === null) {
            $this->check('revenuecat.webhook', self::ERROR, "No webhook delivers to [{$url}].");

            return;
        }

        $this->check('revenuecat.webhook', self::OK, "A webhook delivers to [{$url}].");

        // RevenueCat returns the signing secret only in the response to a
        // rotate request, so a list read cannot tell a signed webhook from an
        // unsigned one. A person confirms the toggle; a delivery that is not
        // signed answers 403 at the endpoint in the meantime.
        $this->check(
            'revenuecat.webhook.hmac',
            self::AGENT_CHECK,
            "Confirm HMAC signing is on for the webhook to [{$url}] in the RevenueCat dashboard; "
            . 'the API does not report it.',
        );
    }

    private function addAgentChecks(): void
    {
        if ($this->hasRef(BillingChannel::APP_STORE)) {
            $this->check(
                'app_store.remote',
                self::AGENT_CHECK,
                'App Store Connect is not readable from here; compare its subscriptions with the '
                . "manifest's app_store section.",
                'asc subscriptions groups list --app <app-id>',
            );
        }

        if ($this->hasRef(BillingChannel::PLAY)) {
            $this->check(
                'play.remote',
                self::AGENT_CHECK,
                "Play Console is not readable from here; compare its subscriptions with the manifest's play section.",
                'gplay subscriptions list --package <package-name>',
            );
        }
    }

    /**
     * @param  list<string>  $expected
     * @param  list<mixed>  $attached
     */
    private function checkAttached(string $id, string $subject, array $expected, array $attached): void
    {
        $missing = array_values(array_diff($expected, $attached));

        $missing === []
            ? $this->check($id, self::OK, "{$subject} carries its products.")
            : $this->check($id, self::ERROR, "{$subject} is missing " . implode(', ', $missing) . '.');
    }

    /**
     * Print the checks and answer the exit code.
     */
    private function report(): int
    {
        $failed = in_array(self::ERROR, array_column($this->checks, 'status'), true);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'schema_version' => BillingManifest::SCHEMA_VERSION,
                'ok' => ! $failed,
                'checks' => $this->checks,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            foreach ($this->checks as $check) {
                $this->components->twoColumnDetail($check['id'], $this->label($check['status']));
                $this->line('  ' . $check['message']);

                if (isset($check['command'])) {
                    $this->line("  run: {$check['command']}");
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function label(string $status): string
    {
        return match ($status) {
            self::OK => '<fg=green;options=bold>OK</>',
            self::WARNING => '<fg=yellow;options=bold>WARNING</>',
            self::AGENT_CHECK => '<fg=cyan;options=bold>AGENT CHECK</>',
            default => '<fg=red;options=bold>ERROR</>',
        };
    }

    private function check(string $id, string $status, string $message, ?string $command = null): void
    {
        $check = [
            'id' => $id,
            'status' => $status,
            'message' => $message,
        ];

        if ($command !== null) {
            $check['command'] = $command;
        }

        $this->checks[] = $check;
    }

    /**
     * Subscriptions with a web price: what a web billing screen offers.
     *
     * @return list<array<string, mixed>>
     */
    private function webSellable(): array
    {
        $pricing = BillingCatalogue::pricing();

        return array_values(array_filter(
            BillingManifest::subscriptions(),
            static fn (array $product): bool => $product['refs']['stripe_price'] !== null
                || PriceTable::for($product, BillingChannel::WEB, $pricing) !== [],
        ));
    }

    private function sellsOnTheCardRail(): bool
    {
        return $this->webSellable() !== [];
    }

    private function listsStoreIds(): bool
    {
        return $this->hasRef(BillingChannel::APP_STORE) || $this->hasRef(BillingChannel::PLAY);
    }

    /**
     * Whether any subscription carries an id on [$channel].
     */
    private function hasRef(BillingChannel $channel): bool
    {
        foreach (BillingManifest::subscriptions() as $product) {
            if ($product['refs'][$channel->value] !== null) {
                return true;
            }
        }

        return false;
    }

    private function isSet(string $configKey): bool
    {
        $value = config($configKey);

        return is_string($value) && trim($value) !== '';
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function firstWhere(array $rows, string $field, mixed $value): ?array
    {
        foreach ($rows as $row) {
            if (($row[$field] ?? null) === $value) {
                return $row;
            }
        }

        return null;
    }

    /**
     * The `items` of an embedded RevenueCat list, or none.
     *
     * @return list<array<string, mixed>>
     */
    private function listItems(mixed $list): array
    {
        $items = is_array($list) && is_array($list['items'] ?? null) ? $list['items'] : [];

        return array_values(array_filter($items, 'is_array'));
    }
}
