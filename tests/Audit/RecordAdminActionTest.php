<?php

namespace FlutterSdk\MagicStarter\Tests\Audit;

use FlutterSdk\MagicStarter\Audit\Audit;
use FlutterSdk\MagicStarter\Events\AdminActionPerformed;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\Attributes\DefineEnvironment;

/**
 * An admin panel write becomes one `admin.*` audit row, attributed to the panel
 * user the event names, and only while the audit feature is on.
 */
#[DefineEnvironment('withAuditEnabled')]
final class RecordAdminActionTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function withAuditEnabled($app): void
    {
        $app['config']->set('magic-starter.features', [
            Features::audit(),
        ]);
    }

    /**
     * @param  Application  $app
     */
    protected function withAuditDisabled($app): void
    {
        $app['config']->set('magic-starter.features', []);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('magic-starter.use_uuids', false);

        foreach ([
            'create_users_table',
            'create_magic_starter_audits_table',
        ] as $migration) {
            (require __DIR__ . "/../../database/migrations/{$migration}.php")->up();
        }
    }

    public function test_the_event_is_recorded_as_an_admin_row_for_the_event_actor(): void
    {
        $actor = $this->createUser('operator@example.com');
        $subject = $this->createUser('subject@example.com');

        Event::dispatch(new AdminActionPerformed($actor, 'user.updated', $subject, [
            'fields' => ['name'],
        ]));

        $audit = Audit::query()->where('event', 'admin.user.updated')->sole();

        $this->assertSame((string) $actor->getKey(), $audit->actor_id);
        $this->assertSame($subject->getMorphClass(), $audit->auditable_type);
        $this->assertSame((string) $subject->getKey(), $audit->auditable_id);
        $this->assertSame(['name'], $audit->context['fields']);
    }

    public function test_a_subjectless_event_is_recorded_too(): void
    {
        $actor = $this->createUser('operator@example.com');

        Event::dispatch(new AdminActionPerformed($actor, 'audit.exported', null));

        $audit = Audit::query()->where('event', 'admin.audit.exported')->sole();

        $this->assertSame((string) $actor->getKey(), $audit->actor_id);
        $this->assertNull($audit->auditable_type);
    }

    #[DefineEnvironment('withAuditDisabled')]
    public function test_nothing_is_listening_and_nothing_is_written_while_the_feature_is_off(): void
    {
        $this->assertFalse(Event::hasListeners(AdminActionPerformed::class));

        Event::dispatch(new AdminActionPerformed($this->createUser('operator@example.com'), 'user.updated', null));

        $this->assertSame(0, Audit::query()->count());
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
