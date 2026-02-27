<?php

namespace FlutterSdk\MagicStarter\Tests\Console;

use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Support\Facades\File;

final class InstallCommandTest extends TestCase
{
    /** @var list<string> */
    private array $packageMigrationFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->packageMigrationFiles = array_map(
            static fn (string $path): string => basename($path),
            glob(__DIR__ . '/../../database/migrations/*.php') ?: [],
        );

        $this->cleanupPublishedArtifacts();
    }

    protected function tearDown(): void
    {
        $this->cleanupPublishedArtifacts();

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Backward Compatibility Tests (existing behavior)
    // -------------------------------------------------------------------------

    public function test_install_command_publishes_config_migrations_and_actions(): void
    {
        $this->artisan('magic-starter:install')->assertExitCode(0);

        $this->assertFileExists(config_path('magic-starter.php'));

        foreach ($this->packageMigrationFiles as $migrationFile) {
            $this->assertNotEmpty(
                glob(database_path('migrations/*_' . $migrationFile)) ?: [],
                sprintf('Expected migration [%s] to be published.', $migrationFile),
            );
        }

        $expectedActionFiles = [
            'AddTeamMember.php',
            'CreateTeam.php',
            'CreateUser.php',
            'DeleteTeam.php',
            'DeleteUser.php',
            'InviteTeamMember.php',
            'RemoveTeamMember.php',
            'UpdateTeam.php',
            'UpdateTeamMemberRole.php',
            'UpdateUserPassword.php',
            'UpdateUserProfile.php',
        ];

        foreach ($expectedActionFiles as $file) {
            $this->assertFileExists(app_path('Actions/MagicStarter/' . $file));
        }
    }

    public function test_install_command_does_not_overwrite_actions_without_force(): void
    {
        File::ensureDirectoryExists(app_path('Actions/MagicStarter'));
        File::put(app_path('Actions/MagicStarter/CreateUser.php'), 'custom-content');

        $this->artisan('magic-starter:install')->assertExitCode(0);

        $this->assertSame('custom-content', File::get(app_path('Actions/MagicStarter/CreateUser.php')));
    }

    public function test_install_command_overwrites_actions_with_force(): void
    {
        File::ensureDirectoryExists(app_path('Actions/MagicStarter'));
        File::put(app_path('Actions/MagicStarter/CreateUser.php'), 'custom-content');

        $this->artisan('magic-starter:install', ['--force' => true])->assertExitCode(0);

        $this->assertStringContainsString(
            'CreateUser action not implemented',
            File::get(app_path('Actions/MagicStarter/CreateUser.php')),
        );
    }

    public function test_install_command_does_not_run_migrations_or_modify_user_model(): void
    {
        $userModelPath = app_path('Models/User.php');
        $userModelBefore = File::exists($userModelPath)
            ? hash_file('sha256', $userModelPath)
            : null;

        $this->artisan('magic-starter:install')->assertExitCode(0);

        $this->assertFalse(
            $this->app['db']->getSchemaBuilder()->hasTable('migrations'),
            'Install command must not run migrations.',
        );

        if ($userModelBefore !== null) {
            $this->assertSame($userModelBefore, hash_file('sha256', $userModelPath));
        }
    }

    // -------------------------------------------------------------------------
    // Feature Selection Tests
    // -------------------------------------------------------------------------

    public function test_teams_feature_publishes_team_migrations_actions_and_models(): void
    {
        $this->artisan('magic-starter:install', [
            '--features' => ['teams'],
        ])->assertExitCode(0);

        // Team migrations should be published.
        $teamMigrations = [
            'create_teams_table.php',
            'create_team_user_table.php',
            'create_team_invitations_table.php',
            'add_current_team_id_to_users_table.php',
            'add_expires_at_to_team_invitations_table.php',
        ];

        foreach ($teamMigrations as $migration) {
            $this->assertNotEmpty(
                glob(database_path('migrations/*_' . $migration)) ?: [],
                sprintf('Expected team migration [%s] to be published.', $migration),
            );
        }

        // Team actions should be published.
        $teamActions = [
            'AddTeamMember.php',
            'CreateTeam.php',
            'DeleteTeam.php',
            'InviteTeamMember.php',
            'RemoveTeamMember.php',
            'UpdateTeam.php',
            'UpdateTeamMemberRole.php',
        ];

        foreach ($teamActions as $action) {
            $this->assertFileExists(app_path('Actions/MagicStarter/' . $action));
        }

        // Team models should be published.
        $this->assertFileExists(app_path('Models/Team.php'));
        $this->assertFileExists(app_path('Models/TeamUser.php'));
        $this->assertFileExists(app_path('Models/TeamInvitation.php'));

        // TeamPolicy should be published to Policies directory.
        $this->assertFileExists(app_path('Policies/TeamPolicy.php'));
    }

    public function test_without_teams_skips_team_assets(): void
    {
        $this->artisan('magic-starter:install', [
            '--features' => ['sessions'],
        ])->assertExitCode(0);

        // Core migrations should still be published.
        $this->assertNotEmpty(
            glob(database_path('migrations/*_create_users_table.php')) ?: [],
        );

        // Sessions migration should be published.
        $this->assertNotEmpty(
            glob(database_path('migrations/*_add_device_info_to_personal_access_tokens_table.php')) ?: [],
        );

        // Team migrations should NOT be published.
        $this->assertEmpty(
            glob(database_path('migrations/*_create_teams_table.php')) ?: [],
        );

        // Team actions should NOT be published.
        $this->assertFileDoesNotExist(app_path('Actions/MagicStarter/CreateTeam.php'));
        $this->assertFileDoesNotExist(app_path('Actions/MagicStarter/AddTeamMember.php'));

        // Team models should NOT be published.
        $this->assertFileDoesNotExist(app_path('Models/Team.php'));
        $this->assertFileDoesNotExist(app_path('Models/TeamUser.php'));
        $this->assertFileDoesNotExist(app_path('Models/TeamInvitation.php'));

        // TeamPolicy should NOT be published.
        $this->assertFileDoesNotExist(app_path('Policies/TeamPolicy.php'));

        // Core actions should still be published.
        $this->assertFileExists(app_path('Actions/MagicStarter/CreateUser.php'));
        $this->assertFileExists(app_path('Actions/MagicStarter/UpdateUserProfile.php'));
    }

    public function test_config_has_selected_features_uncommented(): void
    {
        $this->artisan('magic-starter:install', [
            '--features' => [
                'teams',
                'sessions',
            ],
        ])->assertExitCode(0);

        $config = File::get(config_path('magic-starter.php'));

        // Selected features should be uncommented.
        $this->assertStringContainsString(
            '\\FlutterSdk\\MagicStarter\\Features::teams(),',
            $config,
        );
        $this->assertStringContainsString(
            '\\FlutterSdk\\MagicStarter\\Features::sessions(),',
            $config,
        );

        // Non-selected features should remain commented.
        $this->assertStringContainsString(
            '// \\FlutterSdk\\MagicStarter\\Features::profilePhotos(),',
            $config,
        );
        $this->assertStringContainsString(
            '// \\FlutterSdk\\MagicStarter\\Features::socialLogin(),',
            $config,
        );
    }

    public function test_conditional_migration_requires_both_features(): void
    {
        // Only profile-photos selected (without teams).
        $this->artisan('magic-starter:install', [
            '--features' => ['profile-photos'],
        ])->assertExitCode(0);

        // User profile photo migration should be published.
        $this->assertNotEmpty(
            glob(database_path('migrations/*_add_profile_photo_path_to_users_table.php')) ?: [],
        );

        // Team profile photo migration should NOT be published (requires both features).
        $this->assertEmpty(
            glob(database_path('migrations/*_add_profile_photo_path_to_teams_table.php')) ?: [],
        );

        $this->cleanupPublishedArtifacts();

        // Both profile-photos and teams selected.
        $this->artisan('magic-starter:install', [
            '--features' => [
                'profile-photos',
                'teams',
            ],
        ])->assertExitCode(0);

        // Now team profile photo migration should be published.
        $this->assertNotEmpty(
            glob(database_path('migrations/*_add_profile_photo_path_to_teams_table.php')) ?: [],
        );
    }

    public function test_route_prefix_and_frontend_url_written_to_config(): void
    {
        $this->artisan('magic-starter:install', [
            '--features' => ['sessions'],
            '--route-prefix' => 'api/v1',
            '--frontend-url' => 'http://localhost:3000',
        ])->assertExitCode(0);

        $config = File::get(config_path('magic-starter.php'));

        $this->assertStringContainsString("'api/v1'", $config);
        $this->assertStringContainsString("'http://localhost:3000'", $config);
    }

    public function test_no_features_selected_publishes_only_core_assets(): void
    {
        $this->artisan('magic-starter:install', [
            '--features' => ['social-login'],
        ])->assertExitCode(0);

        // Core migrations should be published.
        $this->assertNotEmpty(
            glob(database_path('migrations/*_create_users_table.php')) ?: [],
        );
        $this->assertNotEmpty(
            glob(database_path('migrations/*_create_personal_access_tokens_table.php')) ?: [],
        );

        // Core actions should be published.
        $this->assertFileExists(app_path('Actions/MagicStarter/CreateUser.php'));
        $this->assertFileExists(app_path('Actions/MagicStarter/DeleteUser.php'));
        $this->assertFileExists(app_path('Actions/MagicStarter/UpdateUserProfile.php'));
        $this->assertFileExists(app_path('Actions/MagicStarter/UpdateUserPassword.php'));

        // Feature-specific assets should NOT be published (social-login has no migrations/stubs).
        $this->assertEmpty(
            glob(database_path('migrations/*_create_teams_table.php')) ?: [],
        );
        $this->assertFileDoesNotExist(app_path('Actions/MagicStarter/CreateTeam.php'));
        $this->assertFileDoesNotExist(app_path('Models/Team.php'));
    }

    public function test_models_published_to_correct_directory(): void
    {
        $this->artisan('magic-starter:install', [
            '--features' => ['teams'],
        ])->assertExitCode(0);

        $teamModel = File::get(app_path('Models/Team.php'));

        $this->assertStringContainsString('namespace App\Models;', $teamModel);
        $this->assertStringContainsString('extends MagicStarterTeam', $teamModel);
    }

    public function test_force_flag_overwrites_models(): void
    {
        File::ensureDirectoryExists(app_path('Models'));
        File::put(app_path('Models/Team.php'), 'custom-team-content');

        $this->artisan('magic-starter:install', [
            '--features' => ['teams'],
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertStringContainsString(
            'extends MagicStarterTeam',
            File::get(app_path('Models/Team.php')),
        );
    }

    public function test_policy_published_to_policies_directory(): void
    {
        $this->artisan('magic-starter:install', [
            '--features' => ['teams'],
        ])->assertExitCode(0);

        $this->assertFileExists(app_path('Policies/TeamPolicy.php'));

        $policy = File::get(app_path('Policies/TeamPolicy.php'));

        $this->assertStringContainsString('class TeamPolicy', $policy);
    }

    // -------------------------------------------------------------------------
    // UUID / Integer Primary Key Tests
    // -------------------------------------------------------------------------

    public function test_uuid_flag_sets_use_uuids_to_true_in_config(): void
    {
        $this->artisan('magic-starter:install', [
            '--features' => ['sessions'],
            '--uuid' => true,
        ])->assertExitCode(0);

        $config = File::get(config_path('magic-starter.php'));

        $this->assertStringContainsString("'use_uuids' => true,", $config);
    }

    public function test_no_uuid_flag_sets_use_uuids_to_false_in_config(): void
    {
        $this->artisan('magic-starter:install', [
            '--features' => ['sessions'],
            '--no-uuid' => true,
        ])->assertExitCode(0);

        $config = File::get(config_path('magic-starter.php'));

        $this->assertStringContainsString("'use_uuids' => false,", $config);
    }

    public function test_default_install_uses_uuid_primary_keys(): void
    {
        $this->artisan('magic-starter:install', [
            '--features' => ['sessions'],
        ])->assertExitCode(0);

        $config = File::get(config_path('magic-starter.php'));

        // Default (no existing users table) should be UUID.
        $this->assertStringContainsString("'use_uuids' => true,", $config);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function cleanupPublishedArtifacts(): void
    {
        File::delete(config_path('magic-starter.php'));
        File::deleteDirectory(app_path('Actions/MagicStarter'));
        File::deleteDirectory(app_path('Policies'));

        // Clean published model stubs.
        File::delete(app_path('Models/Team.php'));
        File::delete(app_path('Models/TeamUser.php'));
        File::delete(app_path('Models/TeamInvitation.php'));

        foreach ($this->packageMigrationFiles as $migrationFile) {
            // Match both timestamped (2026_02_27_000001_create_users_table.php)
            // and plain copies (create_users_table.php) left by vendor:publish.
            $patterns = [
                database_path('migrations/*_' . $migrationFile),
                database_path('migrations/' . $migrationFile),
            ];

            foreach ($patterns as $pattern) {
                foreach (glob($pattern) ?: [] as $publishedMigration) {
                    File::delete($publishedMigration);
                }
            }
        }
    }
}
