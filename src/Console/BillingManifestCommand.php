<?php

namespace FlutterSdk\MagicStarter\Console;

use FlutterSdk\MagicStarter\Support\BillingManifest;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

/**
 * Print the billing manifest: what every store and rail has to hold for the
 * catalogue to sell, for an agent to apply with its own vendor CLIs.
 *
 * `--json` is the contract an agent reads; the plain output is the same data
 * flattened to dotted keys for a person skimming it.
 */
class BillingManifestCommand extends Command
{
    public const NAME = 'billing:manifest';

    /**
     * @var string
     */
    protected $signature = self::NAME . '
        {--json : Print the manifest as JSON}
        {--section= : Print one section only (app_store, play, revenuecat, stripe, env)}';

    /**
     * @var string
     */
    protected $description = 'Describe the store and rail configuration the billing catalogue needs';

    public function handle(): int
    {
        $manifest = BillingManifest::build();
        $section = $this->option('section');

        // 1. Narrow to one section, refusing a name that is not one.
        if (is_string($section) && $section !== '') {
            if (! in_array($section, BillingManifest::SECTIONS, true)) {
                $this->components->error(sprintf(
                    'Unknown section [%s]; use one of %s.',
                    $section,
                    implode(', ', BillingManifest::SECTIONS),
                ));

                return self::FAILURE;
            }

            $manifest = [
                'schema_version' => $manifest['schema_version'],
                $section => $manifest[$section],
            ];
        }

        // 2. The agent's contract.
        if ($this->option('json')) {
            $this->line((string) json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));

            return self::SUCCESS;
        }

        // 3. The same data for a person.
        foreach (Arr::except($manifest, ['schema_version']) as $name => $content) {
            $this->components->info($name);

            $plain = json_decode((string) json_encode($content, JSON_THROW_ON_ERROR), true);

            foreach (Arr::dot($plain) as $key => $value) {
                $this->components->twoColumnDetail((string) $key, $this->scalar($value));
            }
        }

        return self::SUCCESS;
    }

    private function scalar(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            $value === [] => '{}',
            default => (string) (is_scalar($value) ? $value : json_encode($value)),
        };
    }
}
