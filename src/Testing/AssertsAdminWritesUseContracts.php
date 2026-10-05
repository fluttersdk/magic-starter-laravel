<?php

namespace FlutterSdk\MagicStarter\Testing;

use PhpToken;
use PHPUnit\Framework\Assert;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * An architecture assertion for the admin panel's one structural rule: a write
 * that reaches a record goes through a package contract.
 *
 * Filament's stock actions and pages write straight to the model, which skips
 * the contracts' validation, refusals and side effects. The scanner reads PHP
 * tokens (no autoloading, no Filament needed) and fails on:
 *
 *  - a `DeleteAction`, `DeleteBulkAction`, `ForceDeleteAction`, `EditAction` or
 *    `CreateAction` built with `::make()` whose expression has no `->using(`;
 *  - a class extending `EditRecord` or `CreateRecord` that does not use
 *    `WritesThroughContracts`.
 *
 * Run it over an application's own overrides as well as over the package:
 *
 *     public function test_admin_writes_use_contracts(): void
 *     {
 *         AssertsAdminWritesUseContracts::assertAdminWritesUseContracts([app_path('Filament')]);
 *     }
 *
 * An expression ends at the first `;` or `,` outside any bracket, or at the
 * bracket that closes the one it sits in, so `->using()` on a neighbouring
 * action in the same array does not vouch for this one. It also has to be on
 * the action's own chain, not inside a closure passed to another call.
 */
final class AssertsAdminWritesUseContracts
{
    /**
     * Fail when a scanned file writes through Filament's defaults.
     *
     * @param  list<string>  $paths  PHP files, or directories scanned recursively
     */
    public static function assertAdminWritesUseContracts(array $paths): void
    {
        // 1. A scan over nothing passes by accident, so an empty result is a failure.
        $files = self::adminWriteFiles($paths);

        Assert::assertNotSame([], $files, 'The admin write scan found no PHP file under: ' . implode(', ', $paths));

        // 2. Collect every violation before failing, so one run lists them all.
        $violations = [];

        foreach ($files as $file) {
            $tokens = self::adminWriteTokens((string) file_get_contents($file));

            foreach (self::adminWriteActionViolations($tokens) as $line => $action) {
                $violations[] = "{$file}:{$line}: {$action}::make() has no ->using(), so it writes without a contract.";
            }

            foreach (self::adminWritePageViolations($tokens) as $line => $page) {
                $violations[] = "{$file}:{$line}: {$page} does not use WritesThroughContracts, "
                    . 'so it saves through Filament\'s default.';
            }
        }

        if ($violations !== []) {
            Assert::fail("Admin writes that skip the contracts:\n" . implode("\n", $violations));
        }
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private static function adminWriteFiles(array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            if (is_file($path)) {
                $files[] = $path;

                continue;
            }

            Assert::assertDirectoryExists($path, "The admin write scan path [{$path}] does not exist.");

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                $path,
                RecursiveDirectoryIterator::SKIP_DOTS,
            ));

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * The significant tokens: whitespace and comments carry no structure.
     *
     * @return list<PhpToken>
     */
    private static function adminWriteTokens(string $source): array
    {
        $ignored = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

        return array_values(array_filter(
            PhpToken::tokenize($source),
            static fn (PhpToken $token): bool => ! $token->is($ignored),
        ));
    }

    /**
     * The action class of every `Action::make()` in the stock write set that has no `->using(`, by line.
     *
     * @param  list<PhpToken>  $tokens
     * @return array<int, string>
     */
    private static function adminWriteActionViolations(array $tokens): array
    {
        $writeActions = ['DeleteAction', 'DeleteBulkAction', 'ForceDeleteAction', 'EditAction', 'CreateAction'];
        $violations = [];

        foreach ($tokens as $index => $token) {
            $name = self::adminWriteBasename($token);

            if (! in_array($name, $writeActions, true)) {
                continue;
            }

            $isMake = ($tokens[$index + 1] ?? null)?->is(T_DOUBLE_COLON) === true
                && ($tokens[$index + 2] ?? null)?->text === 'make';

            if ($isMake && ! self::adminWriteExpressionUsesContract($tokens, $index + 3)) {
                $violations[$token->line] = $name;
            }
        }

        return $violations;
    }

    /**
     * Whether the expression starting at the given token carries `->using(` on its own chain.
     *
     * @param  list<PhpToken>  $tokens
     */
    private static function adminWriteExpressionUsesContract(array $tokens, int $from): bool
    {
        $depth = 0;

        for ($i = $from, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if (self::adminWriteOpens($token)) {
                $depth++;

                continue;
            }

            if ($token->is([')', ']', '}'])) {
                if ($depth === 0) {
                    return false;
                }

                $depth--;

                continue;
            }

            if ($depth === 0 && $token->is([';', ','])) {
                return false;
            }

            $isUsing = $depth === 0
                && $token->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])
                && ($tokens[$i + 1] ?? null)?->text === 'using'
                && ($tokens[$i + 2] ?? null)?->text === '(';

            if ($isUsing) {
                return true;
            }
        }

        return false;
    }

    /**
     * The class of every `EditRecord` or `CreateRecord` child that lacks the contract trait, by line.
     *
     * @param  list<PhpToken>  $tokens
     * @return array<int, string>
     */
    private static function adminWritePageViolations(array $tokens): array
    {
        $violations = [];

        foreach ($tokens as $index => $token) {
            // `Foo::class` is a constant lookup, not a declaration.
            if (! $token->is(T_CLASS) || ($tokens[$index - 1] ?? null)?->is(T_DOUBLE_COLON) === true) {
                continue;
            }

            $parent = null;
            $body = null;

            for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
                if ($tokens[$i]->is(T_EXTENDS)) {
                    $parent = self::adminWriteBasename($tokens[$i + 1] ?? null);
                }

                if ($tokens[$i]->text === '{') {
                    $body = $i;

                    break;
                }
            }

            if (! in_array($parent, ['EditRecord', 'CreateRecord'], true) || $body === null) {
                continue;
            }

            if (! self::adminWriteBodyUsesTrait($tokens, $body, 'WritesThroughContracts')) {
                $violations[$token->line] = $parent . ' child';
            }
        }

        return $violations;
    }

    /**
     * Whether the class body opening at the given token lists the trait in a `use` at its own level.
     *
     * @param  list<PhpToken>  $tokens
     */
    private static function adminWriteBodyUsesTrait(array $tokens, int $open, string $trait): bool
    {
        $depth = 0;

        for ($i = $open, $count = count($tokens); $i < $count; $i++) {
            if (self::adminWriteOpens($tokens[$i])) {
                $depth++;
            } elseif ($tokens[$i]->text === '}' && --$depth === 0) {
                return false;
            }

            // A `use` at depth one is a trait list; deeper, it is a closure's captured variables.
            if ($depth !== 1 || ! $tokens[$i]->is(T_USE)) {
                continue;
            }

            for ($j = $i + 1; $j < $count && ! $tokens[$j]->is([';', '{']); $j++) {
                if (self::adminWriteBasename($tokens[$j]) === $trait) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function adminWriteOpens(PhpToken $token): bool
    {
        return $token->is(['(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES]);
    }

    /**
     * The last segment of a name token, however it is qualified; null for any other token.
     */
    private static function adminWriteBasename(?PhpToken $token): ?string
    {
        if ($token === null || ! $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE])) {
            return null;
        }

        return ltrim(strrchr('\\' . $token->text, '\\'), '\\');
    }
}
