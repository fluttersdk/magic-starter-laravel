<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audit trail. Subject, actor and related-user keys are strings so one
 * column holds integer and UUID keys alike, whatever `use_uuids` says and
 * whichever model the row is about.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('magic_starter_audits')) {
            return;
        }

        Schema::create('magic_starter_audits', function (Blueprint $table) {
            MigrationHelper::primaryKey($table);
            $table->string('event')->index();
            $table->string('auditable_type')->nullable();
            $table->string('auditable_id')->nullable();
            $table->string('actor_type')->nullable();
            $table->string('actor_id')->nullable();
            $table->string('related_user_id')->nullable()->index();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->index([
                'auditable_type',
                'auditable_id',
            ]);

            // Account purges anonymise every row a user acted in.
            $table->index([
                'actor_type',
                'actor_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('magic_starter_audits');
    }
};
