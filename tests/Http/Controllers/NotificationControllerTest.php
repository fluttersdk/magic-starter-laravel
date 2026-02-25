<?php

declare(strict_types=1);

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Http\Controllers\NotificationController;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

final class NotificationControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::reset();

        \call_user_func('config', ['database.default' => 'testing']);
        \call_user_func('config', ['database.connections.testing' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);
        \call_user_func('config', [
            'magic-starter.models.user' => NotifControllerTestUser::class,
        ]);

        \call_user_func([\call_user_func('app', 'db.schema'), 'create'], 'users', function ($table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });

        \call_user_func([\call_user_func('app', 'db.schema'), 'create'], 'notifications', function ($table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->uuidMorphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        $router = \call_user_func('app', 'router');
        $router->get('/notifications', [NotificationController::class, 'index']);
        $router->get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
        $router->post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
        $router->post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
        $router->delete('/notifications/{id}', [NotificationController::class, 'destroy']);
    }

    public function test_index_returns_paginated_notifications(): void
    {
        $user = NotifControllerTestUser::query()->create([
            'name' => 'Notif User',
            'email' => 'notif@example.test',
        ]);

        $this->insertNotification($user);
        $this->insertNotification($user);

        $this->actingAs($user)
            ->getJson('/notifications')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [
                    ['id', 'type', 'data', 'read_at', 'created_at'],
                ],
            ]);
    }

    public function test_unread_count_returns_correct_count(): void
    {
        $user = NotifControllerTestUser::query()->create([
            'name' => 'Notif User',
            'email' => 'notif@example.test',
        ]);

        $this->insertNotification($user);
        $this->insertNotification($user);
        $this->insertNotification($user);

        $this->actingAs($user)
            ->getJson('/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.count', 3);
    }

    public function test_mark_as_read_updates_notification(): void
    {
        $user = NotifControllerTestUser::query()->create([
            'name' => 'Notif User',
            'email' => 'notif@example.test',
        ]);

        $notificationId = $this->insertNotification($user);

        $this->actingAs($user)
            ->postJson("/notifications/{$notificationId}/read")
            ->assertOk()
            ->assertJsonPath('message', 'Notification marked as read.');

        $this->assertNotNull(
            \call_user_func('app', 'db')
                ->table('notifications')
                ->where('id', $notificationId)
                ->value('read_at'),
        );
    }

    public function test_mark_all_as_read_updates_all_notifications(): void
    {
        $user = NotifControllerTestUser::query()->create([
            'name' => 'Notif User',
            'email' => 'notif@example.test',
        ]);

        $this->insertNotification($user);
        $this->insertNotification($user);
        $this->insertNotification($user);

        $this->actingAs($user)
            ->postJson('/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('message', 'All notifications marked as read.');

        $unreadCount = \call_user_func('app', 'db')
            ->table('notifications')
            ->where('notifiable_id', $user->getKey())
            ->whereNull('read_at')
            ->count();

        $this->assertSame(0, $unreadCount);
    }

    public function test_destroy_deletes_notification(): void
    {
        $user = NotifControllerTestUser::query()->create([
            'name' => 'Notif User',
            'email' => 'notif@example.test',
        ]);

        $notificationId = $this->insertNotification($user);

        $this->actingAs($user)
            ->deleteJson("/notifications/{$notificationId}")
            ->assertOk()
            ->assertJsonPath('message', 'Notification deleted.');

        $this->assertNull(
            \call_user_func('app', 'db')
                ->table('notifications')
                ->where('id', $notificationId)
                ->first(),
        );
    }

    public function test_destroy_returns_404_for_nonexistent(): void
    {
        $user = NotifControllerTestUser::query()->create([
            'name' => 'Notif User',
            'email' => 'notif@example.test',
        ]);

        $this->actingAs($user)
            ->deleteJson('/notifications/00000000-0000-0000-0000-000000000000')
            ->assertStatus(404);
    }

    /**
     * Insert a notification record and return its UUID.
     */
    private function insertNotification(NotifControllerTestUser $user): string
    {
        $id = (string) Str::uuid();

        \call_user_func('app', 'db')->table('notifications')->insert([
            'id' => $id,
            'type' => 'test_notification',
            'notifiable_type' => NotifControllerTestUser::class,
            'notifiable_id' => $user->getKey(),
            'data' => json_encode([
                'title' => 'Test',
                'body' => 'Test Body',
                'data' => ['type' => 'test'],
            ]),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}

/**
 * @internal Test stub only.
 */
final class NotifControllerTestUser extends Authenticatable
{
    use HasUuids;
    use Notifiable;

    protected $table = 'users';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
