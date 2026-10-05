<?php

namespace FlutterSdk\MagicStarter\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * Copies one of the package's admin resources into the application so it can
 * be changed, and says how to register the copy.
 *
 * The copy keeps extending the package's `MagicStarterResource` and keeps
 * calling the contracts; only its own namespace moves to
 * `App\Filament\Resources\<Name>`. The name is checked against a fixed list
 * before any path is built from it.
 *
 * Registered by the package provider only while Filament is installed.
 */
class FilamentEjectCommand extends Command
{
    public const NAME = 'magic-starter:filament:eject';

    /**
     * Ejectable directory name => plugin key and resource class.
     *
     * @var array<string, array{string, string}>
     */
    private const RESOURCES = [
        'Users' => ['users', 'UserResource'],
        'Teams' => ['teams', 'TeamResource'],
        'Subscriptions' => ['subscriptions', 'SubscriptionResource'],
        'NewsletterSubscribers' => ['newsletter_subscribers', 'NewsletterSubscriberResource'],
        'Audits' => ['audits', 'AuditResource'],
    ];

    /**
     * @var string
     */
    protected $signature = self::NAME . '
        {resource : Users, Teams, Subscriptions, NewsletterSubscribers or Audits}
        {--force : Overwrite an existing copy}';

    /**
     * @var string
     */
    protected $description = 'Copy an admin resource into app/Filament/Resources so it can be customised';

    public function handle(Filesystem $files): int
    {
        // 1. Only the five names; nothing the caller typed reaches a path before this.
        $name = (string) $this->argument('resource');

        if (! array_key_exists($name, self::RESOURCES)) {
            $this->components->error(
                "Unknown resource [{$name}]. Choose one of: " . implode(', ', array_keys(self::RESOURCES)) . '.',
            );

            return self::FAILURE;
        }

        // 2. An existing copy may hold the application's edits.
        $destination = app_path('Filament/Resources/' . $name);

        if ($files->isDirectory($destination) && ! (bool) $this->option('force')) {
            $this->components->error("app/Filament/Resources/{$name} exists; use --force to overwrite it.");

            return self::FAILURE;
        }

        // 3. Copy every file under the application namespace.
        $copied = $this->copy($files, $name, $destination);

        $this->components->info("Copied {$copied} files to app/Filament/Resources/{$name}.");

        // 4. The package keeps mounting its own class until the plugin is told otherwise.
        $this->newLine();
        $this->line('Register the copy on the panel:');
        $this->newLine();
        $this->line('    ->plugin(MagicStarterPlugin::make()' . $this->setter($name) . ')');

        return self::SUCCESS;
    }

    private function copy(Filesystem $files, string $name, string $destination): int
    {
        $namespace = 'FlutterSdk\\MagicStarter\\Filament\\Resources\\' . $name;
        $target = 'App\\Filament\\Resources\\' . $name;
        $pattern = '/' . preg_quote($namespace, '/') . '(?![A-Za-z0-9_])/';

        $copied = 0;

        foreach ($files->allFiles(__DIR__ . '/../Filament/Resources/' . $name) as $file) {
            $files->ensureDirectoryExists(dirname($destination . '/' . $file->getRelativePathname()));
            $files->put(
                $destination . '/' . $file->getRelativePathname(),
                (string) preg_replace_callback($pattern, static fn (): string => $target, $file->getContents()),
            );

            $copied++;
        }

        return $copied;
    }

    private function setter(string $name): string
    {
        [$key, $class] = self::RESOURCES[$name];

        $reference = "\\App\\Filament\\Resources\\{$name}\\{$class}::class";

        return match ($key) {
            'users' => "->userResource({$reference})",
            'teams' => "->teamResource({$reference})",
            default => "->resource('{$key}', {$reference})",
        };
    }
}
