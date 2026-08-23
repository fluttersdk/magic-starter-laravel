<?php

namespace FlutterSdk\MagicStarter\Tests;

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

/**
 * Asserts the four values that must name one billable subject agree, so no
 * two of them can drift apart while four separate tests each assume the
 * other three are still correct.
 *
 * The four are: the declared `billing.billable` config token, the class
 * {@see MagicStarter::billableModel()} resolves it to, that class's own
 * `getTable()`, and the table the provenance migration ACTUALLY altered,
 * read from the database catalogue after `up()` runs rather than assumed to
 * equal the accessor's answer a second time. Asserted once per token, since
 * a fix that only works for one subject is exactly the drift this class
 * exists to catch.
 *
 * A later plan extends this same assertion rather than writing a second one:
 * once the package depends on Cashier, Cashier's customer model (the target
 * of `Cashier::useCustomerModel()`) and the `subscriptions` table's foreign
 * key (`$billable->getForeignKey()`) are the two further values that must
 * also name this subject. They are absent here only because the package
 * does not depend on Cashier until then.
 */
class BillingSubjectAgreementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The user subject: the token, the accessor, the model's table, and the
     * table the migration actually wrote to must all name `users`.
     */
    public function test_all_four_values_name_the_user_table_together(): void
    {
        $this->assertFourValuesAgree(
            token: 'user',
            expectedTable: 'users',
            expectedModel: ConcreteUser::class,
            features: [Features::billing()],
        );
    }

    /**
     * The team subject: the token, the accessor, the model's table, and the
     * table the migration actually wrote to must all name `teams`.
     */
    public function test_all_four_values_name_the_team_table_together(): void
    {
        $this->assertFourValuesAgree(
            token: 'team',
            expectedTable: 'teams',
            expectedModel: ConcreteTeam::class,
            features: [Features::teams(), Features::billing()],
        );
    }

    /**
     * Run the four-value agreement check for one billable token.
     *
     * @param  class-string  $expectedModel
     * @param  list<string>  $features
     */
    private function assertFourValuesAgree(
        string $token,
        string $expectedTable,
        string $expectedModel,
        array $features,
    ): void {
        config([
            'magic-starter.features' => $features,
            'magic-starter.billing.billable' => $token,
        ]);

        // 1. The declared token, read directly from config rather than through
        //    the accessor below, so it is a genuinely separate value.
        $this->assertSame($token, config('magic-starter.billing.billable'));

        // 2. The class the accessor resolves the token to.
        $resolvedModel = MagicStarter::billableModel();
        $this->assertSame($expectedModel, $resolvedModel);

        // 3. That class's own table.
        $this->assertSame($expectedTable, (new $resolvedModel)->getTable());

        // 4. The table the migration ACTUALLY altered. Both candidate tables
        //    exist before it runs, minimally and carrying no provenance yet,
        //    so the migration's choice is OBSERVED from the database catalogue
        //    afterward instead of assumed: asking billableModel()->getTable()
        //    a second time here would compare the accessor with itself, and
        //    would stay green even if the migration silently altered the
        //    wrong table.
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('name');
            $table->timestamps();
        });

        $migration = require __DIR__ . '/../database/migrations/add_entitlement_provenance_to_billable_table.php';
        $migration->up();

        $alteredTables = array_values(array_filter(
            ['users', 'teams'],
            static fn (string $candidate): bool => Schema::hasColumn($candidate, 'plan_provider'),
        ));

        $this->assertSame(
            [$expectedTable],
            $alteredTables,
            'The resolved table must be the only one the migration altered.',
        );
    }
}
