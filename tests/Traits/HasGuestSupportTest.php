<?php

namespace FlutterSdk\MagicStarter\Tests\Traits;

use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class HasGuestSupportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->string('phone')->nullable();
            $table->string('device_id')->nullable();
            $table->string('phone_country')->nullable();
            $table->timestamps();
        });
    }

    /** @test */
    public function it_can_determine_if_user_is_guest(): void
    {
        $guest = new ConcreteUser(['is_guest' => true]);
        $registered = new ConcreteUser(['is_guest' => false]);

        $this->assertTrue($guest->isGuest());
        $this->assertFalse($registered->isGuest());
    }

    /** @test */
    public function it_can_determine_if_user_is_registered_via_email(): void
    {
        $registered = new ConcreteUser([
            'is_guest' => false,
            'email' => 'john@example.com',
            'password' => 'password',
        ]);

        $guest = new ConcreteUser(['is_guest' => true]);

        $noPassword = new ConcreteUser([
            'is_guest' => false,
            'email' => 'john@example.com',
        ]);

        $this->assertTrue($registered->isRegistered());
        $this->assertFalse($guest->isRegistered());
        $this->assertFalse($noPassword->isRegistered());
    }

    /** @test */
    public function it_can_determine_if_user_is_registered_via_phone(): void
    {
        $registered = new ConcreteUser([
            'is_guest' => false,
            'phone' => '+1234567890',
            'password' => 'password',
        ]);

        $this->assertTrue($registered->isRegistered());
    }
}
