<?php

namespace FlutterSdk\MagicStarter\Tests\Http;

use FlutterSdk\MagicStarter\Actions\AddTeamMember;
use FlutterSdk\MagicStarter\Http\Controllers\AuthController;
use FlutterSdk\MagicStarter\Http\Controllers\PasswordResetController;
use FlutterSdk\MagicStarter\Http\Controllers\ProfileController;
use FlutterSdk\MagicStarter\Http\Controllers\TeamInvitationController;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Notifications\TeamInvitationNotification;
use FlutterSdk\MagicStarter\Policies\TeamPolicy;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use FlutterSdk\MagicStarter\Traits\NormalizesEmail;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Passwords\CanResetPassword as CanResetPasswordTrait;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * Pins that an email is compared case-insensitively on every write and lookup.
 *
 * SQLite and PostgreSQL both compare strings case-sensitively, so every case here
 * fails on a tree that only trusts the database. Rows are asserted, not only
 * status codes: a 200 that stored `Mixed@Example.COM` is the bug.
 */
final class EmailNormalizationTest extends TestCase
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
            'app.key' => 'base64:' . base64_encode(str_repeat('k', 32)),
            'auth.providers.users.model' => EmailNormalizationUser::class,
            'magic-starter.models.user' => EmailNormalizationUser::class,
            'magic-starter.models.team' => EmailNormalizationTeam::class,
            'magic-starter.features' => ['teams'],
        ]);

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->boolean('is_guest')->default(false);
            $table->string('device_id')->unique()->nullable();
            $table->string('phone')->nullable()->unique();
            $table->char('phone_country', 2)->nullable();
            $table->string('locale')->default('en');
            $table->string('timezone')->default('UTC');
            $table->foreignUuid('current_team_id')->nullable();
            $table->string('profile_photo_path', 2048)->nullable();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
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

        Schema::create('team_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->string('email');
            $table->string('role')->nullable()->default('member');
            $table->string('token')->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Gate::policy(EmailNormalizationTeam::class, TeamPolicy::class);

        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLinkEmail']);
        Route::put('/profile', [ProfileController::class, 'update']);
        Route::post('/teams/{team}/invitations', [TeamInvitationController::class, 'store']);
        Route::post('/invitations/{token}/accept', [TeamInvitationController::class, 'accept']);
    }

    protected function tearDown(): void
    {
        MagicStarter::reset();
        parent::tearDown();
    }

    public function test_register_stores_the_email_in_lower_case(): void
    {
        $this->postJson('/register', $this->registration('Mixed@Example.COM'))
            ->assertCreated()
            ->assertJsonPath('data.user.email', 'mixed@example.com');

        $this->assertSame(['mixed@example.com'], EmailNormalizationUser::query()->pluck('email')->all());
    }

    public function test_login_matches_the_email_regardless_of_case(): void
    {
        $this->makeUser('mixed@example.com');

        $this->postJson('/login', ['email' => 'MIXED@example.com', 'password' => 'Password123'])
            ->assertOk()
            ->assertJsonPath('data.user.email', 'mixed@example.com');
    }

    public function test_registering_the_same_email_in_another_case_fails_unique(): void
    {
        $this->postJson('/register', $this->registration('mixed@example.com'))->assertCreated();

        $this->postJson('/register', $this->registration('mixed@EXAMPLE.com'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertSame(1, EmailNormalizationUser::query()->count());
    }

    public function test_forgot_password_sends_the_link_whatever_the_case(): void
    {
        Notification::fake();
        $user = $this->makeUser('mixed@example.com');

        $this->postJson('/forgot-password', ['email' => 'Mixed@Example.COM'])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_profile_update_rejects_an_email_another_account_holds_in_another_case(): void
    {
        $this->makeUser('taken@example.com');
        $user = $this->makeUser('mine@example.com');

        $this->actingAs($user)
            ->putJson('/profile', ['name' => 'Mine', 'email' => 'TAKEN@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_profile_update_stores_the_email_in_lower_case(): void
    {
        $user = $this->makeUser('mine@example.com');

        $this->actingAs($user)
            ->putJson('/profile', ['name' => 'Mine', 'email' => 'New@Example.COM'])
            ->assertOk();

        $this->assertSame('new@example.com', $user->fresh()->email);
    }

    public function test_invitation_to_a_mixed_case_email_is_stored_lower_case_and_accepted_by_the_lower_case_user(): void
    {
        Notification::fake();
        $owner = $this->makeUser('owner@example.com');
        $bob = $this->makeUser('bob@x.io');
        $team = EmailNormalizationTeam::query()->create(['user_id' => $owner->id, 'name' => 'Team', 'personal_team' => false]);

        $this->actingAs($owner)
            ->postJson('/teams/' . $team->id . '/invitations', ['email' => 'Bob@x.io', 'role' => 'member'])
            ->assertCreated();

        $invitation = $team->invitations()->sole();
        $this->assertSame('bob@x.io', $invitation->email);

        $this->actingAs($bob)->postJson('/invitations/' . $invitation->token . '/accept')->assertOk();

        $this->assertTrue($team->users()->where('user_id', $bob->id)->exists());
        Notification::assertSentOnDemand(TeamInvitationNotification::class);
    }

    public function test_a_second_invitation_in_another_case_is_refused_as_already_sent(): void
    {
        Notification::fake();
        $owner = $this->makeUser('owner@example.com');
        $team = EmailNormalizationTeam::query()->create(['user_id' => $owner->id, 'name' => 'Team', 'personal_team' => false]);

        $this->actingAs($owner)
            ->postJson('/teams/' . $team->id . '/invitations', ['email' => 'bob@x.io', 'role' => 'member'])
            ->assertCreated();

        $this->actingAs($owner)
            ->postJson('/teams/' . $team->id . '/invitations', ['email' => 'BOB@x.io', 'role' => 'member'])
            ->assertUnprocessable();

        $this->assertSame(1, $team->invitations()->count());
    }

    public function test_adding_a_team_member_finds_the_user_regardless_of_case(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $member = $this->makeUser('member@example.com');
        $team = EmailNormalizationTeam::query()->create(['user_id' => $owner->id, 'name' => 'Team', 'personal_team' => false]);

        (new AddTeamMember)->add($owner, $team, 'MEMBER@Example.com', 'editor');

        $this->assertTrue($team->users()->where('user_id', $member->id)->exists());
    }

    public function test_the_model_mutator_lower_cases_a_string_and_leaves_null_alone(): void
    {
        $withEmail = EmailNormalizationUser::query()->create(['name' => 'A', 'email' => 'A@B.Io']);
        $phoneOnly = EmailNormalizationUser::query()->create(['name' => 'B', 'email' => null, 'phone' => '+905550000000']);

        $this->assertSame('a@b.io', $withEmail->fresh()->email);
        $this->assertNull($phoneOnly->fresh()->email);
    }

    /**
     * @return array<string, mixed>
     */
    private function registration(string $email): array
    {
        return [
            'name' => 'Mixed Case',
            'email' => $email,
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ];
    }

    private function makeUser(string $email): EmailNormalizationUser
    {
        return EmailNormalizationUser::query()->create([
            'name' => 'User',
            'email' => $email,
            'password' => Hash::make('Password123'),
        ]);
    }
}

/**
 * The fixture user with the trait a consumer's User model adopts.
 */
final class EmailNormalizationUser extends ConcreteUser implements CanResetPassword
{
    use CanResetPasswordTrait;
    use NormalizesEmail;
    use Notifiable;
}

/**
 * The fixture team, keyed by `team_id` the way a consumer's `Team` model is.
 */
final class EmailNormalizationTeam extends ConcreteTeam
{
    public function invitations(): HasMany
    {
        return $this->hasMany(MagicStarter::teamInvitationModel(), 'team_id');
    }
}
