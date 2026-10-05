<?php

namespace FlutterSdk\MagicStarter\Tests\Audit;

use FlutterSdk\MagicStarter\Audit\Audit;
use FlutterSdk\MagicStarter\Audit\Auditor;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeamUser;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * The global model listener: what it records for created, updated and deleted
 * models, what it never records, and that nothing lands before the commit.
 */
#[DefineEnvironment('withAuditEnabled')]
final class ModelAuditListenerTest extends TestCase
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

    /**
     * @return iterable<string, array{0: bool}>
     */
    public static function keyModes(): iterable
    {
        yield 'uuid keys' => [true];
        yield 'integer keys' => [false];
    }

    #[DataProvider('keyModes')]
    public function test_a_created_model_records_its_attributes_and_owner(bool $useUuids): void
    {
        $this->migrate($useUuids);

        $owner = $this->createUser('owner@example.com');
        $team = ConcreteTeam::query()->create([
            'name' => 'Acme',
            'user_id' => $owner->getKey(),
            'personal_team' => false,
        ]);

        $audit = $this->auditsFor($team)->sole();

        $this->assertSame('created', $audit->event);
        $this->assertSame((string) $team->getKey(), $audit->auditable_id);
        $this->assertNull($audit->old_values);
        $this->assertSame('Acme', $audit->new_values['name']);
        $this->assertSame((string) $owner->getKey(), $audit->related_user_id);
        $this->assertNotNull($audit->created_at);
    }

    #[DataProvider('keyModes')]
    public function test_an_update_records_only_the_changed_keys_with_their_old_values(bool $useUuids): void
    {
        $this->migrate($useUuids);

        $user = $this->createUser('ada@example.com', 'Ada');
        $user->update(['name' => 'Ada Lovelace']);

        $audit = $this->auditsFor($user)->where('event', 'updated')->sole();

        $this->assertSame('Ada', $audit->old_values['name']);
        $this->assertSame('Ada Lovelace', $audit->new_values['name']);
        $this->assertArrayNotHasKey('email', $audit->new_values);
        $this->assertArrayNotHasKey('email', $audit->old_values);
        $this->assertSame(array_keys($audit->old_values), array_keys($audit->new_values));
        $this->assertSame((string) $user->getKey(), $audit->related_user_id);
    }

    #[DataProvider('keyModes')]
    public function test_a_deleted_model_records_its_last_attributes(bool $useUuids): void
    {
        $this->migrate($useUuids);

        $owner = $this->createUser('owner@example.com');
        $team = ConcreteTeam::query()->create([
            'name' => 'Doomed',
            'user_id' => $owner->getKey(),
            'personal_team' => false,
        ]);
        $team->delete();

        $audit = $this->auditsFor($team)->where('event', 'deleted')->sole();

        $this->assertSame('Doomed', $audit->old_values['name']);
        $this->assertNull($audit->new_values);
        $this->assertSame((string) $owner->getKey(), $audit->related_user_id);
    }

    #[DataProvider('keyModes')]
    public function test_attaching_a_team_member_writes_no_membership_row_and_raises_nothing(bool $useUuids): void
    {
        $this->migrate($useUuids);

        $owner = $this->createUser('owner@example.com');
        $member = $this->createUser('member@example.com');
        $team = ConcreteTeam::query()->create([
            'name' => 'Acme',
            'user_id' => $owner->getKey(),
            'personal_team' => false,
        ]);

        $member->teams()->attach($team->getKey(), ['role' => 'editor']);
        $member->teams()->updateExistingPivot($team->getKey(), ['role' => 'admin']);
        $member->teams()->detach($team->getKey());

        $this->assertSame(
            0,
            Audit::query()->where('auditable_type', (new ConcreteTeamUser)->getMorphClass())->count(),
        );
        $this->assertSame(3, Audit::query()->count(), 'Only the two users and the team are audited.');
    }

    public function test_an_update_that_only_ticks_ignored_attributes_writes_no_row(): void
    {
        $this->migrate(true);

        $user = $this->createIgnoringUser();
        $this->travel(1)->minute();

        $user->update(['name' => 'Ticked']);

        $this->assertTrue($user->wasChanged('updated_at'), 'The tick also moved the timestamp.');
        $this->assertSame(0, $this->updateAudits($user)->count());
        $this->assertSame(1, Audit::query()->count(), 'Only the creation is recorded.');
    }

    public function test_a_mixed_update_is_recorded_without_its_ignored_keys(): void
    {
        $this->migrate(true);

        $user = $this->createIgnoringUser();
        $this->travel(1)->minute();

        $user->update([
            'name' => 'Ticked',
            'email' => 'changed@example.com',
        ]);

        $audit = $this->updateAudits($user)->sole();

        $this->assertArrayNotHasKey('name', $audit->old_values);
        $this->assertArrayNotHasKey('name', $audit->new_values);
        $this->assertSame('changed@example.com', $audit->new_values['email']);
        $this->assertSame('ignored@example.com', $audit->old_values['email']);
    }

    public function test_a_config_entry_ignores_attributes_like_the_property(): void
    {
        config()->set('magic-starter.audit.ignore', [
            ConcreteUser::class => ['name'],
        ]);
        $this->migrate(true);

        $user = $this->createUser('ada@example.com');
        $this->travel(1)->minute();

        $user->update(['name' => 'Ticked']);

        $this->assertSame(0, $this->updateAudits($user)->count());

        $user->update(['email' => 'changed@example.com']);

        $this->assertSame(1, $this->updateAudits($user)->count());
    }

    public function test_a_touch_on_a_model_without_an_ignore_list_is_still_recorded(): void
    {
        $this->migrate(true);

        $user = $this->createUser('ada@example.com');
        $this->travel(1)->minute();

        $user->touch();

        $audit = $this->updateAudits($user)->sole();

        $this->assertSame(['updated_at'], array_keys($audit->new_values));
    }

    public function test_a_touch_on_a_model_with_an_ignore_list_is_still_recorded(): void
    {
        // Nothing ignored changed, so the timestamp was not dragged along by a
        // tick: the touch is a change of its own.
        $this->migrate(true);

        $user = $this->createIgnoringUser();
        $this->travel(1)->minute();

        $user->touch();

        $audit = $this->updateAudits($user)->sole();

        $this->assertSame(['updated_at'], array_keys($audit->new_values));
    }

    public function test_a_credential_column_is_recorded_even_when_configured_as_ignored(): void
    {
        config()->set('magic-starter.audit.ignore', [
            ConcreteUser::class => ['name', 'password'],
        ]);
        $this->migrate(true);

        $user = $this->createUser('ada@example.com');
        $this->travel(1)->minute();

        $user->update(['password' => 'another-password']);

        $audit = $this->updateAudits($user)->sole();

        $this->assertSame('[redacted]', $audit->new_values['password']);
    }

    public function test_a_model_without_timestamps_ignores_attributes_too(): void
    {
        $this->migrate(true);

        $owner = $this->createUser('owner@example.com');
        $team = ModelAuditListenerTestUntimedTeam::query()->create([
            'name' => 'Acme',
            'user_id' => $owner->getKey(),
            'personal_team' => false,
        ]);

        $team->update(['name' => 'Ticked']);

        $this->assertSame(0, Audit::query()->where('event', 'updated')->count());

        $team->update(['personal_team' => true]);

        $this->assertSame(1, Audit::query()->where('event', 'updated')->count());
    }

    public function test_the_audit_model_never_audits_itself(): void
    {
        $this->migrate(true);

        $this->createUser('ada@example.com');

        $this->assertSame(0, Audit::query()->where('auditable_type', (new Audit)->getMorphClass())->count());
        $this->assertSame(1, Audit::query()->count());
    }

    public function test_a_class_in_the_exclude_list_is_not_audited(): void
    {
        config()->set('magic-starter.audit.exclude', [
            ConcreteTeam::class,
        ]);
        $this->migrate(true);

        $owner = $this->createUser('owner@example.com');
        ConcreteTeam::query()->create([
            'name' => 'Hidden',
            'user_id' => $owner->getKey(),
            'personal_team' => false,
        ]);

        $this->assertSame(0, Audit::query()->where('auditable_type', (new ConcreteTeam)->getMorphClass())->count());
        $this->assertSame(1, Audit::query()->count());
    }

    public function test_changes_inside_without_auditing_are_not_recorded(): void
    {
        $this->migrate(true);

        $user = Auditor::withoutAuditing(fn (): ConcreteUser => $this->createUser('quiet@example.com'));

        $this->assertInstanceOf(ConcreteUser::class, $user);
        $this->assertSame(0, Audit::query()->count());

        $user->update(['name' => 'Loud']);

        $this->assertSame(1, Audit::query()->count(), 'Auditing resumes once the callback returns.');
    }

    public function test_without_auditing_resumes_after_the_callback_throws(): void
    {
        $this->migrate(true);

        try {
            Auditor::withoutAuditing(function (): never {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
            // The point is what happens next.
        }

        $this->createUser('after@example.com');

        $this->assertSame(1, Audit::query()->count());
    }

    public function test_the_actor_is_the_authenticated_user(): void
    {
        $this->migrate(false);

        $actor = $this->createUser('actor@example.com');
        $this->actingAs($actor);

        $team = ConcreteTeam::query()->create([
            'name' => 'Acme',
            'user_id' => $actor->getKey(),
            'personal_team' => false,
        ]);

        $audit = $this->auditsFor($team)->sole();

        $this->assertSame($actor->getMorphClass(), $audit->actor_type);
        $this->assertSame((string) $actor->getKey(), $audit->actor_id);
    }

    public function test_a_change_without_an_authenticated_user_has_no_actor(): void
    {
        $this->migrate(true);

        $audit = $this->auditsFor($this->createUser('ada@example.com'))->sole();

        $this->assertNull($audit->actor_type);
        $this->assertNull($audit->actor_id);
    }

    public function test_the_context_carries_the_request_without_its_query_string(): void
    {
        $this->migrate(true);

        Route::post('audit-probe', fn (): string => (string) $this->createUser('probe@example.com')->getKey())
            ->name('audit.probe');

        $key = $this->post('audit-probe?token=secret-value', [], ['User-Agent' => 'AuditProbe/1.0'])
            ->assertOk()
            ->getContent();

        $audit = Audit::query()->where('auditable_id', $key)->sole();

        $this->assertSame('127.0.0.1', $audit->context['ip']);
        $this->assertSame('AuditProbe/1.0', $audit->context['user_agent']);
        $this->assertSame('http://localhost/audit-probe', $audit->context['url']);
        $this->assertSame('audit.probe', $audit->context['route']);
        $this->assertStringNotContainsString('secret-value', (string) json_encode($audit->context));
    }

    public function test_nothing_is_written_before_the_transaction_commits(): void
    {
        $this->migrate(true);

        DB::transaction(function (): void {
            $this->createUser('pending@example.com');

            $this->assertSame(0, Audit::query()->count());
        });

        $this->assertSame(1, Audit::query()->count());
    }

    public function test_a_rolled_back_change_leaves_no_audit_row(): void
    {
        $this->migrate(true);

        try {
            DB::transaction(function (): void {
                $this->createUser('ghost@example.com');

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
            // The rollback is the subject of this test.
        }

        $this->assertSame(0, ConcreteUser::query()->count());
        $this->assertSame(0, Audit::query()->count());
    }

    #[DefineEnvironment('withAuditDisabled')]
    public function test_with_the_feature_off_no_model_change_is_recorded(): void
    {
        $this->migrate(true);

        $this->assertFalse(Features::hasAuditFeatures());

        // A team rather than a user: deleting a user purges its own rows, which
        // would let this pass with the listener registered.
        $owner = $this->createUser('ada@example.com');
        $team = ConcreteTeam::query()->create([
            'name' => 'Acme',
            'user_id' => $owner->getKey(),
            'personal_team' => false,
        ]);
        $team->update(['name' => 'Changed']);
        $team->delete();

        $this->assertSame(0, Audit::query()->count());
    }

    private function migrate(bool $useUuids): void
    {
        config()->set('magic-starter.use_uuids', $useUuids);

        foreach ([
            'create_users_table',
            'create_teams_table',
            'create_team_user_table',
            'create_magic_starter_audits_table',
        ] as $migration) {
            (require __DIR__ . "/../../database/migrations/{$migration}.php")->up();
        }
    }

    private function createUser(string $email, string $name = 'User'): ConcreteUser
    {
        return ConcreteUser::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => 'secret-password',
        ]);
    }

    private function createIgnoringUser(): ModelAuditListenerTestIgnoringUser
    {
        return ModelAuditListenerTestIgnoringUser::query()->create([
            'name' => 'User',
            'email' => 'ignored@example.com',
            'password' => 'secret-password',
        ]);
    }

    /**
     * @return Builder<Audit>
     */
    private function updateAudits(ConcreteUser $user): Builder
    {
        return $this->auditsFor($user)->where('event', 'updated');
    }

    /**
     * @return Builder<Audit>
     */
    private function auditsFor(ConcreteUser|ConcreteTeam $model): Builder
    {
        return Audit::query()
            ->where('auditable_type', $model->getMorphClass())
            ->where('auditable_id', (string) $model->getKey());
    }
}

/**
 * A user whose `name` stands in for a per-tick column that must not be audited.
 */
class ModelAuditListenerTestIgnoringUser extends ConcreteUser
{
    protected array $auditIgnore = ['name'];
}

class ModelAuditListenerTestUntimedTeam extends ConcreteTeam
{
    public $timestamps = false;

    protected array $auditIgnore = ['name'];
}
