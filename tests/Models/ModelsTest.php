<?php

declare(strict_types=1);

namespace FlutterSdk\MagicStarter\Tests\Models;

use FlutterSdk\MagicStarter\Models\Team;
use FlutterSdk\MagicStarter\Models\TeamInvitation;
use FlutterSdk\MagicStarter\Models\TeamUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

class ModelsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'magic-starter.models.user' => ConfiguredUser::class,
            'magic-starter.models.team' => ConfiguredTeam::class,
        ]);
    }

    public function test_team_relationships_resolve_configured_models(): void
    {
        $team = new Team;

        $owner = $team->owner();
        $users = $team->users();
        $invitations = $team->invitations();

        $this->assertSame(ConfiguredUser::class, $owner->getRelated()::class);
        $this->assertSame(ConfiguredUser::class, $users->getRelated()::class);
        $this->assertSame(TeamUser::class, $users->getPivotClass());
        $this->assertSame(TeamInvitation::class, $invitations->getRelated()::class);
    }

    public function test_team_uses_casts_method_for_personal_team(): void
    {
        $reflection = new \ReflectionClass(Team::class);

        $declaredProperties = array_map(
            static fn (\ReflectionProperty $property): string => $property->getName(),
            array_filter(
                $reflection->getProperties(),
                static fn (\ReflectionProperty $property): bool => $property->getDeclaringClass()->getName() === Team::class,
            ),
        );

        $this->assertNotContains('casts', $declaredProperties);

        $castsMethod = $reflection->getMethod('casts');
        $casts = $castsMethod->invoke(new Team);

        $this->assertSame(['personal_team' => 'boolean'], $casts);
    }

    public function test_team_invitation_team_relation_uses_configured_team_model(): void
    {
        $relation = (new TeamInvitation)->team();

        $this->assertSame(ConfiguredTeam::class, $relation->getRelated()::class);
    }

    public function test_team_user_uses_expected_table_and_uuid_key_shape(): void
    {
        $teamUser = new TeamUser;

        $this->assertSame('team_user', $teamUser->getTable());
        $this->assertFalse($teamUser->getIncrementing());
        $this->assertSame('string', $teamUser->getKeyType());
    }

    public function test_personal_access_token_uses_uuid_key_shape(): void
    {
        $class = implode('\\', ['FlutterSdk', 'MagicStarter', 'Models', 'PersonalAccessToken']);

        $this->assertTrue(class_exists($class));

        $token = (new \ReflectionClass($class))->newInstance();

        $this->assertFalse($token->getIncrementing());
        $this->assertSame('string', $token->getKeyType());
        $this->assertTrue(is_subclass_of($class, Model::class));
    }
}

class ConfiguredUser extends Model {}

class ConfiguredTeam extends Model {}
