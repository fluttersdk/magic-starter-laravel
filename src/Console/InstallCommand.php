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
 * Publishes configuration, migrations, action stubs, model stubs, and policies
 * based on the features selected by the user. Supports both interactive prompts
 * and non-interactive CLI options for CI/CD pipelines.
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
        {--features=* : Features to install (teams, profile-photos, sessions, social-login, newsletter-subscription, extended-profile, notifications)}
        {--route-prefix= : Route prefix for package routes}
        {--frontend-url= : Frontend application URL for email links}
        {--force : Overwrite existing files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install Magic Starter configuration, migrations, and action stubs';

    /** @var array<string, string> Feature key → human-readable label for multiselect. */
    private const FEATURE_LABELS = [
        'teams' => 'Team management',
        'profile-photos' => 'Profile & team photos',
        'sessions' => 'Session management (device tracking)',
        'social-login' => 'Social login (OAuth via Socialite, routes only)',
        'newsletter-subscription' => 'Newsletter subscription',
        'extended-profile' => 'Extended profile (phone, timezone, language)',
        'notifications' => 'Notification preferences',
    ];

    /** @var array<string, string> Feature key → Features class method name. */
    private const FEATURE_CONFIG_MAP = [
        'teams' => 'teams',
        'profile-photos' => 'profilePhotos',
        'sessions' => 'sessions',
        'social-login' => 'socialLogin',
        'newsletter-subscription' => 'newsletterSubscription',
        'extended-profile' => 'extendedProfile',
        'notifications' => 'notifications',
    ];

    /** @var list<string> Migrations always published regardless of feature selection. */
    private const CORE_MIGRATIONS = [
        'create_users_table.php',
        'create_personal_access_tokens_table.php',
    ];

    /** @var array<string, list<string>> Feature key → associated migration files. */
    private const FEATURE_MIGRATIONS = [
        'teams' => [
            'create_teams_table.php',
            'create_team_user_table.php',
            'create_team_invitations_table.php',
            'add_current_team_id_to_users_table.php',
            'add_expires_at_to_team_invitations_table.php',
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
        ],
        'notifications' => [
            'create_notifications_table.php',
            'create_notification_settings_table.php',
        ],
        'newsletter-subscription' => [
            'create_newsletter_subscribers_table.php',
        ],
    ];

    /** @var array<string, list<string>> Migrations requiring ALL listed features to be selected. */
    private const CONDITIONAL_MIGRATIONS = [
        'add_profile_photo_path_to_teams_table.php' => [
            'profile-photos',
            'teams',
        ],
    ];

    /** @var list<string> Action stubs always published. */
    private const CORE_ACTIONS = [
        'CreateUser.php',
        'UpdateUserProfile.php',
        'UpdateUserPassword.php',
        'DeleteUser.php',
    ];

    /** @var list<string> Action stubs published only when teams feature is selected. */
    private const TEAM_ACTIONS = [
        'AddTeamMember.php',
        'CreateTeam.php',
        'DeleteTeam.php',
        'InviteTeamMember.php',
        'RemoveTeamMember.php',
        'UpdateTeam.php',
        'UpdateTeamMemberRole.php',
    ];

    /** @var list<string> Model stubs published only when teams feature is selected. */
    private const TEAM_MODELS = [
        'Team.php',
        'TeamUser.php',
        'TeamInvitation.php',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // 1. Display installation banner.
        $this->components->info('Installing Magic Starter...');
        $this->newLine();

        // 2. Resolve which features to enable.
        $features = $this->resolveFeatures();

        // 3. Resolve route prefix for package routes.
        $routePrefix = $this->resolveRoutePrefix();

        // 4. Resolve frontend URL for email links.
        $frontendUrl = $this->resolveFrontendUrl();

        // 5. Publish and configure the config file.
        $this->publishConfig($features, $routePrefix, $frontendUrl);
        $this->components->twoColumnDetail('Publishing configuration', '<fg=green;options=bold>DONE</>');

        // 6. Publish feature-relevant migrations.
        $migrationCount = $this->publishMigrations($features);
        $this->components->twoColumnDetail(
            "Publishing migrations ({$migrationCount} files)",
            '<fg=green;options=bold>DONE</>',
        );

        // 7. Publish action stubs.
        $actionCount = $this->publishActions($features);
        $this->components->twoColumnDetail(
            "Publishing actions ({$actionCount} files)",
            '<fg=green;options=bold>DONE</>',
        );

        // 8. Publish TeamPolicy when teams is selected.
        $policyCount = $this->publishPolicy($features);
        if ($policyCount > 0) {
            $this->components->twoColumnDetail(
                'Publishing policy',
                '<fg=green;options=bold>DONE</>',
            );
        }

        // 9. Publish model stubs when teams is selected.
        $modelCount = $this->publishModels($features);
        if ($modelCount > 0) {
            $this->components->twoColumnDetail(
                "Publishing models ({$modelCount} files)",
                '<fg=green;options=bold>DONE</>',
            );
        }

        $this->newLine();

        // 10. Optionally run database migrations.
        $this->promptToRunMigrations();

        // 11. Display installation summary.
        $this->displaySummary(
            $features,
            $routePrefix,
            $frontendUrl,
            $migrationCount,
            $actionCount,
            $policyCount,
            $modelCount,
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
            return array_values(
                array_intersect($optionFeatures, array_keys(self::FEATURE_LABELS)),
            );
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
     */
    private function publishConfig(
        array $features,
        string $routePrefix,
        ?string $frontendUrl,
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
     * Publish action stubs relevant to the selected features.
     *
     * @param  list<string>  $features  Selected feature keys.
     * @return int Number of action files published.
     */
    private function publishActions(array $features): int
    {
        $filesystem = new Filesystem;
        $filesystem->ensureDirectoryExists(app_path('Actions/MagicStarter'));

        $actions = self::CORE_ACTIONS;

        if (in_array('teams', $features, true)) {
            $actions = array_merge($actions, self::TEAM_ACTIONS);
        }

        $published = 0;

        foreach ($actions as $filename) {
            $source = $this->stubSourcePath() . "/actions/{$filename}";
            $destination = app_path("Actions/MagicStarter/{$filename}");

            if ($this->publishFile($source, $destination)) {
                $published++;
            }
        }

        return $published;
    }

    /**
     * Publish the TeamPolicy when teams feature is selected.
     *
     * @param  list<string>  $features  Selected feature keys.
     * @return int Number of policy files published (0 or 1).
     */
    private function publishPolicy(array $features): int
    {
        if (! in_array('teams', $features, true)) {
            return 0;
        }

        $filesystem = new Filesystem;
        $filesystem->ensureDirectoryExists(app_path('Policies'));

        $source = $this->stubSourcePath() . '/actions/TeamPolicy.php';
        $destination = app_path('Policies/TeamPolicy.php');

        return $this->publishFile($source, $destination) ? 1 : 0;
    }

    /**
     * Publish model stubs when teams feature is selected.
     *
     * @param  list<string>  $features  Selected feature keys.
     * @return int Number of model files published.
     */
    private function publishModels(array $features): int
    {
        if (! in_array('teams', $features, true)) {
            return 0;
        }

        $published = 0;

        foreach (self::TEAM_MODELS as $filename) {
            $source = $this->stubSourcePath() . "/models/{$filename}";
            $destination = app_path("Models/{$filename}");

            if ($this->publishFile($source, $destination)) {
                $published++;
            }
        }

        return $published;
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
     * @param  int  $migrationCount  Number of migrations published.
     * @param  int  $actionCount  Number of actions published.
     * @param  int  $policyCount  Number of policies published.
     * @param  int  $modelCount  Number of models published.
     */
    private function displaySummary(
        array $features,
        string $routePrefix,
        ?string $frontendUrl,
        int $migrationCount,
        int $actionCount,
        int $policyCount,
        int $modelCount,
    ): void {
        $this->components->info('Magic Starter installed successfully.');
        $this->newLine();

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

        $this->components->twoColumnDetail(
            '<fg=gray>Actions</>',
            "{$actionCount} files",
        );

        if ($policyCount > 0) {
            $this->components->twoColumnDetail(
                '<fg=gray>Policy</>',
                'TeamPolicy.php',
            );
        }

        if ($modelCount > 0) {
            $this->components->twoColumnDetail(
                '<fg=gray>Models</>',
                "{$modelCount} files",
            );
        }

        $this->newLine();
    }

    /**
     * Copy a file from source to destination, respecting the --force flag.
     *
     * @param  string  $source  Absolute path to the source file.
     * @param  string  $destination  Absolute path to the destination.
     * @return bool True if the file was published, false if skipped.
     */
    private function publishFile(string $source, string $destination): bool
    {
        if (file_exists($destination) && ! (bool) $this->option('force')) {
            return false;
        }

        $filesystem = new Filesystem;
        $filesystem->ensureDirectoryExists(dirname($destination));
        $filesystem->copy($source, $destination);

        return true;
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

    /**
     * Get the path to the package's stub source directory.
     */
    private function stubSourcePath(): string
    {
        return __DIR__ . '/../../stubs';
    }
}
