<?php

namespace FlutterSdk\MagicStarter\Tests\Filament;

use FlutterSdk\MagicStarter\Enums\AiRunStatus;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Filament\Resources\AiPrompts\AiPromptOverrideResource;
use FlutterSdk\MagicStarter\Filament\Resources\AiRuns\AiRunResource;
use FlutterSdk\MagicStarter\Filament\Resources\AppReleases\AppReleaseResource;
use FlutterSdk\MagicStarter\Filament\Resources\Audits\AuditResource;
use FlutterSdk\MagicStarter\Filament\Resources\BillingEvents\BillingEventResource;
use FlutterSdk\MagicStarter\Filament\Resources\MagicStarterResource;
use FlutterSdk\MagicStarter\Filament\Resources\SupportConversations\SupportConversationResource;
use FlutterSdk\MagicStarter\Filament\Resources\SupportFeedback\SupportFeedbackResource;
use FlutterSdk\MagicStarter\Filament\Resources\WebhookDeliveries\WebhookDeliveryResource;
use FlutterSdk\MagicStarter\Models\AiRun;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteAdminUser;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

/**
 * A record id in a panel url that is not a uuid answers 404 on a uuid-keyed resource instead of
 * reaching the database: PostgreSQL refuses to compare a uuid column with text (SQLSTATE 22P02)
 * and the panel answered 500. SQLite just misses, so the proof here is that no query runs.
 */
class RecordBindingTest extends FilamentTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('magic-starter.features', [
            Features::aiRuns(),
            Features::aiPrompts(),
        ]);
    }

    /**
     * @return array<string, array{class-string<MagicStarterResource>}>
     */
    public static function uuidResources(): array
    {
        return [
            'ai runs' => [AiRunResource::class],
            'ai prompts' => [AiPromptOverrideResource::class],
            'audits' => [AuditResource::class],
            'billing events' => [BillingEventResource::class],
            'webhook deliveries' => [WebhookDeliveryResource::class],
            'support conversations' => [SupportConversationResource::class],
            'support feedback' => [SupportFeedbackResource::class],
            'app releases' => [AppReleaseResource::class],
        ];
    }

    /**
     * @param  class-string<MagicStarterResource>  $resource
     */
    #[DataProvider('uuidResources')]
    public function test_a_non_uuid_id_binds_to_nothing_without_a_query(string $resource): void
    {
        $queries = $this->recordQueries();

        $this->assertNull($resource::resolveRecordRouteBinding('not-a-real-id'));
        $this->assertNull($resource::resolveRecordRouteBinding('42'));
        $this->assertSame([], $queries->all());
    }

    public function test_a_valid_but_unknown_uuid_still_misses_through_the_database(): void
    {
        $queries = $this->recordQueries();

        $this->assertNull(AiRunResource::resolveRecordRouteBinding((string) Str::uuid()));
        $this->assertNotSame([], $queries->all());
    }

    public function test_a_stored_run_binds_by_its_uuid(): void
    {
        $run = AiRun::query()->create([
            'feature' => 'incident.analysis',
            'status' => AiRunStatus::SUCCEEDED,
            'started_at' => Carbon::now(),
        ]);

        $this->assertTrue(Str::isUuid((string) $run->getKey()));
        $this->assertTrue($run->is(AiRunResource::resolveRecordRouteBinding((string) $run->getKey())));
    }

    public function test_the_panel_answers_404_for_a_non_uuid_id(): void
    {
        $this->actingAs($this->staff());

        $this->get(AiRunResource::getUrl('view', ['record' => 'not-a-real-id']))->assertNotFound();
        $this->get(AiPromptOverrideResource::getUrl('edit', ['record' => 'not-a-real-id']))->assertNotFound();
        $this->get(AiRunResource::getUrl('view', ['record' => (string) Str::uuid()]))->assertNotFound();
    }

    /**
     * Judged without a lookup: the tables here were built with uuid keys, so a lookup of `42` would
     * fail on PostgreSQL for a reason that has nothing to do with the guard.
     */
    public function test_an_integer_key_setup_is_not_guarded(): void
    {
        $judge = new ReflectionMethod(AiRunResource::class, 'cannotBeUuidKey');

        $this->assertTrue($judge->invoke(null, '42'));

        config()->set('magic-starter.use_uuids', false);

        $this->assertFalse($judge->invoke(null, '42'));
    }

    /**
     * Collects the queries run from now on.
     *
     * @return Collection<int, string>
     */
    private function recordQueries(): Collection
    {
        $queries = new Collection;

        DB::listen(static function (QueryExecuted $query) use ($queries): void {
            $queries->push($query->sql);
        });

        return $queries;
    }

    private function staff(): ConcreteAdminUser
    {
        config()->set('magic-starter.admin.emails', ['staff@example.com']);

        return ConcreteAdminUser::query()->firstOrCreate(['email' => 'staff@example.com'], [
            'name' => 'Staff',
            'password' => 'secret',
            'email_verified_at' => Carbon::now(),
        ]);
    }
}
