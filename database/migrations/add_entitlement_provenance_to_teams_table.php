<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives a team's entitlement the provenance and ordering discipline a SECOND
 * billing rail requires, and gives a provider-neutral billing wire a source for
 * every field it promises.
 *
 * The tier itself (`plan`) and its neutral lifecycle (`plan_status`) belong to
 * the consuming application, because the tier vocabulary does: this package has
 * no opinion about how many tiers exist or what they are called. What it does
 * own is everything needed to arbitrate between two rails feeding that tier:
 *
 * - `plan_provider` names WHICH rail granted the entitlement currently held. It
 *   is what lets a write refuse to revoke a grant some other rail made.
 * - `plan_source_event_at` is the source event's OWN timestamp, not the moment
 *   it was processed. Rails retry and cancellations arrive late, so this is the
 *   only thing that makes an out-of-order delivery detectable.
 * - `plan_provider_status` keeps the rail's own status word verbatim, for
 *   support and debugging. It is opaque text and never a gate.
 * - `plan_product_id` is the rail-native product or price identifier.
 * - `plan_current_period_end` is when the paid period ends, whether or not it
 *   renews. Deliberately not a cancellation-effective date, which means
 *   something else.
 * - `plan_renews` is the auto-renew state, and it is nullable on purpose. NULL
 *   means unknown, which is not the same claim as false.
 * - `plan_grace_period_ends_at` carries a dunning window.
 * - `plan_manage_url` is where the customer manages this subscription, and it
 *   holds only DURABLE destinations: a store's own management URL, passed
 *   through rather than hardcoded, so a store moving that page does not need an
 *   app release. It stays NULL on a card rail whose portal URL is a short-lived
 *   single-use session, because a stored copy would expire.
 *
 * Every column is nullable and there is deliberately NO backfill. Writing a
 * provider onto existing rows would assert a provenance that never happened,
 * and would then license that rail's events to revoke another rail's grant. A
 * NULL `plan_provider` is the honest reading: this row predates any rail.
 *
 * There is deliberately no database enum or CHECK constraint on
 * `plan_provider` or `plan_provider_status` either. The provider vocabulary
 * lives in a PHP enum ({@see \FlutterSdk\MagicStarter\Enums\BillingProvider}),
 * and the status column exists precisely to keep a word that vocabulary does
 * not have.
 *
 * The three instants are `timestampTz`: they are compared ACROSS rails, and an
 * ordering rule that compares two instants cannot afford either of them to be
 * zone-ambiguous.
 *
 * No key column is added here, so MigrationHelper is not involved. The
 * `hasTable` guard is: entitlement is per-team, so this migration is meaningful
 * only where the teams feature created that table, and a billing feature
 * selected without teams must be a no-op rather than a failed `migrate`.
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
        if (! Schema::hasTable('teams') || Schema::hasColumn('teams', 'plan_provider')) {
            return;
        }

        Schema::table('teams', function (Blueprint $table): void {
            $table->string('plan_provider')->nullable();
            $table->timestampTz('plan_source_event_at')->nullable();
            $table->string('plan_provider_status')->nullable();
            $table->string('plan_product_id')->nullable();
            $table->timestampTz('plan_current_period_end')->nullable();
            $table->boolean('plan_renews')->nullable();
            $table->timestampTz('plan_grace_period_ends_at')->nullable();
            $table->string('plan_manage_url', 2048)->nullable();
        });

        // The entitlement ITSELF, added only where a consumer has not already
        // got it. The eight columns above are provenance, and provenance with
        // nothing to be provenance FOR is not a feature: the default action
        // writes `plan` and `plan_status` on every apply, so without these two a
        // fresh consumer that enables billing gets a throw on its first write.
        //
        // Conditional rather than unconditional because the two audiences differ.
        // A consumer that already sells something has both (uptizm does, from its
        // own migration, and running this one there must stay a no-op); a fresh
        // one has neither. `hasColumn` per column rather than per table, since
        // nothing says a consumer cannot have arrived at one and not the other.
        //
        // `plan` is a plain nullable string on purpose: the tier vocabulary
        // belongs to the consumer, this package must not name a default tier, and
        // NULL is the honest reading of "nobody has been sold anything yet".
        // `plan_status` is this package's own vocabulary ({@see PlanStatus}) and
        // is still stored as a string rather than cast, so a status a newer
        // release introduces cannot break reads on an older one.
        Schema::table('teams', function (Blueprint $table): void {
            if (! Schema::hasColumn('teams', 'plan')) {
                $table->string('plan')->nullable();
            }

            if (! Schema::hasColumn('teams', 'plan_status')) {
                $table->string('plan_status')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * Drops only the eight PROVENANCE columns. `plan` and `plan_status` survive
     * a rollback deliberately, and the reason is asymmetric risk rather than
     * ownership: `up()` may have created them on a fresh consumer, but it cannot
     * tell afterwards whether it did, and a consumer that already had them holds
     * its live entitlement there. Leaving two nullable columns behind is untidy;
     * dropping the column a customer's paid tier is stored in is not recoverable.
     */
    public function down(): void
    {
        if (! Schema::hasTable('teams') || ! Schema::hasColumn('teams', 'plan_provider')) {
            return;
        }

        Schema::table('teams', function (Blueprint $table): void {
            $table->dropColumn([
                'plan_provider',
                'plan_source_event_at',
                'plan_provider_status',
                'plan_product_id',
                'plan_current_period_end',
                'plan_renews',
                'plan_grace_period_ends_at',
                'plan_manage_url',
            ]);
        });
    }
};
