<?php

namespace FlutterSdk\MagicStarter\Tests\Audit;

use FlutterSdk\MagicStarter\Audit\Audit;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * Deleting the user model erases the trail about that person: rows about the
 * user or related to them go, and rows they acted in lose the actor key while
 * keeping what happened.
 */
final class UserPurgeAuditTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('magic-starter.features', [
            Features::teams(),
            Features::audit(),
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
    public function test_deleting_a_user_leaves_no_row_holding_their_email(bool $useUuids): void
    {
        $this->migrate($useUuids);

        // 1. A trail about the user: created, an email change, a team of their own.
        $user = $this->createUser('leaving@example.com');
        $user->update(['email' => 'still-leaving@example.com']);
        $ownTeam = ConcreteTeam::query()->create([
            'name' => 'Leaving Personal',
            'user_id' => $user->getKey(),
            'personal_team' => true,
        ]);

        // 2. A row the user acted in, about somebody else's team.
        $other = $this->createUser('staying@example.com');
        $this->actingAs($user);
        $othersTeam = ConcreteTeam::query()->create([
            'name' => 'Staying Team',
            'user_id' => $other->getKey(),
            'personal_team' => false,
        ]);

        // 3. The purge, inside a transaction the way DeleteUser runs it.
        DB::transaction(function () use ($user, $ownTeam): void {
            $ownTeam->delete();
            $user->delete();
        });

        $stored = (string) json_encode(Audit::query()->get()->toArray());

        $this->assertStringNotContainsString('leaving@example.com', $stored);
        $this->assertStringNotContainsString('still-leaving@example.com', $stored);
        $this->assertStringNotContainsString('Leaving Personal', $stored);
        $this->assertSame(0, Audit::query()->where('related_user_id', (string) $user->getKey())->count());
        $this->assertSame(0, Audit::query()
            ->where('auditable_type', $user->getMorphClass())
            ->where('auditable_id', (string) $user->getKey())
            ->count());

        $acted = Audit::query()
            ->where('auditable_type', $othersTeam->getMorphClass())
            ->where('auditable_id', (string) $othersTeam->getKey())
            ->sole();

        $this->assertNull($acted->actor_id);
        $this->assertSame($user->getMorphClass(), $acted->actor_type);
        $this->assertSame('Staying Team', $acted->new_values['name']);

        $this->assertSame(1, Audit::query()
            ->where('auditable_type', $other->getMorphClass())
            ->where('auditable_id', (string) $other->getKey())
            ->count(), 'Another user\'s trail survives.');
    }

    public function test_a_rolled_back_deletion_keeps_the_trail(): void
    {
        $this->migrate(false);

        $user = $this->createUser('kept@example.com');

        try {
            DB::transaction(function () use ($user): void {
                $user->delete();

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
            // The rollback is the subject of this test.
        }

        $this->assertSame(1, Audit::query()
            ->where('auditable_type', $user->getMorphClass())
            ->where('auditable_id', (string) $user->getKey())
            ->count());
    }

    public function test_a_team_sharing_the_user_integer_key_keeps_its_rows(): void
    {
        $this->migrate(false);

        $owner = $this->createUser('owner@example.com');
        $leaving = $this->createUser('leaving@example.com');
        $team = ConcreteTeam::query()->create([
            'name' => 'Same Key',
            'user_id' => $owner->getKey(),
            'personal_team' => false,
        ]);
        ConcreteTeam::query()->create([
            'name' => 'Second',
            'user_id' => $owner->getKey(),
            'personal_team' => false,
        ]);

        $this->assertSame((string) $leaving->getKey(), (string) ConcreteTeam::query()->latest('id')->value('id'));

        $leaving->delete();

        $this->assertSame(2, Audit::query()->where('auditable_type', $team->getMorphClass())->count());
    }

    private function migrate(bool $useUuids): void
    {
        config()->set('magic-starter.use_uuids', $useUuids);

        foreach ([
            'create_users_table',
            'create_teams_table',
            'create_magic_starter_audits_table',
        ] as $migration) {
            (require __DIR__ . "/../../database/migrations/{$migration}.php")->up();
        }
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
