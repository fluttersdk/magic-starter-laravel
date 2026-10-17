<?php

namespace FlutterSdk\MagicStarter\Tests\Database;

use FlutterSdk\MagicStarter\Models\NewsletterSubscriber;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Runs the real consent migration against a seeded legacy table: one row whose
 * address matches a user exactly, one that matches in different case, one
 * inactive, and one orphan whose address belongs to nobody. Each row is read
 * back afterwards, because this migration deletes production rows.
 */
class NewsletterConsentMigrationTest extends TestCase
{
    /**
     * @return array<string, array{bool}>
     */
    public static function keyModes(): array
    {
        return [
            'uuid keys' => [
                true,
            ],
            'integer keys' => [
                false,
            ],
        ];
    }

    #[DataProvider('keyModes')]
    public function test_it_adds_the_consent_columns(bool $useUuids): void
    {
        config()->set('magic-starter.use_uuids', $useUuids);
        $this->buildLegacySchema();

        $this->consentMigration()->up();

        $this->assertTrue(Schema::hasColumns('newsletter_subscribers', [
            'user_id',
            'consented_at',
            'consent_version',
            'consent_source',
            'unsubscribed_at',
            'unsubscribe_token',
        ]));
        $this->assertTrue(Schema::hasIndex('newsletter_subscribers', [
            'user_id',
        ], 'unique'));
        $this->assertTrue(Schema::hasIndex('newsletter_subscribers', [
            'unsubscribe_token',
        ], 'unique'));

        $foreignKeys = collect(Schema::getForeignKeys('newsletter_subscribers'));
        $this->assertTrue($foreignKeys->contains(
            fn (array $key): bool => $key['columns'] === ['user_id']
                && $key['foreign_table'] === 'users'
                && strtolower($key['on_delete']) === 'cascade',
        ));
    }

    #[DataProvider('keyModes')]
    public function test_it_backfills_the_owner_case_insensitively(bool $useUuids): void
    {
        config()->set('magic-starter.use_uuids', $useUuids);
        $this->buildLegacySchema();
        $exact = $this->insertUser('exact@example.com');
        $shouting = $this->insertUser('shouting@example.com');
        $this->insertSubscriber('exact@example.com');
        $this->insertSubscriber('Shouting@Example.COM');

        $this->consentMigration()->up();

        $this->assertEquals($exact, $this->subscriber('exact@example.com')->user_id);
        $this->assertEquals($shouting, $this->subscriber('Shouting@Example.COM')->user_id);
    }

    public function test_two_rows_differing_only_by_case_leave_one_owner_and_one_orphan(): void
    {
        $this->buildLegacySchema();
        $owner = $this->insertUser('twin@example.com');
        $this->insertSubscriber('twin@example.com');
        $this->insertSubscriber('Twin@Example.com');

        $this->consentMigration()->up();

        $this->assertEquals($owner, $this->subscriber('twin@example.com')->user_id);
        $this->assertSame(1, DB::table('newsletter_subscribers')->count());
    }

    public function test_an_exact_case_user_wins_over_a_case_variant(): void
    {
        $this->buildLegacySchema();
        $this->insertUser('casey@example.com');
        $exact = $this->insertUser('Casey@Example.com');
        $this->insertSubscriber('Casey@Example.com');

        $this->consentMigration()->up();

        $this->assertEquals($exact, $this->subscriber('Casey@Example.com')->user_id);
    }

    public function test_a_case_variant_row_goes_to_the_case_variant_user_still_free(): void
    {
        $this->buildLegacySchema();
        $taken = $this->insertUser('Kim@example.com');
        $free = $this->insertUser('kIm@example.com');
        $this->insertSubscriber('Kim@example.com');
        $this->insertSubscriber('KIM@example.com');

        $this->consentMigration()->up();

        $this->assertEquals($taken, $this->subscriber('Kim@example.com')->user_id);
        $this->assertEquals($free, $this->subscriber('KIM@example.com')->user_id);
    }

    public function test_a_legacy_row_gets_the_pre_e5a_consent_record(): void
    {
        $this->buildLegacySchema();
        $this->insertUser('reader@example.com');
        $this->insertSubscriber('reader@example.com', [
            'source' => 'footer',
            'created_at' => '2025-01-02 03:04:05',
            'updated_at' => '2025-06-07 08:09:10',
        ]);

        $this->consentMigration()->up();

        $row = $this->subscriber('reader@example.com');
        $this->assertSame('2025-01-02 03:04:05', $row->consented_at);
        $this->assertSame('pre-e5a', $row->consent_version);
        $this->assertSame('footer', $row->consent_source);
        $this->assertNull($row->unsubscribed_at);
    }

    public function test_an_inactive_row_is_unsubscribed_as_of_its_last_update(): void
    {
        $this->buildLegacySchema();
        $this->insertUser('active@example.com');
        $this->insertUser('gone@example.com');
        $this->insertSubscriber('active@example.com');
        $this->insertSubscriber('gone@example.com', [
            'is_active' => false,
            'created_at' => '2025-01-02 03:04:05',
            'updated_at' => '2025-06-07 08:09:10',
        ]);

        $this->consentMigration()->up();

        $this->assertNull($this->subscriber('active@example.com')->unsubscribed_at);
        $this->assertSame('2025-06-07 08:09:10', $this->subscriber('gone@example.com')->unsubscribed_at);
    }

