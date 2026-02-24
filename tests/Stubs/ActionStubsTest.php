<?php

declare(strict_types=1);

namespace FlutterSdk\MagicStarter\Tests\Stubs;

use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
final class ActionStubsTest extends TestCase
{
    public function test_add_team_member_stub_throws_runtime_exception(): void
    {
        require_once __DIR__ . '/../../stubs/actions/AddTeamMember.php';

        $action = new \App\Actions\MagicStarter\AddTeamMember;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AddTeamMember action not implemented. Publish and implement this stub.');

        $action->add($this->createMock(Authenticatable::class), $this->createMock(Model::class), '', '');
    }

    public function test_create_team_stub_throws_runtime_exception(): void
    {
        require_once __DIR__ . '/../../stubs/actions/CreateTeam.php';

        $action = new \App\Actions\MagicStarter\CreateTeam;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CreateTeam action not implemented. Publish and implement this stub.');

        $action->create($this->createMock(Authenticatable::class), []);
    }

    public function test_create_user_stub_throws_runtime_exception(): void
    {
        require_once __DIR__ . '/../../stubs/actions/CreateUser.php';

        $action = new \App\Actions\MagicStarter\CreateUser;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CreateUser action not implemented. Publish and implement this stub.');

        $action->create([]);
    }

    public function test_delete_team_stub_throws_runtime_exception(): void
    {
        require_once __DIR__ . '/../../stubs/actions/DeleteTeam.php';

        $action = new \App\Actions\MagicStarter\DeleteTeam;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DeleteTeam action not implemented. Publish and implement this stub.');

        $action->delete($this->createMock(Model::class));
    }

    public function test_delete_user_stub_throws_runtime_exception(): void
    {
        require_once __DIR__ . '/../../stubs/actions/DeleteUser.php';

        $action = new \App\Actions\MagicStarter\DeleteUser;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DeleteUser action not implemented. Publish and implement this stub.');

        $action->delete($this->createMock(Authenticatable::class));
    }

    public function test_invite_team_member_stub_throws_runtime_exception(): void
    {
        require_once __DIR__ . '/../../stubs/actions/InviteTeamMember.php';

        $action = new \App\Actions\MagicStarter\InviteTeamMember;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('InviteTeamMember action not implemented. Publish and implement this stub.');

        $action->invite($this->createMock(Authenticatable::class), $this->createMock(Model::class), '', '');
    }

    public function test_remove_team_member_stub_throws_runtime_exception(): void
    {
        require_once __DIR__ . '/../../stubs/actions/RemoveTeamMember.php';

        $action = new \App\Actions\MagicStarter\RemoveTeamMember;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('RemoveTeamMember action not implemented. Publish and implement this stub.');

        $action->remove($this->createMock(Authenticatable::class), $this->createMock(Model::class), $this->createMock(Model::class));
    }

    public function test_update_team_stub_throws_runtime_exception(): void
    {
        require_once __DIR__ . '/../../stubs/actions/UpdateTeam.php';

        $action = new \App\Actions\MagicStarter\UpdateTeam;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('UpdateTeam action not implemented. Publish and implement this stub.');

        $action->update($this->createMock(Authenticatable::class), $this->createMock(Model::class), []);
    }

    public function test_update_user_password_stub_throws_runtime_exception(): void
    {
        require_once __DIR__ . '/../../stubs/actions/UpdateUserPassword.php';

        $action = new \App\Actions\MagicStarter\UpdateUserPassword;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('UpdateUserPassword action not implemented. Publish and implement this stub.');

        $action->update($this->createMock(Authenticatable::class), []);
    }

    public function test_update_user_profile_stub_throws_runtime_exception(): void
    {
        require_once __DIR__ . '/../../stubs/actions/UpdateUserProfile.php';

        $action = new \App\Actions\MagicStarter\UpdateUserProfile;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('UpdateUserProfile action not implemented. Publish and implement this stub.');

        $action->update($this->createMock(Authenticatable::class), []);
    }
}
