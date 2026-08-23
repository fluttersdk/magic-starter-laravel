<?php

namespace FlutterSdk\MagicStarter\Tests;

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use Laravel\Cashier\Cashier;

/**
 * Guards the two claims this package's billing dependencies make.
 *
 * The first is a claim about two FILES agreeing: composer.json declares which
 * Illuminate components the package needs, and .github/workflows/ci.yml pins
 * each of them by name to the matrix Laravel version. A require added without
 * its pin is invisible to any resolved-version check, because every Illuminate
 * split requires illuminate/support at its own major, so pinning support to
 * 12.* transitively holds an unpinned illuminate/console at 12 as well. The
 * mutant is therefore only reachable by comparing the two files.
 *
 * The second is a claim about a PHASE: Cashier's routes are refused from the
 * provider's register(), never from boot(). By the time any boot() runs,
 * CashierServiceProvider::boot() has already registered stripe/webhook, so a
 * refusal there is too late (laravel/cashier#1739).
 */
final class DependencyPinTest extends TestCase
{
    protected function tearDown(): void
    {
        // ignoreRoutes() writes a static, so it outlives the application this
        // test booted. Put it back or every later test inherits the refusal.
        Cashier::$registersRoutes = true;

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // The CI pin line against the require block
    // -------------------------------------------------------------------------

    public function test_every_illuminate_require_is_pinned_in_the_ci_matrix_line(): void
    {
        $required = array_keys($this->illuminateRequires());
        $pinned = $this->ciPinnedIlluminatePackages();

        // Vacuity guard: a broken extractor yields two empty lists, which are
        // equal, so the comparison below would pass while measuring nothing.
        $this->assertContains('illuminate/support', $required);
        $this->assertContains('illuminate/support', $pinned);

        $this->assertSame($required, $pinned, sprintf(
            'composer.json requires [%s] while the CI pin line carries [%s]. '
            . 'Every illuminate require must be pinned to the matrix version by name.',
            implode(', ', array_diff($required, $pinned)) ?: 'nothing extra',
            implode(', ', array_diff($pinned, $required)) ?: 'nothing extra',
        ));
    }

    public function test_every_illuminate_require_carries_the_same_version_constraint(): void
    {
        $constraints = array_unique(array_values($this->illuminateRequires()));

        $this->assertNotEmpty($constraints);

        // One narrower constraint decides the whole tree: Composer resolves the
        // intersection, so a single '^12.0' beside eight '^12.0|^13.0' silently
        // caps the package at Laravel 12 with nothing in CI to say so.
        $this->assertCount(1, $constraints, sprintf(
            'The illuminate requires carry mixed constraints [%s]; the narrowest one wins for all of them.',
            implode('] and [', $constraints),
        ));
    }

    // -------------------------------------------------------------------------
    // Cashier is wired in register(), not boot()
    // -------------------------------------------------------------------------

    public function test_cashier_routes_are_refused_from_register_when_billing_is_enabled(): void
    {
        Cashier::$registersRoutes = true;

        config(['magic-starter.features' => [Features::billing()]]);

        // register() ALONE, with no boot() call anywhere after it: that is the
        // phase claim. Cashier reads $registersRoutes inside its own boot(), and
        // every provider registers before any provider boots, so this is the
        // last moment the refusal still lands.
        (new MagicStarterServiceProvider($this->app))->register();

        $this->assertFalse(Cashier::$registersRoutes);
    }

    public function test_cashier_keeps_its_own_routes_when_billing_is_disabled(): void
    {
        Cashier::$registersRoutes = true;

        config(['magic-starter.features' => []]);

        (new MagicStarterServiceProvider($this->app))->register();

        // An adopter who installs this package and drives Cashier directly is
        // entitled to Cashier's stripe/webhook route. The gate is what leaves
        // it alone, and without it the hard require would break them.
        $this->assertTrue(Cashier::$registersRoutes);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Read the illuminate entries of composer.json's require block.
     *
     * The path is relative to this file and never base_path(): under Testbench
     * base_path() resolves into the skeleton application, not the package, so a
     * base_path() read would find nothing here.
     *
     * @return array<string, string> package name to version constraint
     */
    private function illuminateRequires(): array
    {
        $manifest = file_get_contents(__DIR__ . '/../composer.json');

        $this->assertIsString($manifest, 'composer.json must be readable from the package root.');

        /** @var array{require?: array<string, string>} $decoded */
        $decoded = json_decode($manifest, true, 512, JSON_THROW_ON_ERROR);

        $require = $decoded['require'] ?? [];

        $illuminate = array_filter(
            $require,
            static fn (string $package): bool => str_starts_with($package, 'illuminate/'),
            ARRAY_FILTER_USE_KEY,
        );

        ksort($illuminate);

        return $illuminate;
    }

    /**
     * Read the illuminate packages the CI workflow pins to the matrix version.
     *
     * @return list<string> sorted package names
     */
    private function ciPinnedIlluminatePackages(): array
    {
        $workflow = file_get_contents(__DIR__ . '/../.github/workflows/ci.yml');

        $this->assertIsString($workflow, 'The CI workflow must be readable from the package root.');

        // 1. Join the backslash-continued shell lines, so the invocation is one
        //    string whether it is written on one line or wrapped for reading.
        $flattened = preg_replace('/\\\\\s*\R\s*/', ' ', $workflow);

        $this->assertIsString($flattened);

        // 2. Isolate the require invocation. Only this command carries pins;
        //    the update below it must not be read as one.
        $this->assertSame(1, preg_match('/^[ \t]*composer require [^\r\n]+/m', $flattened, $invocation));

        // 3. Every illuminate argument, and separately every one of them that is
        //    actually pinned to the matrix. Comparing the two catches a package
        //    listed with a hardcoded version instead of ${{ matrix.laravel }}.
        preg_match_all('#illuminate/([a-z-]+)#', $invocation[0], $mentioned);
        preg_match_all('#illuminate/([a-z-]+):\$\{\{\s*matrix\.laravel\s*\}\}#', $invocation[0], $pinned);

        $mentionedNames = $mentioned[1];
        $pinnedNames = $pinned[1];

        sort($mentionedNames);
        sort($pinnedNames);

        $this->assertSame($mentionedNames, $pinnedNames, sprintf(
            'The CI require line mentions [%s] without pinning it to ${{ matrix.laravel }}.',
            implode(', ', array_diff($mentionedNames, $pinnedNames)),
        ));

        return array_map(
            static fn (string $name): string => 'illuminate/' . $name,
            $pinnedNames,
        );
    }
}
