<?php

declare(strict_types=1);

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
}
