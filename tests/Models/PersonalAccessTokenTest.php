<?php

namespace FlutterSdk\MagicStarter\Tests\Models;

use FlutterSdk\MagicStarter\Models\PersonalAccessToken;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class PersonalAccessTokenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuidMorphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * A bearer of `999|x` used to reach PostgreSQL as `where id = '999'` against a
     * uuid column, which throws SQLSTATE 22P02 and answered 500 instead of 401.
     */
    public function test_a_prefix_that_is_no_uuid_names_no_token_and_queries_nothing(): void
    {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        foreach (['999|bogus', '|bogus', 'abc|'] as $bearer) {
            $this->assertNull(PersonalAccessToken::findToken($bearer));
        }

        $this->assertSame(0, $queries);
    }

    /**
     * Under integer keys Sanctum only checks the prefix is digits, so a value
     * past bigint reached PostgreSQL as SQLSTATE 22003 and answered 500.
     */
    public function test_an_integer_prefix_past_bigint_names_no_token(): void
    {
        config(['magic-starter.use_uuids' => false]);
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->assertNull(PersonalAccessToken::findToken('99999999999999999999|bogus'));
        $this->assertSame(0, $queries);
    }

    public function test_a_uuid_prefix_still_finds_its_token(): void
    {
        $token = PersonalAccessToken::query()->forceCreate([
            'tokenable_type' => 'user',
            'tokenable_id' => (string) Str::uuid(),
            'name' => 'device',
            'token' => hash('sha256', 'plain-secret'),
            'abilities' => ['*'],
        ]);

        $this->assertTrue($token->is(PersonalAccessToken::findToken("{$token->id}|plain-secret")));
        $this->assertNull(PersonalAccessToken::findToken("{$token->id}|wrong-secret"));
    }
}