    public function test_every_row_gets_its_own_url_safe_token(): void
    {
        $this->buildLegacySchema();

        foreach (range(1, 5) as $index) {
            $this->insertUser("reader{$index}@example.com");
            $this->insertSubscriber("reader{$index}@example.com");
        }

        $this->consentMigration()->up();

        $tokens = DB::table('newsletter_subscribers')->pluck('unsubscribe_token');
        $this->assertCount(5, $tokens);
        $this->assertCount(5, $tokens->unique());

        foreach ($tokens as $token) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{54}$/', $token);
        }
    }

    public function test_an_orphan_is_deleted_with_its_audit_rows_and_a_matched_row_keeps_its_own(): void
    {
        Log::spy();
        $this->buildLegacySchema();
        $this->insertUser('kept@example.com');
        $kept = $this->insertSubscriber('kept@example.com');
        $orphan = $this->insertSubscriber('nobody@example.com');
        $this->insertAudit($kept);
        $this->insertAudit($orphan);
        $this->insertAudit($orphan);

        $this->consentMigration()->up();

        $this->assertSame(1, DB::table('newsletter_subscribers')->count());
        $this->assertNotNull($this->subscriber('kept@example.com'));
        $this->assertSame(0, $this->auditCount($orphan));
        $this->assertSame(1, $this->auditCount($kept));
        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(fn (string $message, array $context = []): bool => ($context['orphans'] ?? null) === 1);
    }

    public function test_orphans_are_deleted_when_the_audit_table_is_absent(): void
    {
        $this->buildLegacySchema(audits: false);
        $this->insertSubscriber('nobody@example.com');

        $this->consentMigration()->up();

        $this->assertSame(0, DB::table('newsletter_subscribers')->count());
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->buildLegacySchema();
        $this->insertUser('reader@example.com');
        $this->insertUser('gone@example.com');
        $this->insertSubscriber('reader@example.com');
        $this->insertSubscriber('gone@example.com', [
            'is_active' => false,
        ]);
        $this->consentMigration()->up();
        $before = DB::table('newsletter_subscribers')->orderBy('email')->get();

        $this->consentMigration()->up();

        $this->assertEquals($before, DB::table('newsletter_subscribers')->orderBy('email')->get());
    }

    public function test_it_does_nothing_when_the_table_is_absent(): void
    {
        $this->migrate('create_users_table.php');

        $this->consentMigration()->up();

        $this->assertFalse(Schema::hasTable('newsletter_subscribers'));
    }

    public function test_down_removes_the_consent_columns(): void
    {
        $this->buildLegacySchema();
        $this->insertUser('reader@example.com');
        $this->insertSubscriber('reader@example.com');
        $migration = $this->consentMigration();
        $migration->up();

        $migration->down();

        $this->assertTrue(Schema::hasColumns('newsletter_subscribers', [
            'email',
            'is_active',
            'source',
        ]));

        foreach (['user_id', 'consented_at', 'consent_version', 'consent_source', 'unsubscribed_at', 'unsubscribe_token'] as $column) {
            $this->assertFalse(Schema::hasColumn('newsletter_subscribers', $column), $column);
        }

        $this->assertSame(1, DB::table('newsletter_subscribers')->count());
    }

    /**
     * The tables as an application built them before the consent columns existed.
     */
    private function buildLegacySchema(bool $audits = true): void
    {
        $this->migrate('create_users_table.php');
        $this->migrate('create_newsletter_subscribers_table.php');

        if ($audits) {
            $this->migrate('create_magic_starter_audits_table.php');
        }
    }

    private function consentMigration(): Migration
    {
        return require __DIR__ . '/../../database/migrations/add_consent_to_newsletter_subscribers_table.php';
    }

    private function migrate(string $file): void
    {
        $migration = require __DIR__ . '/../../database/migrations/' . $file;
        $migration->up();
    }

    private function insertUser(string $email): string|int
    {
        return $this->insertRow('users', [
            'name' => 'Reader',
            'email' => $email,
            'password' => 'secret',
        ]);
    }

    /**
     * Insert a row the way the pre-consent code did: by address, nothing else.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function insertSubscriber(string $email, array $attributes = []): string|int
    {
        return $this->insertRow('newsletter_subscribers', array_merge([
            'email' => $email,
            'is_active' => true,
            'source' => 'settings',
            'created_at' => '2025-01-01 00:00:00',
            'updated_at' => '2025-01-01 00:00:00',
        ], $attributes));
    }

    private function insertAudit(string|int $subscriberId): void
    {
        $this->insertRow('magic_starter_audits', [
            'event' => 'updated',
            'auditable_type' => NewsletterSubscriber::class,
            'auditable_id' => (string) $subscriberId,
            'created_at' => '2025-01-01 00:00:00',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insertRow(string $table, array $attributes): string|int
    {
        if (! config('magic-starter.use_uuids')) {
            return DB::table($table)->insertGetId($attributes);
        }

        $id = (string) Str::uuid();
        DB::table($table)->insert(array_merge([
            'id' => $id,
        ], $attributes));

        return $id;
    }

    private function subscriber(string $email): object
    {
        $row = DB::table('newsletter_subscribers')->where('email', $email)->first();
        $this->assertNotNull($row, "No subscriber row for {$email}.");

        return $row;
    }

    private function auditCount(string|int $subscriberId): int
    {
        return DB::table('magic_starter_audits')
            ->where('auditable_type', NewsletterSubscriber::class)
            ->where('auditable_id', (string) $subscriberId)
            ->count();
    }
}
