<?php

namespace FlutterSdk\MagicStarter\Tests\Listeners;

use FlutterSdk\MagicStarter\Contracts\BypassesNotificationPreferences;
use FlutterSdk\MagicStarter\Listeners\GateNotificationChannels;
use FlutterSdk\MagicStarter\NotificationPreferenceRegistry;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Notifications\Events\NotificationSending;

class GateNotificationChannelsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        NotificationPreferenceRegistry::flush();
    }

    protected function tearDown(): void
    {
        NotificationPreferenceRegistry::flush();
        parent::tearDown();
    }

    public function test_allows_unregistered_notification_classes(): void
    {
        $listener = new GateNotificationChannels;
        $notifiable = new GateTestNotifiable;
        $notification = new GateTestUnregisteredNotification;
        $event = new NotificationSending($notifiable, $notification, 'mail');

        $this->assertTrue($listener->handle($event));
    }

    public function test_allows_when_notifiable_lacks_prefers_method(): void
    {
        NotificationPreferenceRegistry::register([
            GateTestNotification::class => [
                'label' => 'Test Notification',
                'channels' => ['mail'],
                'default' => ['mail'],
                'locked' => [],
            ],
        ]);

        $listener = new GateNotificationChannels;
        $notifiable = new GateTestNotifiableWithoutPrefers;
        $notification = new GateTestNotification;
        $event = new NotificationSending($notifiable, $notification, 'mail');

        $this->assertTrue($listener->handle($event));
    }

    public function test_allows_channel_when_user_has_it_enabled(): void
    {
        NotificationPreferenceRegistry::register([
            GateTestNotification::class => [
                'label' => 'Test Notification',
                'channels' => ['mail', 'database'],
                'default' => ['mail', 'database'],
                'locked' => [],
            ],
        ]);

        $listener = new GateNotificationChannels;
        $notifiable = new GateTestNotifiable;
        $notification = new GateTestNotification;
        $event = new NotificationSending($notifiable, $notification, 'mail');

        $this->assertTrue($listener->handle($event));
    }

    public function test_blocks_channel_when_user_has_it_disabled(): void
    {
        NotificationPreferenceRegistry::register([
            GateTestNotification::class => [
                'label' => 'Test Notification',
                'channels' => ['mail', 'database'],
                'default' => ['mail', 'database'],
                'locked' => [],
            ],
        ]);

        $listener = new GateNotificationChannels;
        $notifiable = new GateTestNotifiable;
        $notifiable->disabledPreferences = [['gate_test', 'mail']];
        $notification = new GateTestNotification;
        $event = new NotificationSending($notifiable, $notification, 'mail');

        $this->assertFalse($listener->handle($event));
    }

    public function test_allows_locked_channels_even_when_user_disabled(): void
    {
        NotificationPreferenceRegistry::register([
            GateTestNotification::class => [
                'label' => 'Test Notification',
                'channels' => ['database', 'mail'],
                'default' => ['database', 'mail'],
                'locked' => ['database'],
            ],
        ]);

        $listener = new GateNotificationChannels;
        $notifiable = new GateTestNotifiable;
        $notifiable->disabledPreferences = [['gate_test', 'database']];
        $notification = new GateTestNotification;
        $event = new NotificationSending($notifiable, $notification, 'database');

        $this->assertTrue($listener->handle($event));
    }

    public function test_maps_custom_driver_channel_to_logical_name(): void
    {
        NotificationPreferenceRegistry::channelAliases([
            'push' => 'SomeVendor\\PushChannel',
        ]);

        NotificationPreferenceRegistry::register([
            GateTestNotification::class => [
                'label' => 'Test Notification',
                'channels' => ['push'],
                'default' => ['push'],
                'locked' => [],
            ],
        ]);

        $listener = new GateNotificationChannels;
        $notifiable = new GateTestNotifiable;
        $notifiable->disabledPreferences = [['gate_test', 'push']];
        $notification = new GateTestNotification;
        $event = new NotificationSending($notifiable, $notification, 'SomeVendor\\PushChannel');

        $this->assertFalse($listener->handle($event));
    }

    public function test_allows_unknown_channels_not_in_registry(): void
    {
        NotificationPreferenceRegistry::register([
            GateTestNotification::class => [
                'label' => 'Test Notification',
                'channels' => ['mail'],
                'default' => ['mail'],
                'locked' => [],
            ],
        ]);

        $listener = new GateNotificationChannels;
        $notifiable = new GateTestNotifiable;
        $notification = new GateTestNotification;
        $event = new NotificationSending($notifiable, $notification, 'sms');

        $this->assertTrue($listener->handle($event));
    }

    public function test_uses_explicit_slug_from_registry(): void
    {
        NotificationPreferenceRegistry::register([
            GateTestNotification::class => [
                'slug' => 'custom_slug',
                'label' => 'Test Notification',
                'channels' => ['mail'],
                'default' => ['mail'],
                'locked' => [],
            ],
        ]);

        $listener = new GateNotificationChannels;
        $notifiable = new GateTestNotifiable;
        $notifiable->disabledPreferences = [['custom_slug', 'mail']];
        $notification = new GateTestNotification;
        $event = new NotificationSending($notifiable, $notification, 'mail');

        $this->assertFalse($listener->handle($event));
    }

    public function test_uses_auto_derived_slug_when_no_explicit_slug(): void
    {
        NotificationPreferenceRegistry::register([
            GateTestNotification::class => [
                'label' => 'Test Notification',
                'channels' => ['mail'],
                'default' => ['mail'],
                'locked' => [],
            ],
        ]);

        $listener = new GateNotificationChannels;
        $notifiable = new GateTestNotifiable;
        // Auto-derived: GateTestNotification -> gate_test
        $notifiable->disabledPreferences = [['gate_test', 'mail']];
        $notification = new GateTestNotification;
        $event = new NotificationSending($notifiable, $notification, 'mail');

        $this->assertFalse($listener->handle($event));
    }

    public function test_allows_disabled_channel_the_notification_bypasses(): void
    {
        $this->registerBypassingNotification();

        $listener = new GateNotificationChannels;
        $notifiable = new GateTestNotifiable;
        $notifiable->disabledPreferences = [['gate_test_bypassing', 'mail']];
        $notification = new GateTestBypassingNotification(['mail']);
        $event = new NotificationSending($notifiable, $notification, 'mail');

        $this->assertTrue($listener->handle($event));
    }

    public function test_does_not_bypass_a_channel_the_notification_did_not_name(): void
    {
        $this->registerBypassingNotification();

        $listener = new GateNotificationChannels;
        $notifiable = new GateTestNotifiable;
        $notifiable->disabledPreferences = [['gate_test_bypassing', 'push']];
        $notification = new GateTestBypassingNotification(['mail']);
        $event = new NotificationSending($notifiable, $notification, 'push');

        $this->assertFalse($listener->handle($event));
    }

    public function test_bypass_receives_the_notifiable_and_logical_channel(): void
    {
        NotificationPreferenceRegistry::channelAliases([
            'push' => 'SomeVendor\\PushChannel',
        ]);
        $this->registerBypassingNotification();

        $listener = new GateNotificationChannels;
        $notifiable = new GateTestNotifiable;
        $notifiable->disabledPreferences = [['gate_test_bypassing', 'push']];
        $notification = new GateTestBypassingNotification(['push']);
        $event = new NotificationSending($notifiable, $notification, 'SomeVendor\\PushChannel');

        $this->assertTrue($listener->handle($event));
        $this->assertSame([[$notifiable, 'push']], $notification->asked);
    }

    public function test_still_gates_a_notification_without_the_bypass_contract(): void
    {
        NotificationPreferenceRegistry::register([
            GateTestNotification::class => [
                'label' => 'Test Notification',
                'channels' => ['mail'],
                'default' => ['mail'],
                'locked' => [],
            ],
        ]);

        $listener = new GateNotificationChannels;
        $notifiable = new GateTestNotifiable;
        $notifiable->disabledPreferences = [['gate_test', 'mail']];
        $notification = new GateTestNotification;
        $event = new NotificationSending($notifiable, $notification, 'mail');

        $this->assertFalse($listener->handle($event));
    }

    private function registerBypassingNotification(): void
    {
        NotificationPreferenceRegistry::register([
            GateTestBypassingNotification::class => [
                'slug' => 'gate_test_bypassing',
                'label' => 'Test Notification',
                'channels' => ['mail', 'push'],
                'default' => ['mail', 'push'],
                'locked' => [],
            ],
        ]);
    }
}

class GateTestNotification extends \Illuminate\Notifications\Notification
{
    //
}

class GateTestBypassingNotification extends \Illuminate\Notifications\Notification implements BypassesNotificationPreferences
{
    /** @var list<array{0: object, 1: string}> */
    public array $asked = [];

    /**
     * @param  list<string>  $bypassed
     */
    public function __construct(private array $bypassed) {}

    public function bypassesPreference(object $notifiable, string $logicalChannel): bool
    {
        $this->asked[] = [$notifiable, $logicalChannel];

        return in_array($logicalChannel, $this->bypassed, true);
    }
}

class GateTestUnregisteredNotification extends \Illuminate\Notifications\Notification
{
    //
}

class GateTestNotifiable
{
    public array $disabledPreferences = [];

    public function prefers(string $type, string $channel): bool
    {
        foreach ($this->disabledPreferences as $pref) {
            if ($pref[0] === $type && $pref[1] === $channel) {
                return false;
            }
        }

        return true;
    }
}

class GateTestNotifiableWithoutPrefers
{
    //
}
