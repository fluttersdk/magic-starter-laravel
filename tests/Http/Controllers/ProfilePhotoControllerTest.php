<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Http\Controllers\ProfilePhotoController;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\UploadedFile;

final class ProfilePhotoControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::reset();

        \call_user_func('config', ['database.default' => 'testing']);
        \call_user_func('config', ['database.connections.testing' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);
        \call_user_func('config', [
            'magic-starter.models.user' => ProfilePhotoControllerTestUser::class,
            'magic-starter.models.team' => ProfilePhotoControllerTestTeam::class,
        ]);

        \call_user_func('config', ['magic-starter.profile_photo_disk' => 'profile-photos']);
        \call_user_func('config', ['filesystems.disks.profile-photos' => [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/profile-photos'),
            'url' => 'https://cdn.example.test/profile-photos',
            'visibility' => 'public',
        ]]);

        \call_user_func([\call_user_func('app', 'db.schema'), 'create'], 'users', function ($table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('profile_photo_path')->nullable();
            $table->string('locale')->nullable();
            $table->string('timezone')->nullable();
            $table->string('language')->nullable();
            $table->string('current_team_id')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });

        \call_user_func('app', 'router')->post('/user/profile-photo', [ProfilePhotoController::class, 'update']);
        \call_user_func('app', 'router')->delete('/user/profile-photo', [ProfilePhotoController::class, 'delete']);
    }

    public function test_update_replaces_old_profile_photo_and_returns_user_resource(): void
    {
        $filesystem = \call_user_func('app', 'filesystem')->disk('profile-photos');

        $user = ProfilePhotoControllerTestUser::query()->create([
            'name' => 'Photo User',
            'email' => 'photo@example.test',
            'profile_photo_path' => 'profile-photos/old-photo.jpg',
        ]);

        \call_user_func([$filesystem, 'put'], 'profile-photos/old-photo.jpg', 'old');

        $response = $this->actingAs($user)
            ->post('/user/profile-photo', [
                'photo' => UploadedFile::fake()->image('avatar.jpg', 120, 120),
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $user->getKey())
            ->assertJsonPath('data.email', 'photo@example.test');

        $updatedPath = (string) $user->fresh()->profile_photo_path;

        $this->assertNotSame('profile-photos/old-photo.jpg', $updatedPath);
        $this->assertFalse(\call_user_func([$filesystem, 'exists'], 'profile-photos/old-photo.jpg'));
        $this->assertTrue(\call_user_func([$filesystem, 'exists'], $updatedPath));
    }

    public function test_delete_removes_profile_photo_path_and_file(): void
    {
        $filesystem = \call_user_func('app', 'filesystem')->disk('profile-photos');

        $user = ProfilePhotoControllerTestUser::query()->create([
            'name' => 'Photo User',
            'email' => 'photo@example.test',
            'profile_photo_path' => 'profile-photos/existing-photo.jpg',
        ]);

        \call_user_func([$filesystem, 'put'], 'profile-photos/existing-photo.jpg', 'content');

        $this->actingAs($user)
            ->deleteJson('/user/profile-photo')
            ->assertOk()
            ->assertJsonPath('data.id', $user->getKey());

        $this->assertNull($user->fresh()->profile_photo_path);
        $this->assertFalse(\call_user_func([$filesystem, 'exists'], 'profile-photos/existing-photo.jpg'));
    }
}

final class ProfilePhotoControllerTestUser extends Authenticatable
{
    use HasUuids;

    protected $table = 'users';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function allTeams()
    {
        return collect();
    }

    public function getCurrentTeamOrPersonal(): mixed
    {
        return null;
    }
}

final class ProfilePhotoControllerTestTeam extends \Illuminate\Database\Eloquent\Model
{
    use HasUuids;

    protected $table = 'teams';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
