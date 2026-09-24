<?php

namespace FlutterSdk\MagicStarter\Tests\Database;

use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Locks the notifications table to the row Laravel's `database` channel writes.
 *
 * The channel sets `id` to the notification's own UUID whatever key type the
 * application uses, so the table's key is a UUID in both `use_uuids` modes; only
 * the morph columns follow the application's key. With an integer `id` the
 * insert fails outright (SQLite: "datatype mismatch"), and the controller tests
 * never saw it because they insert rows by hand with a UUID of their own.
 */
class NotificationsMigrationTest extends TestCase
{
    /**
     * @return array<string, array{bool}>
     */
    public static function keyModes(): array
    {
        return [
            'uuid keys' => [
                true,
            ],
            'integer keys' => [
                false,
            ],
        ];
    }

    #[DataProvider('keyModes')]
    public function test_the_database_channel_writes_a_notification_in_either_key_mode(bool $useUuids): void
    {
        // 1. The key mode is read at migration time, so it is set before the tables exist.
        config()->set('magic-starter.use_uuids', $useUuids);
        $this->migrate('create_users_table.php');
        $this->migrate('create_notifications_table.php');

        // 2. Deliver through the real channel rather than inserting a row by hand.
        $user = NotifiableMigrationUser::create([
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'password' => 'secret',
        ]);
        $user->notify(new DatabaseChannelNotification);

        // 3. The row is addressable by the channel's UUID and belongs to the user's own key.
        $notification = DatabaseNotification::query()->sole();
        $this->assertTrue(
            DB::table('notifications')->where('id', $notification->id)->exists(),
        );
        $this->assertSame(
            (string) $user->getKey(),
            (string) $notification->notifiable_id,
        );
        $this->assertSame('Deploy finished', $notification->data['title']);
    }

    /**
     * Run one of the package's migration stubs by file name.
     */
    private function migrate(string $file): void
    {
        $this->artisan('migrate', [
            '--path' => __DIR__ . '/../../database/migrations/' . $file,
            '--realpath' => true,
        ]);
    }
}

/**
 * A user model that is notifiable, which the shared `ConcreteUser` fixture is not.
 */
class NotifiableMigrationUser extends Model
{
    use ConditionallyUsesUuids;
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];
}

/**
 * A notification delivered through Laravel's `database` channel only.
 */
class DatabaseChannelNotification extends Notification
{
    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [
            'database',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Deploy finished',
        ];
    }
}
