<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Http\Controllers\TeamPhotoController;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

final class TeamPhotoControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::reset();

        config([
            'database.default' => 'testing',
            'database.connections.testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'magic-starter.models.user' => TestUserForTeamPhoto::class,
            'magic-starter.models.team' => TestTeamForTeamPhoto::class,
            'magic-starter.features' => [
                Features::teams(),
                Features::profilePhotos(),
            ],
        ]);

        $this->loadLaravelMigrations(['--database' => 'testing']);

        $schema = app('db')->connection()->getSchemaBuilder();

        $schema->create('test_teams_for_team_photo', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->index();
            $table->string('name');
            $table->boolean('personal_team');
            $table->string('profile_photo_path')->nullable();
            $table->timestamps();
        });

        Storage::fake('public');

        Gate::policy(
            TestTeamForTeamPhoto::class,
            \FlutterSdk\MagicStarter\Policies\TeamPolicy::class,
        );

        Route::post('/teams/{team}/photo', [TeamPhotoController::class, 'update']);
        Route::delete('/teams/{team}/photo', [TeamPhotoController::class, 'delete']);
    }

    public function test_upload_stores_photo_and_returns_team_resource(): void
    {
        $user = TestUserForTeamPhoto::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        $team = TestTeamForTeamPhoto::create([
            'user_id' => $user->id,
            'name' => 'Test Team',
            'personal_team' => true,
        ]);

        $file = UploadedFile::fake()->image('photo.jpg');

        $response = $this->actingAs($user)->postJson("/teams/{$team->id}/photo", [
            'photo' => $file,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.id', $team->id);

        $team->refresh();

        $this->assertNotEmpty($team->profile_photo_path);
        Storage::disk('public')->assertExists($team->profile_photo_path);
    }

    public function test_upload_replaces_existing_photo(): void
    {
        $user = TestUserForTeamPhoto::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        $team = TestTeamForTeamPhoto::create([
            'user_id' => $user->id,
            'name' => 'Test Team',
            'personal_team' => true,
            'profile_photo_path' => 'team-photos/old.jpg',
        ]);

        Storage::disk('public')->put('team-photos/old.jpg', 'content');

        $file = UploadedFile::fake()->image('new.jpg');

        $response = $this->actingAs($user)->postJson("/teams/{$team->id}/photo", [
            'photo' => $file,
        ]);

        $response->assertOk();

        $team->refresh();

        Storage::disk('public')->assertMissing('team-photos/old.jpg');
        Storage::disk('public')->assertExists($team->profile_photo_path);
        $this->assertNotEquals('team-photos/old.jpg', $team->profile_photo_path);
    }

    public function test_delete_removes_photo_and_clears_path(): void
    {
        $user = TestUserForTeamPhoto::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        $team = TestTeamForTeamPhoto::create([
            'user_id' => $user->id,
            'name' => 'Test Team',
            'personal_team' => true,
            'profile_photo_path' => 'team-photos/test.jpg',
        ]);

        Storage::disk('public')->put('team-photos/test.jpg', 'content');

        $response = $this->actingAs($user)->deleteJson("/teams/{$team->id}/photo");

        $response->assertOk();

        $team->refresh();

        $this->assertNull($team->profile_photo_path);
        Storage::disk('public')->assertMissing('team-photos/test.jpg');
    }

    public function test_delete_when_no_photo_returns_team_without_error(): void
    {
        $user = TestUserForTeamPhoto::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        $team = TestTeamForTeamPhoto::create([
            'user_id' => $user->id,
            'name' => 'Test Team',
            'personal_team' => true,
        ]);

        $response = $this->actingAs($user)->deleteJson("/teams/{$team->id}/photo");

        $response->assertOk();

        $team->refresh();

        $this->assertNull($team->profile_photo_path);
    }

    public function test_upload_returns_403_for_non_owner(): void
    {
        $owner = TestUserForTeamPhoto::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => bcrypt('password'),
        ]);

        $otherUser = TestUserForTeamPhoto::create([
            'name' => 'Other',
            'email' => 'other@example.com',
            'password' => bcrypt('password'),
        ]);

        $team = TestTeamForTeamPhoto::create([
            'user_id' => $owner->id,
            'name' => 'Test Team',
            'personal_team' => true,
        ]);

        $file = UploadedFile::fake()->image('photo.jpg');

        $response = $this->actingAs($otherUser)->postJson("/teams/{$team->id}/photo", [
            'photo' => $file,
        ]);

        $response->assertForbidden();
    }

    public function test_upload_returns_422_for_missing_photo(): void
    {
        $user = TestUserForTeamPhoto::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        $team = TestTeamForTeamPhoto::create([
            'user_id' => $user->id,
            'name' => 'Test Team',
            'personal_team' => true,
        ]);

        $response = $this->actingAs($user)->postJson("/teams/{$team->id}/photo", []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['photo']);
    }

    public function test_routes_not_registered_when_profile_photos_disabled(): void
    {
        config([
            'magic-starter.features' => [
                Features::teams(),
            ],
        ]);

        // Clear existing routes and reload
        Route::setRoutes(new \Illuminate\Routing\RouteCollection);
        require __DIR__ . '/../../../src/routes/api.php';
        Route::getRoutes()->refreshNameLookups();
        $user = TestUserForTeamPhoto::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        $team = TestTeamForTeamPhoto::create([
            'user_id' => $user->id,
            'name' => 'Test Team',
            'personal_team' => true,
        ]);

        $response = $this->actingAs($user)->postJson("/teams/{$team->id}/photo", []);

        $response->assertNotFound();
    }
}

class TestUserForTeamPhoto extends Model implements AuthenticatableContract
{
    use AuthenticatableTrait;
    use Authorizable;
    use \FlutterSdk\MagicStarter\Traits\HasTeams;

    protected $table = 'users';

    protected $fillable = ['name', 'email', 'password', 'current_team_id', 'profile_photo_path'];
}

class TestTeamForTeamPhoto extends Model
{
    use HasUuids;

    protected $table = 'test_teams_for_team_photo';

    protected $fillable = ['user_id', 'name', 'personal_team', 'profile_photo_path'];

    protected function casts(): array
    {
        return [
            'personal_team' => 'boolean',
        ];
    }
}
