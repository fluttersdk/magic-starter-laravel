<?php

use FlutterSdk\MagicStarter\Models\NewsletterSubscriber;
use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Gives every newsletter subscription an owner, a consent record and a one-click
 * unsubscribe token, and settles the rows written before any of that existed.
 *
 * Every new column is nullable: the code still running while this deploys
 * inserts by address alone, and the new code claims such rows later. A legacy
 * row is recorded as consent version `pre-e5a` as of its creation, from the
 * source it was written with; an inactive one as unsubscribed at its last
 * update. A row whose address matches no user cannot be tied to anyone who
 * consented, so it is removed together with its audit trail, and the count is
 * logged. Re-running it changes nothing.
 */
return new class extends Migration
{
    private const TABLE = 'newsletter_subscribers';

    private const CHUNK = 500;

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $this->addColumns();

        // 1. The owner first, because an owner-less row is not backfilled but removed.
        $this->backfillOwners();

        // 2. Orphans go before anything is minted for them.
        $this->deleteOrphans();

        // 3. What a legacy row is taken to have consented to. The source column is wider than
        // consent_source, so it is cut to fit rather than failing the deploy on a long value.
        DB::table(self::TABLE)
            ->whereNull('consented_at')
            ->update([
                'consented_at' => DB::raw('created_at'),
                'consent_version' => 'pre-e5a',
                'consent_source' => DB::raw('substr(source, 1, 32)'),
            ]);

        DB::table(self::TABLE)
            ->where('is_active', false)
            ->whereNull('unsubscribed_at')
            ->update([
                'unsubscribed_at' => DB::raw('updated_at'),
            ]);

        // 4. One token per row, minted here because the database has no portable url-safe random.
        DB::table(self::TABLE)
            ->whereNull('unsubscribe_token')
            ->select('id')
            ->chunkById(self::CHUNK, function (Collection $rows): void {
                foreach ($rows as $row) {
                    DB::table(self::TABLE)
                        ->where('id', $row->id)
                        ->update([
                            'unsubscribe_token' => $this->mintToken(),
                        ]);
                }
            });
    }

    private function addColumns(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (! Schema::hasColumn(self::TABLE, 'user_id')) {
                MigrationHelper::foreignKey($table, 'user_id')
                    ->nullable()
                    ->unique()
                    ->constrained('users')
                    ->cascadeOnDelete();
            }

            if (! Schema::hasColumn(self::TABLE, 'consented_at')) {
                $table->timestamp('consented_at')->nullable();
            }

            if (! Schema::hasColumn(self::TABLE, 'consent_version')) {
                $table->string('consent_version', 32)->nullable();
            }

            if (! Schema::hasColumn(self::TABLE, 'consent_source')) {
                $table->string('consent_source', 32)->nullable();
            }

            if (! Schema::hasColumn(self::TABLE, 'unsubscribed_at')) {
                $table->timestamp('unsubscribed_at')->nullable();
            }

            if (! Schema::hasColumn(self::TABLE, 'unsubscribe_token')) {
                $table->string('unsubscribe_token', 64)->nullable()->unique();
            }
        });
    }

    /**
     * Give each row the user whose address it carries.
     *
     * An exact match first, set-based. Then the rows that match only when case is ignored, one at
     * a time: an app whose unique indexes are case-sensitive can hold `Foo@x` and `foo@x` in either
     * table, and `user_id` is unique, so a row whose user another row already owns stays owner-less
     * (and is removed with the orphans) instead of failing the deploy on the index.
     */
    private function backfillOwners(): void
    {
        DB::table(self::TABLE)
            ->whereNull('user_id')
            ->update([
                'user_id' => DB::table('users')
                    ->select('users.id')
                    ->whereColumn('users.email', self::TABLE . '.email')
                    ->limit(1),
            ]);

        DB::table(self::TABLE)
            ->whereNull('user_id')
            ->select('id', 'email')
            ->chunkById(self::CHUNK, function (Collection $rows): void {
                foreach ($rows as $row) {
                    // The first case variant that owns no row yet, in a stable order.
                    $owner = DB::table('users')
                        ->whereRaw('lower(users.email) = ?', [mb_strtolower((string) $row->email)])
                        ->whereNotExists(static function ($query): void {
                            $query->select(DB::raw(1))
                                ->from(self::TABLE)
                                ->whereColumn(self::TABLE . '.user_id', 'users.id');
                        })
                        ->orderBy('users.id')
                        ->value('users.id');

                    if ($owner === null) {
                        continue;
                    }

                    DB::table(self::TABLE)->where('id', $row->id)->update(['user_id' => $owner]);
                }
            });
    }

    /**
     * Remove the rows no user owns, their audit rows first.
     *
     * No morph map is registered for the subscriber, so the trail stores its
     * class name as the type, and its keys as strings.
     */
    private function deleteOrphans(): void
    {
        $audited = Schema::hasTable('magic_starter_audits');
        $orphans = 0;

        DB::table(self::TABLE)
            ->whereNull('user_id')
            ->select('id')
            ->chunkById(self::CHUNK, function (Collection $rows) use ($audited, &$orphans): void {
                $ids = $rows->pluck('id')->all();

                if ($audited) {
                    DB::table('magic_starter_audits')
                        ->where('auditable_type', NewsletterSubscriber::class)
                        ->whereIn('auditable_id', array_map('strval', $ids))
                        ->delete();
                }

                DB::table(self::TABLE)->whereIn('id', $ids)->delete();
                $orphans += count($ids);
            });

        Log::info('Newsletter consent migration deleted subscribers that match no user.', [
            'orphans' => $orphans,
        ]);
    }

    private function mintToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(40)), '+/', '-_'), '=');
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'user_id')) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropUnique(['user_id']);
            $table->dropUnique(['unsubscribe_token']);
            $table->dropColumn([
                'user_id',
                'consented_at',
                'consent_version',
                'consent_source',
                'unsubscribed_at',
                'unsubscribe_token',
            ]);
        });
    }
};
