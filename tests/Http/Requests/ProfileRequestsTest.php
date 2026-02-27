<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Requests;

use FlutterSdk\MagicStarter\Contracts\DeletesUsers;
use FlutterSdk\MagicStarter\Contracts\UpdatesUserPasswords;
use FlutterSdk\MagicStarter\Contracts\UpdatesUserProfiles;
use FlutterSdk\MagicStarter\Http\Controllers\ProfileController;
use FlutterSdk\MagicStarter\Http\Controllers\ProfilePhotoController;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

final class ProfileRequestsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::reset();

        \call_user_func('config', [
            'database.default' => 'testing',
            'database.connections.testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'magic-starter.models.user' => ProfileRequestsTestUser::class,
            'magic-starter.models.team' => ProfileRequestsTestTeam::class,
            'magic-starter.profile_photo_disk' => 'public',
        ]);

        \call_user_func('config', [
            'magic-starter.supported_locales' => [
                'en',
                'tr',
                'de',
            ],
            'magic-starter.supported_timezones' => [
                'UTC',
                'Europe/Istanbul',
                'Europe/London',
                'America/New_York',
            ],
        ]);

        \call_user_func('config', [
            'magic-starter.supported_locales' => [
                'en',
                'tr',
                'de',
            ],
            'magic-starter.supported_timezones' => [
                'UTC',
                'Europe/Istanbul',
                'Europe/London',
                'America/New_York',
            ],
        ]);

        \call_user_func([\call_user_func('app', 'db.schema'), 'create'], 'users', function ($table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->string('locale')->nullable();
            $table->string('timezone')->nullable();
            $table->string('language')->nullable();
            $table->string('profile_photo_path')->nullable();
            $table->string('current_team_id')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });

        \call_user_func([\call_user_func('app', 'db.schema'), 'create'], 'personal_access_tokens', function ($table): void {
            $table->uuid('id')->primary();
            $table->string('tokenable_type');
            $table->uuid('tokenable_id');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });

        \call_user_func('app')->instance(UpdatesUserProfiles::class, new class implements UpdatesUserProfiles
        {
            public function update(\Illuminate\Contracts\Auth\Authenticatable $user, array $input): void
            {
                $user->forceFill($input)->save();
            }
        });

        \call_user_func('app')->instance(UpdatesUserPasswords::class, new class implements UpdatesUserPasswords
        {
            public function update(\Illuminate\Contracts\Auth\Authenticatable $user, array $input): void
            {
                $user->forceFill([
                    'password' => \password_hash((string) ($input['password'] ?? ''), PASSWORD_BCRYPT),
                ])->save();
            }
        });

        \call_user_func('app')->instance(DeletesUsers::class, new class implements DeletesUsers
        {
            public function delete(\Illuminate\Contracts\Auth\Authenticatable $user): void
            {
                $user->tokens()->delete();
                $user->delete();
            }
        });

        \call_user_func('app', 'router')->put('/user/profile', [ProfileController::class, 'update']);
        \call_user_func('app', 'router')->put('/user/password', [ProfileController::class, 'updatePassword']);
        \call_user_func('app', 'router')->match(['post', 'delete'], '/user', [ProfileController::class, 'destroy']);
        \call_user_func('app', 'router')->post('/user/profile-photo', [ProfilePhotoController::class, 'update']);
    }

    private function createAuthenticatedUser(): ProfileRequestsTestUser
    {
        return ProfileRequestsTestUser::query()->create([
            'name' => 'Test User',
            'email' => 'user@example.test',
            'phone' => '+1234567890',
            'timezone' => 'UTC',
            'language' => 'en',
            'password' => Hash::make('Password123'),
        ]);
    }

    public function test_update_profile_missing_name_returns_422(): void
    {
        $user = $this->createAuthenticatedUser();

        $this->actingAs($user)
            ->putJson('/user/profile', [
                'phone' => '+1234567890',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_update_profile_name_too_short_returns_422(): void
    {
        $user = $this->createAuthenticatedUser();

        $this->actingAs($user)
            ->putJson('/user/profile', [
                'name' => 'A',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_update_profile_invalid_phone_format_returns_422(): void
    {
        $user = $this->createAuthenticatedUser();

        $this->actingAs($user)
            ->putJson('/user/profile', [
                'name' => 'Valid Name',
                'phone' => 'not-a-phone-abc',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone']);
    }

    public function test_update_profile_invalid_timezone_returns_422(): void
    {
        $user = $this->createAuthenticatedUser();

        $this->actingAs($user)
            ->putJson('/user/profile', [
                'name' => 'Valid Name',
                'timezone' => 'Invalid/Timezone',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['timezone']);
    }

    public function test_update_profile_invalid_language_format_returns_422(): void
    {
        $user = $this->createAuthenticatedUser();

        $this->actingAs($user)
            ->putJson('/user/profile', [
                'name' => 'Valid Name',
                'language' => 'INVALID',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['language']);
    }

    public function test_update_password_missing_current_password_returns_422(): void
    {
        $user = $this->createAuthenticatedUser();

        $this->actingAs($user)
            ->putJson('/user/password', [
                'password' => 'newpassword1',
                'password_confirmation' => 'newpassword1',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password']);
    }

    public function test_update_password_missing_password_returns_422(): void
    {
        $user = $this->createAuthenticatedUser();

        $this->actingAs($user)
            ->putJson('/user/password', [
                'current_password' => 'Password123',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_delete_account_missing_password_returns_422(): void
    {
        $user = $this->createAuthenticatedUser();

        $this->actingAs($user)
            ->deleteJson('/user', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_update_profile_photo_missing_photo_returns_422(): void
    {
        Storage::fake('public');

        $user = $this->createAuthenticatedUser();

        $this->actingAs($user)
            ->postJson('/user/profile-photo', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['photo']);
    }

    public function test_update_profile_photo_non_image_file_returns_422(): void
    {
        Storage::fake('public');

        $user = $this->createAuthenticatedUser();

        $this->actingAs($user)
            ->postJson('/user/profile-photo', [
                'photo' => UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['photo']);
    }

    public function test_update_profile_rejects_non_e164_phone_formats(): void
    {
        $user = $this->createAuthenticatedUser();
        $invalidPhones = [
            '555-1234',
            '(555) 123-4567',
            '12345',
            '+0123456789',
        ];
        foreach ($invalidPhones as $phone) {
            $this->actingAs($user)
                ->putJson('/user/profile', [
                    'name' => 'Valid Name',
                    'phone' => $phone,
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['phone']);
        }
    }

    public function test_update_profile_accepts_valid_e164_phone(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user)
            ->putJson('/user/profile', [
                'name' => 'Valid Name',
                'phone' => '+14155552671',
            ])
            ->assertOk();
    }

    public function test_update_profile_rejects_unsupported_language(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user)
            ->putJson('/user/profile', [
                'name' => 'Valid Name',
                'language' => 'xx',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['language']);
    }

    public function test_update_profile_accepts_supported_language(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user)
            ->putJson('/user/profile', [
                'name' => 'Valid Name',
                'language' => 'tr',
            ])
            ->assertOk();
    }

    public function test_update_profile_accepts_supported_timezone(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user)
            ->putJson('/user/profile', [
                'name' => 'Valid Name',
                'timezone' => 'Europe/Istanbul',
            ])
            ->assertOk();
    }
}

final class ProfileRequestsTestUser extends Authenticatable
{
    use HasUuids;

    protected $table = 'users';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function tokens()
    {
        return $this->morphMany(ProfileRequestsTestToken::class, 'tokenable');
    }

    public function allTeams()
    {
        return collect();
    }

    public function getCurrentTeamOrPersonal(): mixed
    {
        return null;
    }
}

final class ProfileRequestsTestToken extends Model
{
    use HasUuids;

    protected $table = 'personal_access_tokens';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}

final class ProfileRequestsTestTeam extends Model
{
    use HasUuids;

    protected $table = 'teams';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
