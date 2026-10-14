<?php

namespace FlutterSdk\MagicStarter\Tests\Filament;

use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentTimezone;
use FlutterSdk\MagicStarter\Filament\Resources\Users\Pages\ListUsers;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteAdminUser;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * The panel renders times in the signed-in staff user's timezone, else the
 * admin default, else `app.timezone`.
 */
class AdminTimezoneTest extends FilamentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A request boots the panel through its SetUpPanel middleware; a Livewire test skips it.
        Filament::bootCurrentPanel();
    }

    protected function tearDown(): void
    {
        FilamentTimezone::set(null);

        parent::tearDown();
    }

    public function test_a_staff_user_sees_times_in_their_own_timezone(): void
    {
        config()->set('magic-starter.admin.timezone', 'Asia/Tokyo');
        $member = $this->member();

        Livewire::actingAs($this->staff('Europe/Istanbul'))->test(ListUsers::class)
            ->assertTableColumnFormattedStateSet('created_at', $this->formatted('15:00:00'), $member);
    }

    public function test_a_staff_user_on_utc_sees_the_configured_admin_timezone(): void
    {
        config()->set('magic-starter.admin.timezone', 'Asia/Tokyo');
        $member = $this->member();

        Livewire::actingAs($this->staff('UTC'))->test(ListUsers::class)
            ->assertTableColumnFormattedStateSet('created_at', $this->formatted('21:00:00'), $member);
    }

    public function test_a_staff_user_on_the_configured_default_timezone_sees_the_admin_timezone(): void
    {
        config()->set('magic-starter.defaults.timezone', 'Europe/Berlin');
        config()->set('magic-starter.admin.timezone', 'Asia/Tokyo');
        $member = $this->member();

        Livewire::actingAs($this->staff('Europe/Berlin'))->test(ListUsers::class)
            ->assertTableColumnFormattedStateSet('created_at', $this->formatted('21:00:00'), $member);
    }

    public function test_with_neither_the_app_timezone_applies(): void
    {
        config()->set('magic-starter.admin.timezone', null);
        config()->set('app.timezone', 'America/New_York');
        $member = $this->member();

        Livewire::actingAs($this->staff('UTC'))->test(ListUsers::class)
            ->assertTableColumnFormattedStateSet('created_at', $this->formatted('07:00:00'), $member);
    }

    /**
     * The default dateTime format for the fixed 2026-01-15 12:00 UTC reading.
     */
    private function formatted(string $time): string
    {
        return "Jan 15, 2026 {$time}";
    }

    private function staff(string $timezone): ConcreteAdminUser
    {
        config()->set('magic-starter.admin.emails', ['staff@example.com']);

        return ConcreteAdminUser::query()->create([
            'name' => 'Staff',
            'email' => 'staff@example.com',
            'password' => 'secret',
            'email_verified_at' => Carbon::now(),
            'timezone' => $timezone,
        ]);
    }

    private function member(): ConcreteAdminUser
    {
        return ConcreteAdminUser::query()->create([
            'name' => 'Member',
            'email' => 'member@example.com',
            'password' => 'secret',
            'email_verified_at' => Carbon::now(),
            'created_at' => Carbon::parse('2026-01-15 12:00:00', 'UTC'),
        ]);
    }
}
