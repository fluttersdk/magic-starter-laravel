<?php

namespace FlutterSdk\MagicStarter\Tests\Console;

use FlutterSdk\MagicStarter\Tests\Filament\FilamentTestCase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * `magic-starter:filament:install` writes the panel provider once, registers it
 * once and prints what it leaves to the developer. The application's `app/` and
 * `bootstrap/` are pointed at a scratch directory so the Testbench skeleton is
 * never written to.
 */
final class FilamentInstallCommandTest extends FilamentTestCase
{
    private const PROVIDER = 'App\\Providers\\Filament\\AdminPanelProvider';

    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = sys_get_temp_dir() . '/magic-starter-install-' . bin2hex(random_bytes(4));

        File::ensureDirectoryExists($this->scratch . '/app');
        File::ensureDirectoryExists($this->scratch . '/bootstrap');
        File::put($this->scratch . '/bootstrap/providers.php', "<?php\n\nreturn [\n];\n");

        $this->app->useAppPath($this->scratch . '/app');
        $this->app->useBootstrapPath($this->scratch . '/bootstrap');
    }

    protected function tearDown(): void
    {
        // Unset when setUp skipped the test because Filament is absent.
        if (isset($this->scratch)) {
            File::deleteDirectory($this->scratch);
        }

        parent::tearDown();
    }

    public function test_it_writes_a_panel_provider_that_mounts_the_plugin(): void
    {
        $this->artisan('magic-starter:filament:install')->assertSuccessful();

        $source = File::get($this->providerPath());

        $this->assertStringContainsString('namespace App\Providers\Filament;', $source);
        $this->assertStringContainsString("->id('admin')", $source);
        $this->assertStringContainsString("->path('admin')", $source);
        $this->assertStringContainsString("->domain(config('magic-starter.admin.host'))", $source);
        $this->assertStringContainsString('->login()', $source);
        $this->assertStringContainsString('->plugin(MagicStarterPlugin::make())', $source);
        $this->assertStringNotContainsString('{{', $source);
        $this->assertParses($this->providerPath());
    }

    public function test_the_written_provider_authenticates_the_panel(): void
    {
        $this->artisan('magic-starter:filament:install')->assertSuccessful();

        $this->assertStringContainsString('Authenticate::class', File::get($this->providerPath()));
    }

    public function test_running_twice_registers_the_provider_exactly_once(): void
    {
        $this->artisan('magic-starter:filament:install')->assertSuccessful();
        $this->artisan('magic-starter:filament:install')->assertSuccessful();

        $registered = File::get($this->scratch . '/bootstrap/providers.php');

        $this->assertSame(1, substr_count($registered, 'AdminPanelProvider::class'));
        $this->assertSame([self::PROVIDER], require $this->scratch . '/bootstrap/providers.php');
    }

    public function test_a_provider_the_application_already_lists_is_left_untouched(): void
    {
        $original = "<?php\n\nreturn [\n    App\\Providers\\AppServiceProvider::class,\n"
            . "    App\\Providers\\Filament\\AdminPanelProvider::class,\n];\n";
        File::put($this->scratch . '/bootstrap/providers.php', $original);

        $this->artisan('magic-starter:filament:install')->assertSuccessful();

        $this->assertSame($original, File::get($this->scratch . '/bootstrap/providers.php'));
    }

    public function test_an_existing_provider_is_kept_unless_force_is_given(): void
    {
        File::ensureDirectoryExists(dirname($this->providerPath()));
        File::put($this->providerPath(), '<?php // mine');

        $this->artisan('magic-starter:filament:install')->assertSuccessful();

        $this->assertSame('<?php // mine', File::get($this->providerPath()));

        $this->artisan('magic-starter:filament:install', ['--force' => true])->assertSuccessful();

        $this->assertStringContainsString('MagicStarterPlugin::make()', File::get($this->providerPath()));
    }

    public function test_it_prints_the_user_model_lines_and_the_env_keys(): void
    {
        $this->artisan('magic-starter:filament:install')
            ->expectsOutputToContain('implements FilamentUser')
            ->expectsOutputToContain('use AuthorizesAdminPanel')
            ->expectsOutputToContain('MAGIC_STARTER_ADMIN_EMAILS')
            ->expectsOutputToContain('MAGIC_STARTER_ADMIN_HOST')
            ->expectsOutputToContain('HORIZON_DOMAIN')
            ->expectsOutputToContain('HORIZON_PATH')
            ->expectsOutputToContain('PULSE_DOMAIN')
            ->expectsOutputToContain('PULSE_PATH')
            ->expectsOutputToContain('TELESCOPE_DOMAIN')
            ->expectsOutputToContain('TELESCOPE_PATH')
            ->assertSuccessful();
    }

    public function test_it_never_writes_the_user_model(): void
    {
        $this->artisan('magic-starter:filament:install')->assertSuccessful();

        $this->assertFileDoesNotExist($this->scratch . '/app/Models/User.php');
    }

    public function test_a_missing_bootstrap_providers_file_is_reported_with_the_line_to_add(): void
    {
        File::delete($this->scratch . '/bootstrap/providers.php');

        $this->artisan('magic-starter:filament:install')
            ->expectsOutputToContain('AdminPanelProvider::class')
            ->assertSuccessful();

        $this->assertFileExists($this->providerPath());
    }

    private function providerPath(): string
    {
        return $this->scratch . '/app/Providers/Filament/AdminPanelProvider.php';
    }

    private function assertParses(string $path): void
    {
        $lint = new Process([PHP_BINARY, '-l', $path]);
        $lint->run();

        $this->assertTrue($lint->isSuccessful(), $lint->getOutput() . $lint->getErrorOutput());
    }
}
