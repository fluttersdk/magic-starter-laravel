<?php

namespace FlutterSdk\MagicStarter\Tests\Actions;

use FlutterSdk\MagicStarter\Actions\CreateGuestUser;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

class CreateGuestUserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function ($table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('email')->unique()->nullable();
            $table->string('password')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->string('device_id')->unique()->nullable();
            $table->string('phone')->unique()->nullable();
            $table->string('locale')->default('en');
            $table->string('timezone')->default('UTC');
            $table->timestamps();
        });
    }

    /**
     * Test that a guest user can be created with a device ID.
     */
    public function test_guest_user_is_created_with_device_id(): void
    {
        $action = new CreateGuestUser;

        $user = $action->create([
            'device_id' => 'device-123',
        ]);

        $this->assertEquals('Guest', $user->name);
        $this->assertNull($user->email);
        $this->assertTrue((bool) $user->is_guest);
        $this->assertEquals('device-123', $user->device_id);
    }

    /**
     * Test that guest user creation is idempotent (same device_id = same user).
     */
    public function test_guest_user_creation_is_idempotent(): void
    {
        $action = new CreateGuestUser;

        $user1 = $action->create([
            'device_id' => 'device-123',
        ]);

        $user2 = $action->create([
            'device_id' => 'device-123',
        ]);

        $this->assertEquals($user1->id, $user2->id);
        $this->assertEquals(1, MagicStarter::userModel()::count());
    }

    /**
     * Regression: is_guest and device_id are system-managed and intentionally
     * absent from the published User stub's $fillable (making is_guest
     * mass-assignable would let a crafted payload flag any account as a guest).
     * firstOrCreate() went through mass-assignment and silently dropped both, so
     * guests persisted with is_guest = false and a null device_id, which also
     * broke the find-existing idempotency. The action must forceFill. The prior
     * tests used a fully unguarded fixture and masked the bug.
     */
    public function test_guest_user_persists_is_guest_and_device_id_when_user_model_guards_them(): void
    {
        MagicStarter::useUserModel(CreateGuestUserGuardedUser::class);

        $action = new CreateGuestUser;

        $user = $action->create([
            'device_id' => 'guarded-device-1',
        ]);

        $fresh = CreateGuestUserGuardedUser::query()->find($user->id);
        $this->assertTrue((bool) $fresh->is_guest);
        $this->assertSame('guarded-device-1', $fresh->device_id);

        // Idempotency must still hold against the guarded model (a null device_id
        // would have created a second user on the next call).
        $again = $action->create([
            'device_id' => 'guarded-device-1',
        ]);

        $this->assertSame($user->id, $again->id);
        $this->assertSame(1, CreateGuestUserGuardedUser::query()->count());
    }

    /**
     * Test that guest user inherits locale and timezone from headers.
     */
    public function test_guest_user_inherits_locale_and_timezone_from_headers(): void
    {
        config(['magic-starter.features' => ['extended-profile']]);
        config(['magic-starter.supported_locales' => ['en', 'tr']]);

        request()->headers->set('Accept-Language', 'tr');
        request()->headers->set('X-Timezone', 'Europe/Istanbul');

        $action = new CreateGuestUser;

        $user = $action->create([
            'device_id' => 'device-123',
        ]);

        $this->assertEquals('tr', $user->locale);
        $this->assertEquals('Europe/Istanbul', $user->timezone);
    }
}

/**
 * Mirrors the published User stub: is_guest, device_id, and current_team_id are
 * system-managed and intentionally absent from $fillable, so a plain
 * mass-assignment cannot persist them.
 */
final class CreateGuestUserGuardedUser extends Model implements AuthenticatableContract
{
    use AuthenticatableTrait;
    use HasUuids;

    protected $table = 'users';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'phone_country',
        'locale',
        'timezone',
    ];
}
