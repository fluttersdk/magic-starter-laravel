<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Http\Controllers\NotificationPreferenceController;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\NotificationPreferenceRegistry;
use FlutterSdk\MagicStarter\Tests\TestCase;
use FlutterSdk\MagicStarter\Traits\HasNotifications;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;

final class NotificationPreferenceControllerTest extends TestCase
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
            'magic-starter.models.user' => NotifPrefControllerTestUser::class,
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
        ]);

        $router = \call_user_func('app', 'router');
        $router->get('/notification-preferences', [NotificationPreferenceController::class, 'show']);
        $router->put('/notification-preferences', [NotificationPreferenceController::class, 'update']);
    }

    protected function tearDown(): void
    {
        NotificationPreferenceRegistry::flush();
        parent::tearDown();
    }

    public function test_show_returns_preference_matrix(): void
    {
        $user = NotifPrefControllerTestUser::query()->create([
            'name' => 'Pref User',
            'email' => 'pref@example.test',
        ]);

        $this->actingAs($user)
            ->getJson('/notification-preferences')
            ->assertOk()
            ->assertJsonPath('data.monitor_down.label', 'Monitor Down')
            ->assertJsonPath('data.monitor_down.channels.database.enabled', true)
            ->assertJsonPath('data.monitor_down.channels.database.locked', true)
            ->assertJsonPath('data.monitor_down.channels.mail.enabled', true)
            ->assertJsonPath('data.monitor_down.channels.mail.locked', false);
    }

    public function test_show_returns_defaults_when_no_overrides(): void
    {
        $user = NotifPrefControllerTestUser::query()->create([
            'name' => 'Pref User',
            'email' => 'pref@example.test',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/notification-preferences')
            ->assertOk();

        $data = $response->json('data.monitor_down.channels');

        $this->assertTrue($data['database']['enabled']);
        $this->assertTrue($data['mail']['enabled']);
        $this->assertTrue($data['push']['enabled']);
    }

    public function test_update_single_preference(): void
    {
        $user = NotifPrefControllerTestUser::query()->create([
            'name' => 'Pref User',
            'email' => 'pref@example.test',
        ]);

        $this->actingAs($user)
            ->putJson('/notification-preferences', [
                'type' => 'monitor_down',
                'channel' => 'mail',
                'is_enabled' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.monitor_down.channels.mail.enabled', false);

        $this->assertDatabaseHas('notification_settings', [
            'notifiable_id' => $user->getKey(),
            'type' => 'monitor_down',
            'channel' => 'mail',
            'is_enabled' => false,
        ]);
    }

    public function test_update_bulk_preferences(): void
    {
        $user = NotifPrefControllerTestUser::query()->create([
            'name' => 'Pref User',
            'email' => 'pref@example.test',
        ]);

        $this->actingAs($user)
            ->putJson('/notification-preferences', [
                'preferences' => [
                    [
                        'type' => 'monitor_down',
                        'channel' => 'mail',
                        'is_enabled' => false,
                    ],
                    [
                        'type' => 'monitor_down',
                        'channel' => 'push',
                        'is_enabled' => false,
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.monitor_down.channels.mail.enabled', false)
            ->assertJsonPath('data.monitor_down.channels.push.enabled', false);

        $settingsCount = \call_user_func('app', 'db')
            ->table('notification_settings')
            ->where('notifiable_id', $user->getKey())
            ->count();

        $this->assertSame(2, $settingsCount);
    }

    public function test_update_rejects_unregistered_type(): void
    {
        $user = NotifPrefControllerTestUser::query()->create([
            'name' => 'Pref User',
            'email' => 'pref@example.test',
        ]);

        $this->actingAs($user)
            ->putJson('/notification-preferences', [
                'type' => 'unknown',
                'channel' => 'mail',
                'is_enabled' => false,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    public function test_update_rejects_unavailable_channel(): void
    {
        $user = NotifPrefControllerTestUser::query()->create([
            'name' => 'Pref User',
            'email' => 'pref@example.test',
        ]);

        $this->actingAs($user)
            ->putJson('/notification-preferences', [
                'type' => 'monitor_down',
                'channel' => 'slack',
                'is_enabled' => false,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('channel');
    }

    public function test_update_rejects_locked_channel(): void
    {
        $user = NotifPrefControllerTestUser::query()->create([
            'name' => 'Pref User',
            'email' => 'pref@example.test',
        ]);

        $this->actingAs($user)
            ->putJson('/notification-preferences', [
                'type' => 'monitor_down',
                'channel' => 'database',
                'is_enabled' => false,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('channel');
    }
}

/**
 * @internal Test stub only.
 */
final class NotifPrefControllerTestUser extends Authenticatable
{
    use HasNotifications;
    use HasUuids;

    protected $table = 'users';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
