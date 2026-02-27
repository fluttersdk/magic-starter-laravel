<?php

namespace FlutterSdk\MagicStarter\Tests\Actions;

use FlutterSdk\MagicStarter\Actions\CreateGuestUser;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\TestCase;
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
     * Test that guest user inherits locale and timezone from headers.
     */
    public function test_guest_user_inherits_locale_and_timezone_from_headers(): void
    {
        config(['magic-starter.features' => ['extended-profile']]);
        config(['magic-starter.supported_locales' => ['en', 'tr']]);
        config(['magic-starter.supported_timezones' => ['UTC', 'Europe/Istanbul']]);

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
