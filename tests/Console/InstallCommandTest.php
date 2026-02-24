<?php

declare(strict_types=1);

namespace FlutterSdk\MagicStarter\Tests\Console;

use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Support\Facades\File;

final class InstallCommandTest extends TestCase
{
    private array $packageMigrationFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->packageMigrationFiles = array_map(
            static fn (string $path): string => basename($path),
            glob(__DIR__ . '/../../../database/migrations/*.php') ?: [],
        );

        $this->cleanupPublishedArtifacts();
    }

    protected function tearDown(): void
    {
        $this->cleanupPublishedArtifacts();

        parent::tearDown();
    }

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

    private function cleanupPublishedArtifacts(): void
    {
        File::delete(config_path('magic-starter.php'));
        File::deleteDirectory(app_path('Actions/MagicStarter'));

        foreach ($this->packageMigrationFiles as $migrationFile) {
            foreach (glob(database_path('migrations/*_' . $migrationFile)) ?: [] as $publishedMigration) {
                File::delete($publishedMigration);
            }
        }
    }
}
