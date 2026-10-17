<?php

namespace FlutterSdk\MagicStarter\Tests\Models;

use FlutterSdk\MagicStarter\Models\AiPromptOverride;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Locks the one thing the override model owns beyond its columns: every write
 * and every delete forgets the resolver's cache key once the write is committed,
 * so a staff edit is read on the next call and never after a stale hit.
 */
class AiPromptOverrideTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--path' => __DIR__ . '/../../database/migrations/create_ai_prompt_overrides_table.php',
            '--realpath' => true,
        ]);
    }

    public function test_cache_key_is_the_one_the_resolver_reads(): void
    {
        $this->assertSame('magic-starter:ai-prompt-overrides', AiPromptOverride::CACHE_KEY);
    }

    public function test_it_persists_its_fillable_columns(): void
    {
        $override = AiPromptOverride::query()->create([
            'key' => 'incident.analysis',
            'body' => 'Analyse the incident.',
            'default_hash' => str_repeat('a', 64),
        ])->fresh();

        $this->assertSame('incident.analysis', $override->key);
        $this->assertSame('Analyse the incident.', $override->body);
        $this->assertSame(str_repeat('a', 64), $override->default_hash);
    }

    public function test_saving_forgets_the_cache_key(): void
    {
        Cache::put(AiPromptOverride::CACHE_KEY, ['sentinel'], 3600);

        AiPromptOverride::query()->create([
            'key' => 'incident.analysis',
            'body' => 'Analyse the incident.',
            'default_hash' => str_repeat('a', 64),
        ]);

        $this->assertFalse(Cache::has(AiPromptOverride::CACHE_KEY));
    }

    public function test_updating_forgets_the_cache_key(): void
    {
        $override = $this->makeOverride();
        Cache::put(AiPromptOverride::CACHE_KEY, ['sentinel'], 3600);

        $override->update(['body' => 'Analyse it again.']);

        $this->assertFalse(Cache::has(AiPromptOverride::CACHE_KEY));
    }

    public function test_deleting_forgets_the_cache_key(): void
    {
        $override = $this->makeOverride();
        Cache::put(AiPromptOverride::CACHE_KEY, ['sentinel'], 3600);

        $override->delete();

        $this->assertFalse(Cache::has(AiPromptOverride::CACHE_KEY));
    }

    /**
     * Guards the lost-race window: a forget at save time lets a reader rebuild the map from the
     * pre-commit rows and cache them past the commit, so the forget waits for the commit.
     */
    public function test_a_save_inside_a_transaction_forgets_the_cache_key_only_after_the_commit(): void
    {
        DB::transaction(function (): void {
            $this->makeOverride();
            Cache::put(AiPromptOverride::CACHE_KEY, ['sentinel'], 3600);

            $this->assertTrue(Cache::has(AiPromptOverride::CACHE_KEY));
        });

        $this->assertFalse(Cache::has(AiPromptOverride::CACHE_KEY));
    }

    public function test_a_delete_inside_a_transaction_forgets_the_cache_key_only_after_the_commit(): void
    {
        $override = $this->makeOverride();

        DB::transaction(function () use ($override): void {
            $override->delete();
            Cache::put(AiPromptOverride::CACHE_KEY, ['sentinel'], 3600);

            $this->assertTrue(Cache::has(AiPromptOverride::CACHE_KEY));
        });

        $this->assertFalse(Cache::has(AiPromptOverride::CACHE_KEY));
    }

    private function makeOverride(): AiPromptOverride
    {
        return AiPromptOverride::query()->create([
            'key' => 'incident.analysis',
            'body' => 'Analyse the incident.',
            'default_hash' => str_repeat('a', 64),
        ]);
    }
}
