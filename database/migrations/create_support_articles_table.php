<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support articles: the knowledge base the AI agent, the public FAQ page and
 * in-app suggestions read.
 *
 * An article belongs to the app, not to a team, so there is no `team_id`. It is
 * unpublished while `published_at` is null and goes live when that moment
 * passes: readers compare it to the clock, so a future date needs no write.
 * `question`, `body` (Markdown, never HTML) and `locale_state` are JSON maps
 * keyed by locale; a locale is readable only once its state is `approved`.
 * `visibility` is `public`, `ai_only` or `staff_only`.
 *
 * Guarded by `hasTable`: this migration creates a table it owns outright, so a
 * second run is a plain no-op.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('support_articles')) {
            return;
        }

        Schema::create('support_articles', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);

            $table->string('slug', 160)->unique();
            $table->string('section', 64);
            $table->unsignedInteger('sort')->default(0);
            $table->string('visibility', 16)->default('public');
            $table->timestamp('published_at')->nullable();
            $table->json('question');
            $table->json('body');
            $table->json('locale_state');

            $table->timestamps();

            $table->index(['visibility', 'section', 'sort']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_articles');
    }
};
