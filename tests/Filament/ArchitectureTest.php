<?php

namespace FlutterSdk\MagicStarter\Tests\Filament;

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Filament\Resources\Audits\RelationManagers\AuditsRelationManager;
use FlutterSdk\MagicStarter\Filament\Resources\Teams\TeamResource;
use FlutterSdk\MagicStarter\Filament\Resources\Users\UserResource;
use FlutterSdk\MagicStarter\Testing\AssertsAdminWritesUseContracts;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The structural rule behind the admin panel: a write that reaches a record
 * goes through a contract, so the package's own code is held to the same
 * scanner an application runs over its overrides.
 *
 * The negative fixtures are PHP written to a temp file by the test, never a
 * loadable class: they name Filament classes that must not autoload, and a
 * file under tests/ would be picked up by tooling that walks the tree.
 */
final class ArchitectureTest extends FilamentTestCase
{
    /** @var list<string> */
    private array $fixtures = [];

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_the_package_admin_code_writes_through_contracts(): void
    {
        AssertsAdminWritesUseContracts::assertAdminWritesUseContracts([__DIR__ . '/../../src/Filament']);
    }

    public function test_a_delete_action_without_using_fails_the_scan(): void
    {
        $path = $this->fixture(<<<'PHP'
            <?php

            use Filament\Actions\DeleteAction;

            class RawDelete
            {
                public function actions(): array
                {
                    return [
                        DeleteAction::make(),
                    ];
                }
            }
            PHP);

        try {
            AssertsAdminWritesUseContracts::assertAdminWritesUseContracts([$path]);
        } catch (AssertionFailedError $failure) {
            $this->assertStringContainsString($path, $failure->getMessage());
            $this->assertStringContainsString('DeleteAction', $failure->getMessage());

            return;
        }

        $this->fail('A DeleteAction with no ->using() passed the scan.');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unguardedWrites(): array
    {
        return [
            'delete' => ['$a = DeleteAction::make();'],
            'bulk delete' => ['$a = DeleteBulkAction::make()->requiresConfirmation();'],
            'force delete' => ['$a = ForceDeleteAction::make()->label("x");'],
            'edit' => ['$a = EditAction::make();'],
            'create' => ['$a = CreateAction::make()->label("x");'],
            'qualified name' => ['$a = \Filament\Actions\DeleteAction::make();'],
            'second of two in an array' => [
                '$a = [EditAction::make()->using(fn () => 1), DeleteAction::make()];',
            ],
            'a using that belongs to the next statement' => [
                '$a = DeleteAction::make(); $b = Other::make()->using(fn () => 1);',
            ],
        ];
    }

    #[DataProvider('unguardedWrites')]
    public function test_every_unguarded_write_action_is_reported(string $statement): void
    {
        $path = $this->fixture("<?php\n\nfunction f() { {$statement} }\n");

        $this->expectException(AssertionFailedError::class);

        AssertsAdminWritesUseContracts::assertAdminWritesUseContracts([$path]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function guardedWrites(): array
    {
        return [
            'using on a chain' => ['$a = DeleteAction::make()->requiresConfirmation()->using(fn () => 1);'],
            'using on a nested call' => ['$a = EditAction::make()->label(trans("x"))->using(fn ($r) => foo($r));'],
            'an array of guarded actions' => [
                '$a = [EditAction::make()->using(fn () => 1), CreateAction::make()->using(fn () => 2)];',
            ],
            'navigation action' => ['$a = Action::make("open")->url("/x");'],
            'a class constant, not a call' => ['$a = DeleteAction::class;'],
        ];
    }

    #[DataProvider('guardedWrites')]
    public function test_a_guarded_write_passes_the_scan(string $statement): void
    {
        $path = $this->fixture("<?php\n\nfunction f() { {$statement} }\n");

        AssertsAdminWritesUseContracts::assertAdminWritesUseContracts([$path]);
    }

    public function test_an_edit_page_without_the_contract_trait_fails_the_scan(): void
    {
        $path = $this->fixture(<<<'PHP'
            <?php

            use Filament\Resources\Pages\EditRecord;

            class EditThing extends EditRecord
            {
            }
            PHP);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('WritesThroughContracts');

        AssertsAdminWritesUseContracts::assertAdminWritesUseContracts([$path]);
    }

    public function test_a_create_page_without_the_contract_trait_fails_the_scan(): void
    {
        $path = $this->fixture(<<<'PHP'
            <?php

            class CreateThing extends \Filament\Resources\Pages\CreateRecord
            {
                use SomethingElse;
            }
            PHP);

        $this->expectException(AssertionFailedError::class);

        AssertsAdminWritesUseContracts::assertAdminWritesUseContracts([$path]);
    }

    public function test_a_page_that_uses_the_contract_trait_passes_the_scan(): void
    {
        $path = $this->fixture(<<<'PHP'
            <?php

            use Filament\Resources\Pages\EditRecord;
            use FlutterSdk\MagicStarter\Filament\Concerns\WritesThroughContracts;

            class EditThing extends EditRecord
            {
                use WritesThroughContracts;
            }
            PHP);

        AssertsAdminWritesUseContracts::assertAdminWritesUseContracts([$path]);
    }

    public function test_a_directory_is_scanned_recursively(): void
    {
        $directory = sys_get_temp_dir() . '/magic-starter-arch-' . bin2hex(random_bytes(4));
        mkdir($directory . '/nested', 0777, true);
        file_put_contents($directory . '/nested/Bad.php', "<?php\n\nfunction f() { \$a = EditAction::make(); }\n");
        $this->fixtures[] = $directory . '/nested/Bad.php';

        try {
            $this->expectException(AssertionFailedError::class);

            AssertsAdminWritesUseContracts::assertAdminWritesUseContracts([$directory]);
        } finally {
            @unlink($directory . '/nested/Bad.php');
            @rmdir($directory . '/nested');
            @rmdir($directory);
        }
    }

    public function test_the_audits_tab_is_attached_to_users_and_teams_only_with_the_audit_feature(): void
    {
        config()->set('magic-starter.features', []);
        $this->assertNotContains(AuditsRelationManager::class, UserResource::getRelations());
        $this->assertNotContains(AuditsRelationManager::class, TeamResource::getRelations());

        config()->set('magic-starter.features', [Features::audit()]);
        $this->assertContains(AuditsRelationManager::class, UserResource::getRelations());
        $this->assertContains(AuditsRelationManager::class, TeamResource::getRelations());
    }

    private function fixture(string $source): string
    {
        $path = tempnam(sys_get_temp_dir(), 'magic-starter-arch-');
        rename($path, $path .= '.php');
        file_put_contents($path, $source);

        return $this->fixtures[] = $path;
    }
}
