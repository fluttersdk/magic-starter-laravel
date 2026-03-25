<?php

namespace FlutterSdk\MagicStarter\Console;

use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\text;

/**
 * Interactive Artisan command to scaffold Magic Starter into a Laravel application.
 *
 * Publishes configuration and migrations based on the features selected by
 * the user. Supports both interactive prompts and non-interactive CLI options
 * for CI/CD pipelines.
 */
#[AsCommand(name: 'magic-starter:install')]
class InstallCommand extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'magic-starter:install
        {--all : Install all features without prompting}
        {--features=* : Features to install (teams, profile-photos, sessions, social-login, newsletter-subscription, extended-profile, notifications, email-verification)}
        {--uuid : Use UUID primary keys}
        {--no-uuid : Use auto-incrementing integer primary keys}
        {--route-prefix= : Route prefix for package routes}
        {--frontend-url= : Frontend application URL for email links}
        {--force : Overwrite existing files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install Magic Starter configuration and migrations';

    /** @var array<string, string> Feature key → human-readable label for multiselect. */
    private const FEATURE_LABELS = [
        'two-factor-authentication' => 'Two factor authentication',
        'teams' => 'Team management',
        'profile-photos' => 'Profile & team photos',
        'sessions' => 'Session management (device tracking)',
        'social-login' => 'Social login (OAuth via Socialite, routes only)',
        'newsletter-subscription' => 'Newsletter subscription',
        'extended-profile' => 'Extended profile (phone, timezone, locale)',
        'notifications' => 'Notification preferences',
        'guest-auth' => 'Guest authentication (anonymous users)',
        'phone-otp' => 'Phone OTP verification',
        'email-verification' => 'Email verification (send/verify email address)',
        'timezones' => 'Timezone list (paginated, searchable)',
    ];

    /** @var array<string, string> Feature key → Features class method name. */
    private const FEATURE_CONFIG_MAP = [
        'two-factor-authentication' => 'twoFactorAuthentication',
        'teams' => 'teams',
        'profile-photos' => 'profilePhotos',
        'sessions' => 'sessions',
        'social-login' => 'socialLogin',
        'newsletter-subscription' => 'newsletterSubscription',
        'extended-profile' => 'extendedProfile',
        'notifications' => 'notifications',
        'guest-auth' => 'guestAuth',
        'phone-otp' => 'phoneOtp',
        'email-verification' => 'emailVerification',
        'timezones' => 'timezones',
    ];

    /** @var list<string> Migrations always published regardless of feature selection. */
    private const CORE_MIGRATIONS = [
        'create_users_table.php',
        'create_personal_access_tokens_table.php',
        'add_two_factor_columns_to_users_table.php',
    ];

    /** @var array<string, list<string>> Feature key → associated migration files. */
    private const FEATURE_MIGRATIONS = [
        'teams' => [
            'create_teams_table.php',
            'create_team_user_table.php',
            'create_team_invitations_table.php',
            'add_current_team_id_to_users_table.php',
            'add_expires_at_to_team_invitations_table.php',
            'add_guest_and_phone_fields_to_users_table.php',
        ],
        'profile-photos' => [
            'add_profile_photo_path_to_users_table.php',
        ],
        'sessions' => [
            'add_device_info_to_personal_access_tokens_table.php',
        ],
        'extended-profile' => [
            'add_localization_fields_to_users_table.php',
            'add_profile_fields_to_users_table.php',
            'drop_language_column_from_users_table.php',
        ],
        'notifications' => [
            'create_notifications_table.php',
            'create_notification_settings_table.php',
        ],
        'newsletter-subscription' => [
            'create_newsletter_subscribers_table.php',
        ],
        'timezones' => [
            'add_timezone_to_users_table.php',
        ],
    ];

    /** @var array<string, list<string>> Migrations requiring ALL listed features to be selected. */
    private const CONDITIONAL_MIGRATIONS = [
        'add_profile_photo_path_to_teams_table.php' => [
            'profile-photos',
            'teams',
        ],
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // 1. Display installation banner.
        $this->components->info('Installing Magic Starter...');
        $this->newLine();

        // 2. Resolve primary key strategy (UUID vs integer).
        $useUuids = $this->resolveUuids();

        // 3. Resolve which features to enable.
        $features = $this->resolveFeatures();

        // 4. Resolve route prefix for package routes.
        $routePrefix = $this->resolveRoutePrefix();

        // 5. Resolve frontend URL for email links.
        $frontendUrl = $this->resolveFrontendUrl();

        // 6. Publish and configure the config file.
        $this->publishConfig($features, $routePrefix, $frontendUrl, $useUuids);
        $this->components->twoColumnDetail('Publishing configuration', '<fg=green;options=bold>DONE</>');

        // 7. Replace default Laravel users migration if present.
        $this->replaceDefaultUsersMigration();

        // 8. Publish feature-relevant migrations.
        $migrationCount = $this->publishMigrations($features);
        $this->components->twoColumnDetail(
            "Publishing migrations ({$migrationCount} files)",
            '<fg=green;options=bold>DONE</>',
        );

        // 9. Publish model stubs when teams feature is selected.
        if (in_array('teams', $features, true)) {
            $this->publishModelStubs();
            $this->components->twoColumnDetail('Publishing model stubs', '<fg=green;options=bold>DONE</>');

            $this->publishPolicyStubs();
            $this->components->twoColumnDetail('Publishing policy stubs', '<fg=green;options=bold>DONE</>');
        }

        // 10. Publish user model stub.
        $this->publishUserModelStub();
        $this->components->twoColumnDetail('Publishing user model stub', '<fg=green;options=bold>DONE</>');

        // 11. Publish language files.
        $this->publishLangFiles();
        $this->components->twoColumnDetail('Publishing language files', '<fg=green;options=bold>DONE</>');

        // 12. Publish factory stub.
        $this->publishFactoryStub();
        $this->components->twoColumnDetail('Publishing factory stub', '<fg=green;options=bold>DONE</>');

        $this->newLine();

        // 13. Optionally run database migrations.
        $this->promptToRunMigrations();

        // 14. Display installation summary.
        $this->displaySummary(
            $features,
            $routePrefix,
            $frontendUrl,
            $useUuids,
            $migrationCount,
        );

        return 0;
    }

    /**
     * Resolve which features to enable via CLI option, interactive prompt, or default.
     *
     * @return list<string>
     */
    private function resolveFeatures(): array
    {
        // --all flag: enable every feature immediately.
        if ((bool) $this->option('all')) {
            return array_keys(self::FEATURE_LABELS);
        }

        $optionFeatures = $this->option('features');

        // Explicit --features provided via CLI.
        if (! empty($optionFeatures)) {
            /** @var array<int, string> $filtered */
            $filtered = array_intersect((array) $optionFeatures, array_keys(self::FEATURE_LABELS));

            return array_values($filtered);
        }

        // Interactive mode: prompt user with multiselect.
        if ($this->isInteractiveMode()) {
            $selected = multiselect(
                label: 'Which features would you like to enable?',
                options: self::FEATURE_LABELS,
                default: array_keys(self::FEATURE_LABELS),
                hint: 'Use space to toggle, enter to confirm.',
            );

            return array_values($selected);
        }

        // Non-interactive fallback: enable all features (backward compatible).
        return array_keys(self::FEATURE_LABELS);
    }

    /**
     * Resolve the primary key strategy via CLI option, interactive prompt, or auto-detection.
     *
     * When no CLI flag is provided, auto-detects by checking if the `users` table
     * already exists with a UUID-compatible primary key. Falls back to UUID (true)
     * when no table exists (fresh install).
     */
    private function resolveUuids(): bool
    {
        // Explicit CLI flags take precedence.
        if ((bool) $this->option('uuid')) {
            return true;
        }

        if ((bool) $this->option('no-uuid')) {
            return false;
        }

        // Auto-detect from existing schema when no flag provided.
        try {
            $schema = $this->laravel['db']->getSchemaBuilder();

            if ($schema->hasTable('users')) {
                $columns = $schema->getColumns('users');

                foreach ($columns as $column) {
                    if ($column['name'] === 'id') {
                        // UUID columns are typically char(36) or uuid type.
                        $isUuid = in_array($column['type_name'], ['uuid', 'char', 'varchar'], true)
                            && ($column['type_name'] === 'uuid' || (isset($column['length']) && $column['length'] >= 36));

                        return $isUuid;
                    }
                }
            }
        } catch (\Throwable) {
            // Schema introspection may fail in tests or without DB — default to UUID.
        }

        // Default: UUID for fresh installs.
        return true;
    }

    /**
     * Resolve the route prefix via CLI option, interactive prompt, or default.
     */
    private function resolveRoutePrefix(): string
    {
        $option = $this->option('route-prefix');

        if ($option !== null) {
            return $option;
        }

        if ($this->isInteractiveMode()) {
            return text(
                label: 'What route prefix should Magic Starter use?',
                placeholder: 'e.g. api/v1',
                default: 'api/v1',
                hint: 'Leave empty for no prefix.',
            );
        }

        return '';
    }

    /**
     * Resolve the frontend URL via CLI option, interactive prompt, or default.
     */
    private function resolveFrontendUrl(): ?string
    {
        $option = $this->option('frontend-url');

        if ($option !== null) {
            return $option;
        }

        if ($this->isInteractiveMode()) {
            $url = text(
                label: 'What is your frontend application URL?',
                placeholder: 'e.g. http://localhost:3000',
                default: 'http://localhost:3000',
                hint: 'Used for password reset links in emails. Leave empty to skip.',
            );

            return $url !== '' ? $url : null;
        }

        return null;
    }

    /**
     * Publish the config file and uncomment selected features.
     *
     * @param  list<string>  $features  Selected feature keys.
     * @param  string  $routePrefix  The route prefix to set.
     * @param  string|null  $frontendUrl  The frontend URL to set.
     * @param  bool  $useUuids  Whether to use UUID primary keys.
     */
    private function publishConfig(
        array $features,
        string $routePrefix,
        ?string $frontendUrl,
        bool $useUuids,
    ): void {
        $publishOptions = [
            '--provider' => MagicStarterServiceProvider::class,
            '--tag' => 'magic-starter-config',
        ];

        if ((bool) $this->option('force')) {
            $publishOptions['--force'] = true;
        }

        $this->callSilently('vendor:publish', $publishOptions);

        $configPath = config_path('magic-starter.php');

        // Uncomment selected features in the published config.
        foreach ($features as $feature) {
            $methodName = self::FEATURE_CONFIG_MAP[$feature] ?? null;

            if ($methodName === null) {
                continue;
            }

            $this->replaceInFile(
                "        // \\FlutterSdk\\MagicStarter\\Features::{$methodName}(),",
                "        \\FlutterSdk\\MagicStarter\\Features::{$methodName}(),",
                $configPath,
            );
        }

        // Set route prefix when provided.
        if ($routePrefix !== '') {
            $this->replaceInFile(
                "'route_prefix' => env('MAGIC_STARTER_ROUTE_PREFIX', ''),",
                "'route_prefix' => env('MAGIC_STARTER_ROUTE_PREFIX', '{$routePrefix}'),",
                $configPath,
            );
        }

        // Set frontend URL when provided.
        if ($frontendUrl !== null && $frontendUrl !== '') {
            $this->replaceInFile(
                "'frontend_url' => env('MAGIC_STARTER_FRONTEND_URL'),",
                "'frontend_url' => env('MAGIC_STARTER_FRONTEND_URL', '{$frontendUrl}'),",
                $configPath,
            );
        }

        // Set UUID strategy in the published config.
        if (! $useUuids) {
            $this->replaceInFile(
                "'use_uuids' => true,",
                "'use_uuids' => false,",
                $configPath,
            );
        }
    }

    /**
     * Publish migrations relevant to the selected features.
     *
     * @param  list<string>  $features  Selected feature keys.
     * @return int Number of migration files published.
     */
    private function publishMigrations(array $features): int
    {
        $files = self::CORE_MIGRATIONS;

        // Add feature-specific migrations.
        foreach ($features as $feature) {
            $files = array_merge($files, self::FEATURE_MIGRATIONS[$feature] ?? []);
        }

        // Add conditional migrations where ALL required features are selected.
        foreach (self::CONDITIONAL_MIGRATIONS as $filename => $requiredFeatures) {
            if (count(array_intersect($requiredFeatures, $features)) === count($requiredFeatures)) {
                $files[] = $filename;
            }
        }

        $files = array_unique($files);

        $published = 0;
        $index = 0;

        foreach ($files as $filename) {
            if ($this->publishMigrationFile($filename, $index)) {
                $published++;
            }

            $index++;
        }

        return $published;
    }

    /**
     * Publish model stubs (Team, TeamUser, TeamInvitation) to App\Models.
     *
     * These stubs extend the package's built-in models, allowing consumers
     * to add custom $fillable, relationships, or factory support.
     */
    private function publishModelStubs(): void
    {
        $publishOptions = [
            '--provider' => MagicStarterServiceProvider::class,
            '--tag' => 'magic-starter-models',
        ];

        if ((bool) $this->option('force')) {
            $publishOptions['--force'] = true;
        }

        $this->callSilently('vendor:publish', $publishOptions);
    }

    /**
     * Prompt the user to run database migrations after publishing.
     */
    private function promptToRunMigrations(): void
    {
        if (! $this->isInteractiveMode()) {
            return;
        }

        if (confirm('Would you like to run your database migrations?', default: false)) {
            $this->call('migrate');
            $this->newLine();
        }
    }

    /**
     * Display a summary of the installation.
     *
     * @param  list<string>  $features  Selected feature keys.
     * @param  string  $routePrefix  The configured route prefix.
     * @param  string|null  $frontendUrl  The configured frontend URL.
     * @param  bool  $useUuids  Whether UUID primary keys are used.
     * @param  int  $migrationCount  Number of migrations published.
     */
    private function displaySummary(
        array $features,
        string $routePrefix,
        ?string $frontendUrl,
        bool $useUuids,
        int $migrationCount,
    ): void {
        $this->components->info('Magic Starter installed successfully.');
        $this->newLine();

        $this->components->twoColumnDetail(
            '<fg=gray>Primary keys</>',
            $useUuids ? 'UUID' : 'Auto-incrementing integer',
        );

        $this->components->twoColumnDetail(
            '<fg=gray>Features</>',
            implode(', ', $features) ?: '<fg=gray>none</>',
        );

        $this->components->twoColumnDetail(
            '<fg=gray>Route prefix</>',
            $routePrefix !== '' ? $routePrefix : '<fg=gray>none</>',
        );

        $this->components->twoColumnDetail(
            '<fg=gray>Frontend URL</>',
            $frontendUrl ?? '<fg=gray>not set</>',
        );

        $this->components->twoColumnDetail(
            '<fg=gray>Migrations</>',
            "{$migrationCount} files",
        );

        $this->newLine();
    }

    /**
     * Publish the User model stub to App\Models.
     */
    private function publishUserModelStub(): void
    {
        $publishOptions = [
            '--provider' => MagicStarterServiceProvider::class,
            '--tag' => 'magic-starter-user-model',
        ];

        if ((bool) $this->option('force')) {
            $publishOptions['--force'] = true;
        }

        $this->callSilently('vendor:publish', $publishOptions);
    }

    /**
     * Publish policy stubs (TeamPolicy) to App\Policies.
     */
    private function publishPolicyStubs(): void
    {
        $publishOptions = [
            '--provider' => MagicStarterServiceProvider::class,
            '--tag' => 'magic-starter-policies',
        ];

        if ((bool) $this->option('force')) {
            $publishOptions['--force'] = true;
        }

        $this->callSilently('vendor:publish', $publishOptions);
    }

    /**
     * Publish package language files to the application's lang directory.
     */
    private function publishLangFiles(): void
    {
        $publishOptions = [
            '--provider' => MagicStarterServiceProvider::class,
            '--tag' => 'magic-starter-lang',
        ];

        if ((bool) $this->option('force')) {
            $publishOptions['--force'] = true;
        }

        $this->callSilently('vendor:publish', $publishOptions);
    }

    /**
     * Publish the UserFactory stub to database/factories.
     */
    private function publishFactoryStub(): void
    {
        $publishOptions = [
            '--provider' => MagicStarterServiceProvider::class,
            '--tag' => 'magic-starter-factories',
        ];

        if ((bool) $this->option('force')) {
            $publishOptions['--force'] = true;
        }

        $this->callSilently('vendor:publish', $publishOptions);
    }

    /**
     * Detect and remove Laravel's default users migration to avoid conflicts.
     *
     * Only removes the file if it contains $table->id() or $table->bigIncrements,
     * indicating it is the unpatched Laravel default (not already using MigrationHelper).
     */
    private function replaceDefaultUsersMigration(): void
    {
        $path = database_path('migrations/0001_01_01_000000_create_users_table.php');

        if (! file_exists($path)) {
            return;
        }

        $content = file_get_contents($path);

        if ($content === false) {
            return;
        }

        // Only replace if this is the default Laravel migration (not already patched).
        if (str_contains($content, '$table->id()') || str_contains($content, '$table->bigIncrements')) {
            (new Filesystem)->delete($path);

            $this->components->twoColumnDetail(
                'Replacing default users migration',
                '<fg=green;options=bold>DONE</>',
            );
        }
    }

    /**
     * Copy a migration file with a timestamp prefix, checking for existing copies.
     *
     * @param  string  $filename  The migration filename (without timestamp prefix).
     * @param  int  $index  Sequence index for ordering within this batch.
     * @return bool True if the file was published, false if skipped.
     */
    private function publishMigrationFile(string $filename, int $index): bool
    {
        // Check for an existing published copy of this migration.
        $existing = glob(database_path("migrations/*_{$filename}")) ?: [];

        if (! empty($existing) && ! (bool) $this->option('force')) {
            return false;
        }

        // Remove existing copies when --force is set.
        if (! empty($existing) && (bool) $this->option('force')) {
            $filesystem = new Filesystem;

            foreach ($existing as $existingFile) {
                $filesystem->delete($existingFile);
            }
        }

        $timestamp = now()->format('Y_m_d');
        $sequence = str_pad((string) ($index + 1), 6, '0', STR_PAD_LEFT);
        $destination = database_path("migrations/{$timestamp}_{$sequence}_{$filename}");

        $source = $this->migrationSourcePath() . "/{$filename}";

        $filesystem = new Filesystem;
        $filesystem->ensureDirectoryExists(database_path('migrations'));
        $filesystem->copy($source, $destination);

        return true;
    }

    /**
     * Replace a string or array of strings within a file.
     *
     * @param  string|array<int, string>  $search  The value(s) to search for.
     * @param  string|array<int, string>  $replace  The replacement value(s).
     * @param  string  $path  Absolute path to the file.
     */
    private function replaceInFile(string|array $search, string|array $replace, string $path): void
    {
        file_put_contents(
            $path,
            str_replace($search, $replace, file_get_contents($path)),
        );
    }

    /**
     * Determine whether the command is running in interactive mode.
     *
     * Returns false when CLI options are provided, when running in unit tests,
     * or when the console is explicitly non-interactive (--no-interaction).
     */
    private function isInteractiveMode(): bool
    {
        if ($this->laravel->runningUnitTests()) {
            return false;
        }

        return $this->input->isInteractive()
            && ! (bool) $this->option('all')
            && empty($this->option('features'))
            && $this->option('route-prefix') === null
            && $this->option('frontend-url') === null;
    }

    /**
     * Get the path to the package's migration source directory.
     */
    private function migrationSourcePath(): string
    {
        return __DIR__ . '/../../database/migrations';
    }
}
