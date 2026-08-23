<?php

use FlutterSdk\MagicStarter\MagicStarter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives the BILLABLE SUBJECT the four columns Cashier's `Billable` trait needs
 * to hold a Stripe customer, on whichever table
 * `magic-starter.billing.billable` names.
 *
 * Cashier ships this as `2019_05_03_000001_create_customer_columns.php` and
 * that file hardcodes `Schema::table('users')`, so an application billing a
 * team gets the columns on the wrong table. The package therefore ships its own
 * and the README forbids publishing Cashier's; `addPublishGroup()` is additive
 * with no veto, so that group stays listed under `vendor:publish` and the
 * refusal can only be documented, never enforced in code.
 *
 * Without these four columns nothing else in the Stripe rail works, and it
 * fails silently rather than loudly: `hasStripeId()` answers false forever,
 * `createAsStripeCustomer()` has nowhere to write the id it just minted, and
 * `Cashier::findBillable()` matches nothing, so every incoming webhook resolves
 * no customer and no entitlement is ever written.
 *
 * - `stripe_id` is the Stripe customer id, and it is INDEXED because
 *   `Cashier::findBillable()` is a `where('stripe_id', ...)` on the hot path of
 *   every webhook delivery.
 * - `pm_type` and `pm_last_four` are the card summary Cashier caches locally so
 *   a billing screen can name the payment method without dialing Stripe.
 *   `pm_last_four` keeps Cashier's `string(4)`.
 * - `trial_ends_at` is the GENERIC trial, the one that exists before any
 *   subscription does. It is a plain `timestamp` and not the `timestampTz` the
 *   provenance columns use, because that choice is Cashier's rather than this
 *   package's: Cashier casts and compares this column itself, and uptizm's
 *   existing copy of it is already a `timestamp`.
 *
 * Per column rather than per table, matching
 * `add_entitlement_provenance_to_billable_table.php` and for the same measured
 * reason: an adopter can perfectly well have arrived at one of these and not
 * another. uptizm's `2026_07_12_020000_add_billing_to_teams.php` has all four
 * (its `stripe_id` carries a UNIQUE index rather than a plain one), so this
 * migration has to be a total no-op there, and a table-level early return would
 * have made it a no-op for a consumer holding only some of them too.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Additive only: no existing column is read, written or altered.
     */
    public function up(): void
    {
        $table = $this->billableTable();

        Schema::table($table, function (Blueprint $blueprint) use ($table): void {
            $columns = [
                'stripe_id' => fn (): mixed => $blueprint->string('stripe_id')->nullable(),
                'pm_type' => fn (): mixed => $blueprint->string('pm_type')->nullable(),
                'pm_last_four' => fn (): mixed => $blueprint->string('pm_last_four', 4)->nullable(),
                'trial_ends_at' => fn (): mixed => $blueprint->timestamp('trial_ends_at')->nullable(),
            ];

            foreach ($columns as $column => $add) {
                if (! Schema::hasColumn($table, $column)) {
                    $add();
                }
            }

            // The index is guarded on the INDEX and not on the column, because
            // the two can disagree in both directions. A consumer who already
            // holds `stripe_id` may hold it indexed (uptizm's is UNIQUE, which
            // is an index too) or bare, and only the second of those still
            // wants one. Guarding on the column instead would leave the bare
            // case unindexed forever, which is a sequential scan on the hot
            // path of every webhook delivery.
            if (! Schema::hasIndex($table, ['stripe_id'])) {
                $blueprint->index('stripe_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * Deliberately a NO-OP, and this is the one place in the package where a
     * rollback drops nothing at all. `up()` adds each column only when it is
     * absent and keeps no record of which ones it added, so `down()` cannot
     * tell a column it created from one it found. The four names here are
     * Cashier's rather than this package's, which makes finding them the COMMON
     * case and not the exotic one: uptizm holds all four from its own
     * migration, and a symmetric drop would take a live Stripe customer link
     * with it. `stripe_id` is not recoverable from anything local once it is
     * gone; the customer still exists at Stripe and the row no longer knows
     * which one it is, so every later webhook resolves nobody.
     *
     * Same asymmetric-risk reasoning as the provenance migration keeping `plan`
     * and `plan_status` through a rollback, applied to every column rather than
     * to two of them.
     */
    public function down(): void
    {
        //
    }

    /**
     * Resolve the table the Stripe customer lives on, refusing an absent one.
     *
     * Mirrors the provenance migration exactly, including the refusal: a table
     * the token names and the database does not have is a configuration
     * mistake, and `migrate` is the cheapest place to say so. Returning early
     * instead would report success, add nothing, and surface as a
     * missing-column error at the first checkout.
     *
     * @throws RuntimeException
     */
    private function billableTable(): string
    {
        $model = MagicStarter::billableModel();
        $table = (new $model)->getTable();

        if (! Schema::hasTable($table)) {
            throw new RuntimeException(sprintf(
                'Cannot add the Cashier customer columns: table [%s] does not exist. It is resolved '
                . 'from [magic-starter.billing.billable] through MagicStarter::billableModel(), which '
                . 'answered [%s]. Either that key names a subject this application does not have, or '
                . 'the migration creating [%s] has not run yet.',
                $table,
                $model,
                $table,
            ));
        }

        return $table;
    }
};
