<?php

namespace FlutterSdk\MagicStarter\Tests\Listeners;

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Listeners\CreatePersonalTeamListener;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeamUser;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Tests for CreatePersonalTeamListener.
 *
 * Verifies personal team creation, localized naming, idempotency,
 * and owner role assignment.
 */
class CreatePersonalTeamListenerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        MagicStarter::reset();

        config([
            'auth.providers.users.model' => ConcreteUser::class,
            'magic-starter.models.user' => ConcreteUser::class,
            'magic-starter.models.team' => ConcreteTeam::class,
            'magic-starter.models.membership' => ConcreteTeamUser::class,
            'magic-starter.features' => [Features::teams()],
        ]);

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('email')->unique()->nullable();
            $table->string('password')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->string('device_id', 255)->unique()->nullable();
            $table->string('phone')->unique()->nullable();
            $table->char('phone_country', 2)->nullable();
            $table->string('locale')->default('en');
            $table->string('timezone')->default('UTC');
            $table->string('current_team_id')->nullable();
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('name');
            $table->boolean('personal_team')->default(false);
            $table->string('profile_photo_path', 2048)->nullable();
            $table->timestamps();
        });

        Schema::create('team_user', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->uuid('user_id');
            $table->string('role')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        MagicStarter::reset();
        parent::tearDown();
    }

    /**
     * Team name uses English translation for English-locale user.
     */
    public function test_creates_team_with_english_localized_name(): void
    {
        $user = ConcreteUser::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'locale' => 'en',
        ]);

        (new CreatePersonalTeamListener)->handle(new Registered($user));

        $this->assertDatabaseHas('teams', [
            'user_id' => $user->id,
            'name' => "John's Team",
            'personal_team' => true,
        ]);
    }

    /**
     * Team name uses Turkish translation for Turkish-locale user.
     */
    public function test_creates_team_with_turkish_localized_name(): void
    {
        $user = ConcreteUser::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Anıl Can',
            'email' => 'anil@example.com',
            'locale' => 'tr',
        ]);

        (new CreatePersonalTeamListener)->handle(new Registered($user));

        $this->assertDatabaseHas('teams', [
            'user_id' => $user->id,
            'name' => 'Anıl Takımı',
            'personal_team' => true,
        ]);
    }

    /**
     * Idempotency: second call does not create a duplicate team.
     */
    public function test_does_not_create_duplicate_personal_team(): void
    {
        $user = ConcreteUser::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
        ]);

        $listener = new CreatePersonalTeamListener;
        $event = new Registered($user);

        $listener->handle($event);
        $listener->handle($event);

        $this->assertDatabaseCount('teams', 1);
    }

    /**
     * Owner role is set on the pivot table.
     */
    public function test_attaches_owner_role_to_team(): void
    {
        $user = ConcreteUser::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Alice',
            'email' => 'alice@example.com',
        ]);

        (new CreatePersonalTeamListener)->handle(new Registered($user));

        $this->assertDatabaseHas('team_user', [
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
    }

    /**
     * Current team ID is set to the newly created personal team.
     */
    public function test_sets_current_team_id(): void
    {
        $user = ConcreteUser::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Bob',
            'email' => 'bob@example.com',
        ]);

        (new CreatePersonalTeamListener)->handle(new Registered($user));

        $user->refresh();

        $team = ConcreteTeam::query()
            ->where('user_id', $user->id)
            ->where('personal_team', true)
            ->firstOrFail();

        $this->assertSame($team->id, $user->current_team_id);
    }
}
