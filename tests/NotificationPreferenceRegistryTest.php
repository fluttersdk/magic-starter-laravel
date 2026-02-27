<?php

namespace FlutterSdk\MagicStarter\Tests;

use FlutterSdk\MagicStarter\NotificationPreferenceRegistry;

class NotificationPreferenceRegistryTest extends TestCase
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

    public function test_register_stores_notification_types(): void
    {
        NotificationPreferenceRegistry::register([
            'monitor_down' => [
                'label' => 'Monitor Down',
                'channels' => ['database', 'mail', 'push'],
                'default' => ['database', 'mail', 'push'],
                'locked' => ['database'],
            ],
        ]);

        $type = NotificationPreferenceRegistry::get('monitor_down');

        $this->assertIsArray($type);
        $this->assertSame('Monitor Down', $type['label']);
        $this->assertSame(['database', 'mail', 'push'], $type['channels']);
    }

    public function test_all_returns_all_registered_types(): void
    {
        NotificationPreferenceRegistry::register([
            'monitor_down' => [
                'label' => 'Monitor Down',
                'channels' => ['database', 'mail', 'push'],
                'default' => ['database', 'mail', 'push'],
                'locked' => ['database'],
            ],
            'incident_update' => [
                'label' => 'Incident Update',
                'channels' => ['database', 'mail'],
                'default' => ['database'],
                'locked' => [],
            ],
        ]);

        $all = NotificationPreferenceRegistry::all();

        $this->assertCount(2, $all);
        $this->assertArrayHasKey('monitor_down', $all);
        $this->assertArrayHasKey('incident_update', $all);
    }

    public function test_channels_returns_channels_for_type(): void
    {
        NotificationPreferenceRegistry::register([
            'monitor_down' => [
                'label' => 'Monitor Down',
                'channels' => ['database', 'mail', 'push'],
                'default' => ['database', 'mail', 'push'],
                'locked' => ['database'],
            ],
        ]);

        $this->assertSame(
            ['database', 'mail', 'push'],
            NotificationPreferenceRegistry::channels('monitor_down'),
        );
    }

    public function test_defaults_returns_default_channels_for_type(): void
    {
        NotificationPreferenceRegistry::register([
            'incident_update' => [
                'label' => 'Incident Update',
                'channels' => ['database', 'mail'],
                'default' => ['database'],
                'locked' => [],
            ],
        ]);

        $this->assertSame(
            ['database'],
            NotificationPreferenceRegistry::defaults('incident_update'),
        );
    }

    public function test_locked_returns_locked_channels_for_type(): void
    {
        NotificationPreferenceRegistry::register([
            'monitor_down' => [
                'label' => 'Monitor Down',
                'channels' => ['database', 'mail', 'push'],
                'default' => ['database', 'mail', 'push'],
                'locked' => ['database'],
            ],
        ]);

        $this->assertSame(
            ['database'],
            NotificationPreferenceRegistry::locked('monitor_down'),
        );
    }

    public function test_has_returns_true_for_registered_type(): void
    {
        NotificationPreferenceRegistry::register([
            'monitor_down' => [
                'label' => 'Monitor Down',
                'channels' => ['database', 'mail', 'push'],
                'default' => ['database', 'mail', 'push'],
                'locked' => ['database'],
            ],
        ]);

        $this->assertTrue(NotificationPreferenceRegistry::has('monitor_down'));
    }

    public function test_has_returns_false_for_unregistered_type(): void
    {
        $this->assertFalse(NotificationPreferenceRegistry::has('unknown_type'));
    }

    public function test_get_returns_null_for_unregistered_type(): void
    {
        $this->assertNull(NotificationPreferenceRegistry::get('unknown_type'));
    }

    public function test_channels_returns_empty_for_unregistered_type(): void
    {
        $this->assertSame([], NotificationPreferenceRegistry::channels('unknown_type'));
    }

    public function test_flush_clears_all_registered_types(): void
    {
        NotificationPreferenceRegistry::register([
            'monitor_down' => [
                'label' => 'Monitor Down',
                'channels' => ['database', 'mail', 'push'],
                'default' => ['database', 'mail', 'push'],
                'locked' => ['database'],
            ],
        ]);

        $this->assertTrue(NotificationPreferenceRegistry::has('monitor_down'));

        NotificationPreferenceRegistry::flush();

        $this->assertFalse(NotificationPreferenceRegistry::has('monitor_down'));
        $this->assertEmpty(NotificationPreferenceRegistry::all());
    }

    public function test_channel_aliases_registers_aliases(): void
    {
        NotificationPreferenceRegistry::channelAliases([
            'push' => 'SomeVendor\\Push\\PushChannel',
        ]);

        $this->assertSame(
            ['push' => 'SomeVendor\\Push\\PushChannel'],
            NotificationPreferenceRegistry::getChannelAliases(),
        );
    }

    public function test_channel_aliases_merges_with_existing(): void
    {
        NotificationPreferenceRegistry::channelAliases([
            'push' => 'SomeVendor\\Push\\PushChannel',
        ]);

        NotificationPreferenceRegistry::channelAliases([
            'sms' => 'SomeVendor\\Sms\\SmsChannel',
        ]);

        $aliases = NotificationPreferenceRegistry::getChannelAliases();
        $this->assertCount(2, $aliases);
        $this->assertSame('SomeVendor\\Push\\PushChannel', $aliases['push']);
        $this->assertSame('SomeVendor\\Sms\\SmsChannel', $aliases['sms']);
    }

    public function test_resolve_slug_returns_explicit_slug(): void
    {
        NotificationPreferenceRegistry::register([
            'App\\Notifications\\MonitorDownNotification' => [
                'slug' => 'monitor_down_custom',
                'label' => 'Monitor Down',
                'channels' => ['mail'],
                'default' => ['mail'],
                'locked' => [],
            ],
        ]);

        $this->assertSame(
            'monitor_down_custom',
            NotificationPreferenceRegistry::resolveSlug('App\\Notifications\\MonitorDownNotification'),
        );
    }

    public function test_resolve_slug_auto_derives_from_class_name(): void
    {
        NotificationPreferenceRegistry::register([
            'App\\Notifications\\MonitorDownNotification' => [
                'label' => 'Monitor Down',
                'channels' => ['mail'],
                'default' => ['mail'],
                'locked' => [],
            ],
        ]);

        $this->assertSame(
            'monitor_down',
            NotificationPreferenceRegistry::resolveSlug('App\\Notifications\\MonitorDownNotification'),
        );
    }

    public function test_resolve_slug_returns_null_for_unregistered_class(): void
    {
        $this->assertNull(NotificationPreferenceRegistry::resolveSlug('Unknown\\Notification'));
    }

    public function test_resolve_logical_channel_maps_driver_to_logical(): void
    {
        NotificationPreferenceRegistry::channelAliases([
            'push' => 'SomeVendor\\Push\\PushChannel',
        ]);

        $this->assertSame(
            'push',
            NotificationPreferenceRegistry::resolveLogicalChannel('SomeVendor\\Push\\PushChannel'),
        );
    }

    public function test_resolve_logical_channel_returns_identity_for_builtin(): void
    {
        $this->assertSame(
            'mail',
            NotificationPreferenceRegistry::resolveLogicalChannel('mail'),
        );
    }

    public function test_resolve_driver_channel_maps_logical_to_driver(): void
    {
        NotificationPreferenceRegistry::channelAliases([
            'push' => 'SomeVendor\\Push\\PushChannel',
        ]);

        $this->assertSame(
            'SomeVendor\\Push\\PushChannel',
            NotificationPreferenceRegistry::resolveDriverChannel('push'),
        );
    }

    public function test_resolve_driver_channel_returns_identity_for_unknown(): void
    {
        $this->assertSame(
            'mail',
            NotificationPreferenceRegistry::resolveDriverChannel('mail'),
        );
    }

    public function test_driver_channels_for_returns_mapped_channels(): void
    {
        NotificationPreferenceRegistry::channelAliases([
            'push' => 'SomeVendor\\Push\\PushChannel',
        ]);

        NotificationPreferenceRegistry::register([
            'monitor_down' => [
                'label' => 'Monitor Down',
                'channels' => ['mail', 'push'],
                'default' => ['mail', 'push'],
                'locked' => [],
            ],
        ]);

        $this->assertSame(
            ['mail', 'SomeVendor\\Push\\PushChannel'],
            NotificationPreferenceRegistry::driverChannelsFor('monitor_down'),
        );
    }

    public function test_flush_clears_channel_aliases(): void
    {
        NotificationPreferenceRegistry::channelAliases([
            'push' => 'SomeVendor\\Push\\PushChannel',
        ]);

        $this->assertNotEmpty(NotificationPreferenceRegistry::getChannelAliases());

        NotificationPreferenceRegistry::flush();

        $this->assertEmpty(NotificationPreferenceRegistry::getChannelAliases());
    }

    public function test_find_by_slug_returns_definition_for_matching_slug(): void
    {
        NotificationPreferenceRegistry::register([
            'App\\Notifications\\MonitorDownNotification' => [
                'slug' => 'monitor_down',
                'label' => 'Monitor Down',
                'channels' => ['mail'],
                'default' => ['mail'],
                'locked' => [],
            ],
        ]);

        $result = NotificationPreferenceRegistry::findBySlug('monitor_down');

        $this->assertNotNull($result);
        $this->assertSame('Monitor Down', $result['label']);
    }

    public function test_find_by_slug_returns_null_for_unknown_slug(): void
    {
        $this->assertNull(NotificationPreferenceRegistry::findBySlug('nonexistent'));
    }

    public function test_find_by_slug_works_with_auto_derived_slug(): void
    {
        NotificationPreferenceRegistry::register([
            'App\\Notifications\\MonitorDownNotification' => [
                'label' => 'Monitor Down',
                'channels' => ['mail'],
                'default' => ['mail'],
                'locked' => [],
            ],
        ]);

        $result = NotificationPreferenceRegistry::findBySlug('monitor_down');

        $this->assertNotNull($result);
        $this->assertSame('Monitor Down', $result['label']);
    }

    public function test_resolve_key_from_slug_returns_fqcn(): void
    {
        NotificationPreferenceRegistry::register([
            'App\\Notifications\\MonitorDownNotification' => [
                'label' => 'Monitor Down',
                'channels' => ['mail'],
                'default' => ['mail'],
                'locked' => [],
            ],
        ]);

        $this->assertSame(
            'App\\Notifications\\MonitorDownNotification',
            NotificationPreferenceRegistry::resolveKeyFromSlug('monitor_down'),
        );
    }

    public function test_resolve_key_from_slug_returns_null_for_unknown(): void
    {
        $this->assertNull(NotificationPreferenceRegistry::resolveKeyFromSlug('nonexistent'));
    }

    public function test_get_falls_back_to_slug_lookup(): void
    {
        NotificationPreferenceRegistry::register([
            'App\\Notifications\\MonitorDownNotification' => [
                'label' => 'Monitor Down',
                'channels' => ['mail'],
                'default' => ['mail'],
                'locked' => [],
            ],
        ]);

        // get() with auto-derived slug should find the FQCN-keyed entry.
        $result = NotificationPreferenceRegistry::get('monitor_down');

        $this->assertNotNull($result);
        $this->assertSame('Monitor Down', $result['label']);
    }

    public function test_has_returns_true_for_slug_of_fqcn_key(): void
    {
        NotificationPreferenceRegistry::register([
            'App\\Notifications\\MonitorDownNotification' => [
                'label' => 'Monitor Down',
                'channels' => ['mail'],
                'default' => ['mail'],
                'locked' => [],
            ],
        ]);

        $this->assertTrue(NotificationPreferenceRegistry::has('monitor_down'));
        $this->assertTrue(
            NotificationPreferenceRegistry::has(
                'App\\Notifications\\MonitorDownNotification',
            ),
        );
    }

    public function test_defaults_works_with_slug_of_fqcn_key(): void
    {
        NotificationPreferenceRegistry::register([
            'App\\Notifications\\MonitorDownNotification' => [
                'label' => 'Monitor Down',
                'channels' => ['mail', 'database'],
                'default' => ['mail'],
                'locked' => [],
            ],
        ]);

        $this->assertSame(
            ['mail'],
            NotificationPreferenceRegistry::defaults('monitor_down'),
        );
    }
}
