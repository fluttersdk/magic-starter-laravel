<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lower-cases the email addresses stored before the package normalized them.
 *
 * Every request now lower-cases `email` before it looks a row up, so on a
 * database that compares strings case-sensitively (PostgreSQL, SQLite) a user
 * stored as `Bob@Example.IO` could no longer sign in or reset their password,
 * and a social sign-in under that address would not see the account.
 *
 * Two rows that differ only in case would become one address, which is two
 * people's accounts and nothing this migration can merge. It refuses before
 * writing anything and names the addresses; the operator settles them and runs
 * it again. Re-running it on lower-cased rows changes nothing.
 */
return new class extends Migration
{
    /**
     * The tables this package reads by email, and the columns a lower-cased
     * address must stay unique within.
     *
     * @var array<string, list<string>>
     */
    private const TABLES = [
        'users' => [],
        'team_invitations' => ['team_id'],
        'newsletter_subscribers' => [],
    ];

    /**
     * Run the migrations.
     *
     * @throws RuntimeException When two rows of one table differ only in the case of their email.
     */
    public function up(): void
    {
        $tables = array_filter(
            self::TABLES,
            fn (string $table): bool => Schema::hasTable($table) && Schema::hasColumn($table, 'email'),
            ARRAY_FILTER_USE_KEY,
        );

        // 1. Refuse before the first write, so a refusal leaves every table as it was.
        foreach ($tables as $table => $scope) {
            $collisions = $this->collisions($table, $scope);

            if ($collisions !== []) {
                throw new RuntimeException(sprintf(
                    'Cannot lower-case %s.email: these addresses are held by more than one row in different case: %s. '
                    . 'Merge or rename those accounts, then run the migration again.',
                    $table,
                    implode(', ', $collisions),
                ));
            }
        }

        // 2. Lower-case what is left.
        foreach (array_keys($tables) as $table) {
            DB::table($table)
                ->whereNotNull('email')
                ->whereRaw('email <> lower(email)')
                ->update(['email' => DB::raw('lower(email)')]);
        }
    }

    /**
     * Lower-cased addresses that more than one row would share.
     *
     * @param  list<string>  $scope  Columns the uniqueness is scoped to.
     * @return list<string>
     */
    private function collisions(string $table, array $scope): array
    {
        return DB::table($table)
            ->selectRaw('lower(email) as address')
            ->whereNotNull('email')
            ->groupBy(array_merge($scope, [DB::raw('lower(email)')]))
            ->havingRaw('count(*) > 1')
            ->pluck('address')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Reverse the migrations.
     *
     * The original case is not kept anywhere, so there is nothing to restore.
     */
    public function down(): void {}
};
