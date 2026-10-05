<?php

namespace FlutterSdk\MagicStarter\Tests\Audit;

use FlutterSdk\MagicStarter\Audit\Audit;
use FlutterSdk\MagicStarter\Audit\Auditor;
use FlutterSdk\MagicStarter\Audit\HasAudits;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use RuntimeException;

/**
 * Explicit events through `Auditor::record()`, the `HasAudits` relation, and
 * the feature toggle and config the audit system reads.
 */
#[DefineEnvironment('withAuditEnabled')]
final class AuditorTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function withAuditEnabled($app): void
    {
        $app['config']->set('magic-starter.features', [
            Features::teams(),
            Features::audit(),
        ]);
    }

    /**
     * @param  Application  $app
     */
    protected function withAuditDisabled($app): void
    {
        $app['config']->set('magic-starter.features', [
            Features::teams(),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('magic-starter.use_uuids', false);

        foreach ([
            'create_users_table',
            'create_teams_table',
            'create_magic_starter_audits_table',
        ] as $migration) {
            (require __DIR__ . "/../../database/migrations/{$migration}.php")->up();
        }
    }

    public function test_the_audit_feature_is_opt_in(): void
    {
        $this->assertSame('audit', Features::audit());
        $this->assertTrue(Features::hasAuditFeatures());

        config()->set('magic-starter.features', []);

        $this->assertFalse(Features::hasAuditFeatures());
    }

    public function test_the_shipped_config_defaults(): void
    {
        $config = require __DIR__ . '/../../config/magic-starter.php';

        $this->assertSame([
            'exclude' => [\Laravel\Sanctum\PersonalAccessToken::class],
            'redact' => [],
            'retention_days' => 365,
        ], $config['audit']);
        $this->assertSame([], $config['admin']['emails']);
        $this->assertStringContainsString(
            '// \\FlutterSdk\\MagicStarter\\Features::audit(),',
            (string) file_get_contents(__DIR__ . '/../../config/magic-starter.php'),
        );
    }

    public function test_record_writes_an_explicit_event_with_the_authenticated_actor(): void
    {
        $actor = $this->createUser('admin@example.com');
        $subject = $this->createUser('subject@example.com');
        $this->actingAs($actor);

        Auditor::record('admin.impersonated', $subject, [
            'reason' => 'support ticket',
        ]);

        $audit = Audit::query()->where('event', 'admin.impersonated')->sole();

        $this->assertSame($subject->getMorphClass(), $audit->auditable_type);
        $this->assertSame((string) $subject->getKey(), $audit->auditable_id);
        $this->assertSame((string) $subject->getKey(), $audit->related_user_id);
        $this->assertSame($actor->getMorphClass(), $audit->actor_type);
        $this->assertSame((string) $actor->getKey(), $audit->actor_id);
        $this->assertSame('support ticket', $audit->context['reason']);
        $this->assertArrayHasKey('ip', $audit->context);
        $this->assertNull($audit->old_values);
        $this->assertNull($audit->new_values);
    }

    public function test_an_explicit_actor_wins_over_the_authenticated_user(): void
    {
        $this->actingAs($this->createUser('session@example.com'));
        $operator = $this->createUser('operator@example.com');

        Auditor::record('admin.exported', null, [], $operator);

        $audit = Audit::query()->where('event', 'admin.exported')->sole();

        $this->assertSame((string) $operator->getKey(), $audit->actor_id);
        $this->assertNull($audit->auditable_type);
        $this->assertNull($audit->auditable_id);
        $this->assertNull($audit->related_user_id);
    }

    public function test_a_non_user_subject_relates_to_its_user_id(): void
    {
        $owner = $this->createUser('owner@example.com');
        $team = ConcreteTeam::query()->create([
            'name' => 'Acme',
            'user_id' => $owner->getKey(),
            'personal_team' => false,
        ]);

        Auditor::record('admin.team_renamed', $team);

        $this->assertSame(
            (string) $owner->getKey(),
            Audit::query()->where('event', 'admin.team_renamed')->sole()->related_user_id,
        );
    }

    public function test_record_still_writes_inside_without_auditing(): void
    {
        $user = $this->createUser('ada@example.com');

        Auditor::withoutAuditing(function () use ($user): void {
            $user->update(['name' => 'Suppressed']);

            Auditor::record('admin.user_updated', $user);
        });

        $this->assertSame(0, Audit::query()->where('event', 'updated')->count());
        $this->assertSame(1, Audit::query()->where('event', 'admin.user_updated')->count());
    }

    public function test_record_waits_for_the_commit_and_drops_on_rollback(): void
    {
        DB::transaction(function (): void {
            Auditor::record('admin.committed', null);

            $this->assertSame(0, Audit::query()->where('event', 'admin.committed')->count());
        });

        try {
            DB::transaction(function (): void {
                Auditor::record('admin.rolled_back', null);

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
            // The rollback is the subject of this test.
        }

        $this->assertSame(1, Audit::query()->where('event', 'admin.committed')->count());
        $this->assertSame(0, Audit::query()->where('event', 'admin.rolled_back')->count());
    }

    #[DefineEnvironment('withAuditDisabled')]
    public function test_record_writes_nothing_while_the_feature_is_off(): void
    {
        $this->assertFalse(Features::hasAuditFeatures());

        Auditor::record('admin.ignored', null);

        $this->assertSame(0, Audit::query()->count());
    }

    public function test_has_audits_exposes_the_rows_about_a_model(): void
    {
        $owner = $this->createUser('owner@example.com');
        $team = AuditorTestTeam::query()->create([
            'name' => 'Acme',
            'user_id' => $owner->getKey(),
            'personal_team' => false,
        ]);
        $team->update(['name' => 'Acme Inc']);
        Auditor::record('admin.inspected', $owner);

        $this->assertSame(['created', 'updated'], $team->audits()->orderBy('id')->pluck('event')->all());
    }

    private function createUser(string $email): ConcreteUser
    {
        return ConcreteUser::query()->create([
            'name' => 'User',
            'email' => $email,
            'password' => 'secret-password',
        ]);
    }
}

class AuditorTestTeam extends ConcreteTeam
{
    use HasAudits;
}
