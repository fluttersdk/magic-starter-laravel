<?php

use FlutterSdk\MagicStarter\MagicStarter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives the BILLABLE SUBJECT the currency its Stripe customer is billed in, on
 * whichever table `magic-starter.billing.billable` names.
 *
 * Stripe bills a customer in one currency once a subscription has fixed it,
 * so after that a price in any other currency is one the customer cannot be
 * charged. `billing_currency` records that fact for the plans screen and the
 * card-rail writes: they show and charge it ahead of the caller's country.
 *
 * It is written by the Stripe subscription webhook ALONE, from the first
 * `default` subscription that carries a currency, and never overwritten.
 * Nullable with no backfill: NULL is the honest reading of "Stripe has not
 * billed this subject yet", and the next subscription event pins a subject
 * that subscribed before this column existed.
 *
 * `string(3)`, uppercase ISO 4217, because that is how every price table in
 * the catalogue keys a currency.
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

        if (Schema::hasColumn($table, 'billing_currency')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->string('billing_currency', 3)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * The pin is recoverable, unlike the Stripe customer id: the next
     * subscription event writes it again from what Stripe bills in.
     */
    public function down(): void
    {
        $table = $this->billableTable();

        if (! Schema::hasColumn($table, 'billing_currency')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->dropColumn('billing_currency');
        });
    }

    /**
     * Resolve the table the billable lives on, refusing an absent one.
     *
     * Mirrors the provenance migration's refusal: a table the token names and
     * the database does not have is a configuration mistake, and `migrate` is
     * the cheapest place to say so.
     *
     * @throws RuntimeException
     */
    private function billableTable(): string
    {
        $model = MagicStarter::billableModel();
        $table = (new $model)->getTable();

        if (! Schema::hasTable($table)) {
            throw new RuntimeException(sprintf(
                'Cannot add the billing currency: table [%s] does not exist. It is resolved from '
                . '[magic-starter.billing.billable] through MagicStarter::billableModel(), which '
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
