<?php

declare(strict_types=1);

namespace FlutterSdk\MagicStarter\Tests\Traits;

use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\NotificationSetting;
use FlutterSdk\MagicStarter\NotificationPreferenceRegistry;
use FlutterSdk\MagicStarter\Tests\TestCase;
use FlutterSdk\MagicStarter\Traits\HasNotificationPreferences;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;

final class HasNotificationPreferencesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::reset();
        NotificationPreferenceRegistry::flush();

        \call_user_func('config', ['database.default' => 'testing']);
        \call_user_func('config', ['database.connections.testing' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);
        \call_user_func('config', [
            'magic-starter.models.user' => HasNotifPrefsTestUser::class,
        ]);

        \call_user_func([\call_user_func('app', 'db.schema'), 'create'], 'users', function ($table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });

        \call_user_func(
            [\call_user_func('app', 'db.schema'), 'create'],
            'notification_settings',
            function ($table): void {
                $table->uuid('id')->primary();
                $table->uuidMorphs('notifiable');
                $table->string('type');
                $table->string('channel');
                $table->boolean('is_enabled')->default(true);
                $table->timestamps();

                $table->unique(
                    ['notifiable_id', 'notifiable_type', 'type', 'channel'],
                    'notification_settings_unique',
                );
            },
        );

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
    }

    protected function tearDown(): void
    {
        NotificationPreferenceRegistry::flush();
        parent::tearDown();
    }

    public function test_prefers_returns_default_when_no_override(): void
    {
        $user = HasNotifPrefsTestUser::query()->create([
            'name' => 'Test User',
            'email' => 'test@example.test',
        ]);

        $this->assertTrue($user->prefers('monitor_down', 'database'));
        $this->assertTrue($user->prefers('monitor_down', 'mail'));
        $this->assertTrue($user->prefers('monitor_down', 'push'));
    }

    public function test_prefers_returns_false_for_channel_not_in_defaults(): void
    {
        $user = HasNotifPrefsTestUser::query()->create([
            'name' => 'Test User',
            'email' => 'test@example.test',
        ]);

        // incident_update defaults to only ['database'], so 'mail' should be false
        $this->assertFalse($user->prefers('incident_update', 'mail'));
    }

    public function test_prefers_returns_override_when_set(): void
    {
        $user = HasNotifPrefsTestUser::query()->create([
            'name' => 'Test User',
            'email' => 'test@example.test',
        ]);

        NotificationSetting::query()->create([
            'notifiable_id' => $user->getKey(),
            'notifiable_type' => HasNotifPrefsTestUser::class,
            'type' => 'monitor_down',
            'channel' => 'mail',
            'is_enabled' => false,
        ]);

        // Reload the relation
        $user->load('notificationSettings');

        $this->assertFalse($user->prefers('monitor_down', 'mail'));
    }

    public function test_prefers_returns_true_for_unregistered_type(): void
    {
        $user = HasNotifPrefsTestUser::query()->create([
            'name' => 'Test User',
            'email' => 'test@example.test',
        ]);

        $this->assertTrue($user->prefers('unknown_type', 'email'));
    }

    public function test_notification_preference_matrix_returns_full_grid(): void
    {
        $user = HasNotifPrefsTestUser::query()->create([
            'name' => 'Test User',
            'email' => 'test@example.test',
        ]);

        $user->load('notificationSettings');
        $matrix = $user->notificationPreferenceMatrix();

        $this->assertArrayHasKey('monitor_down', $matrix);
        $this->assertArrayHasKey('incident_update', $matrix);

        $this->assertSame('Monitor Down', $matrix['monitor_down']['label']);
        $this->assertArrayHasKey('database', $matrix['monitor_down']['channels']);
        $this->assertArrayHasKey('mail', $matrix['monitor_down']['channels']);
        $this->assertArrayHasKey('push', $matrix['monitor_down']['channels']);

        // All defaults are enabled
        $this->assertTrue($matrix['monitor_down']['channels']['database']['enabled']);
        $this->assertTrue($matrix['monitor_down']['channels']['mail']['enabled']);
        $this->assertTrue($matrix['monitor_down']['channels']['push']['enabled']);

        // database is locked
        $this->assertTrue($matrix['monitor_down']['channels']['database']['locked']);
        $this->assertFalse($matrix['monitor_down']['channels']['mail']['locked']);

        // incident_update: database default on, mail default off
        $this->assertTrue($matrix['incident_update']['channels']['database']['enabled']);
        $this->assertFalse($matrix['incident_update']['channels']['mail']['enabled']);
    }

    public function test_notification_preference_matrix_reflects_overrides(): void
    {
        $user = HasNotifPrefsTestUser::query()->create([
            'name' => 'Test User',
            'email' => 'test@example.test',
        ]);

        NotificationSetting::query()->create([
            'notifiable_id' => $user->getKey(),
            'notifiable_type' => HasNotifPrefsTestUser::class,
            'type' => 'monitor_down',
            'channel' => 'push',
            'is_enabled' => false,
        ]);

        $user->load('notificationSettings');
        $matrix = $user->notificationPreferenceMatrix();

        $this->assertFalse($matrix['monitor_down']['channels']['push']['enabled']);
        $this->assertTrue($matrix['monitor_down']['channels']['database']['enabled']);
    }

    public function test_notification_settings_relationship_returns_morph_many(): void
    {
        $user = HasNotifPrefsTestUser::query()->create([
            'name' => 'Test User',
            'email' => 'test@example.test',
        ]);

        NotificationSetting::query()->create([
            'notifiable_id' => $user->getKey(),
            'notifiable_type' => HasNotifPrefsTestUser::class,
            'type' => 'monitor_down',
            'channel' => 'mail',
            'is_enabled' => false,
        ]);

        $this->assertCount(1, $user->notificationSettings);
        $this->assertInstanceOf(NotificationSetting::class, $user->notificationSettings->first());
    }
}

/**
 * @internal Test stub only.
 */
final class HasNotifPrefsTestUser extends Authenticatable
{
    use HasNotificationPreferences;
    use HasUuids;

    protected $table = 'users';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
