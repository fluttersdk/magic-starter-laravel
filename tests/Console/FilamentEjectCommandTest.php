<?php

namespace FlutterSdk\MagicStarter\Tests\Console;

use FlutterSdk\MagicStarter\Tests\Filament\FilamentTestCase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

/**
 * `magic-starter:filament:eject` copies one resource into the application and
 * rewrites its namespace. The application's `app/` is a scratch directory.
 */
final class FilamentEjectCommandTest extends FilamentTestCase
{
    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = sys_get_temp_dir() . '/magic-starter-eject-' . bin2hex(random_bytes(4));

        File::ensureDirectoryExists($this->scratch . '/app');

        $this->app->useAppPath($this->scratch . '/app');
    }

    protected function tearDown(): void
    {
        // Unset when setUp skipped the test because Filament is absent.
        if (isset($this->scratch)) {
            File::deleteDirectory($this->scratch);
        }

        parent::tearDown();
    }

    public function test_ejecting_users_copies_the_directory_under_the_application_namespace(): void
    {
        $this->artisan('magic-starter:filament:eject', ['resource' => 'Users'])->assertSuccessful();

        $resource = $this->scratch . '/app/Filament/Resources/Users/UserResource.php';

        $this->assertFileExists($resource);
        $this->assertFileExists($this->scratch . '/app/Filament/Resources/Users/Pages/EditUser.php');
        $this->assertFileExists($this->scratch . '/app/Filament/Resources/Users/Schemas/UserForm.php');

        $source = File::get($resource);

        $this->assertStringContainsString("namespace App\\Filament\\Resources\\Users;\n", $source);
        $this->assertStringContainsString('use App\Filament\Resources\Users\Pages\EditUser;', $source);
        $this->assertStringContainsString(
            'use FlutterSdk\MagicStarter\Filament\Resources\MagicStarterResource;',
            $source,
        );
        $this->assertStringNotContainsString('FlutterSdk\MagicStarter\Filament\Resources\Users', $source);
    }

    #[DataProvider('resources')]
    public function test_every_ejected_file_parses_and_leaves_no_package_namespace_behind(
        string $name,
        string $class,
    ): void {
        $this->artisan('magic-starter:filament:eject', ['resource' => $name])->assertSuccessful();

        $directory = $this->scratch . '/app/Filament/Resources/' . $name;

        $this->assertFileExists($directory . '/' . $class . '.php');

        foreach (File::allFiles($directory) as $file) {
            $lint = new Process([PHP_BINARY, '-l', $file->getPathname()]);
            $lint->run();

            $this->assertTrue($lint->isSuccessful(), $lint->getOutput() . $lint->getErrorOutput());
            $this->assertStringNotContainsString(
                'FlutterSdk\MagicStarter\Filament\Resources\\' . $name . '\\',
                $file->getContents(),
            );
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function resources(): array
    {
        return [
            'Users' => ['Users', 'UserResource'],
            'Teams' => ['Teams', 'TeamResource'],
            'Subscriptions' => ['Subscriptions', 'SubscriptionResource'],
            'NewsletterSubscribers' => ['NewsletterSubscribers', 'NewsletterSubscriberResource'],
            'Audits' => ['Audits', 'AuditResource'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function setterLines(): array
    {
        return [
            'Users' => ['Users', '->userResource(\App\Filament\Resources\Users\UserResource::class)'],
            'Teams' => ['Teams', '->teamResource(\App\Filament\Resources\Teams\TeamResource::class)'],
            'Subscriptions' => [
                'Subscriptions',
                "->resource('subscriptions', \\App\\Filament\\Resources\\Subscriptions\\SubscriptionResource::class)",
            ],
            'NewsletterSubscribers' => [
                'NewsletterSubscribers',
                "->resource('newsletter_subscribers', "
                . '\App\Filament\Resources\NewsletterSubscribers\NewsletterSubscriberResource::class)',
            ],
            'Audits' => ['Audits', "->resource('audits', \\App\\Filament\\Resources\\Audits\\AuditResource::class)"],
        ];
    }

    #[DataProvider('setterLines')]
    public function test_it_prints_the_plugin_setter_that_registers_the_copy(string $name, string $line): void
    {
        $this->artisan('magic-starter:filament:eject', ['resource' => $name])
            ->expectsOutputToContain('MagicStarterPlugin::make()' . $line)
            ->assertSuccessful();
    }

    public function test_it_refuses_to_overwrite_without_force(): void
    {
        $this->artisan('magic-starter:filament:eject', ['resource' => 'Teams'])->assertSuccessful();

        $resource = $this->scratch . '/app/Filament/Resources/Teams/TeamResource.php';
        File::put($resource, '<?php // edited');

        $this->artisan('magic-starter:filament:eject', ['resource' => 'Teams'])->assertFailed();

        $this->assertSame('<?php // edited', File::get($resource));

        $this->artisan('magic-starter:filament:eject', ['resource' => 'Teams', '--force' => true])
            ->assertSuccessful();

        $this->assertStringContainsString('namespace App\Filament\Resources\Teams;', File::get($resource));
    }

    #[DataProvider('rejectedNames')]
    public function test_anything_but_the_five_names_fails_before_a_path_is_built(string $name): void
    {
        $this->artisan('magic-starter:filament:eject', ['resource' => $name])->assertFailed();

        $this->assertDirectoryDoesNotExist($this->scratch . '/app/Filament');
        $this->assertSame([], File::directories($this->scratch . '/app'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rejectedNames(): array
    {
        return [
            'unknown' => ['Widgets'],
            'lower case' => ['users'],
            'traversal' => ['../../Users'],
            'a path' => ['Users/Pages'],
            'a package class' => ['MagicStarterResource'],
            'empty' => [''],
        ];
    }
}
