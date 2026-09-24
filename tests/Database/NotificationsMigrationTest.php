<?php

namespace FlutterSdk\MagicStarter\Tests\Database;

use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Locks the notifications table to the row Laravel's `database` channel writes.
 *
 * The channel sets `id` to the notification's own UUID whatever key type the
 * application uses, so the table's key is a UUID in both `use_uuids` modes; only
 * the morph columns follow the application's key. With an integer `id` the
 * insert fails outright (SQLite: "datatype mismatch"), and the controller tests
 * never saw it because they insert rows by hand with a UUID of their own.
 *
 * The create stub only reaches a fresh install, so the rekey migration is what
 * repairs a table an integer-key install already built.
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
        //    The sender clones the notification per notifiable and keeps a preset
        //    id, so presetting one is what lets the stored row be compared with it.
        $user = $this->createUser();
        $sent = new DatabaseChannelNotification;
        $sent->id = (string) Str::uuid();
        $user->notify($sent);

        // 3. The stored row carries the channel's UUID unaltered and the user's own key.
        $notification = DatabaseNotification::query()->sole();
        $this->assertSame($sent->id, $notification->id);
        $this->assertSame(
            (string) $user->getKey(),
            (string) $notification->notifiable_id,
        );
        $this->assertSame('Deploy finished', $notification->data['title']);
    }

    public function test_the_rekey_rebuilds_an_integer_keyed_table_and_keeps_its_rows(): void
    {
        // 1. The table an integer-key install built before the create stub was fixed.
        config()->set('magic-starter.use_uuids', false);
        $this->migrate('create_users_table.php');
        $this->createIntegerKeyedNotificationsTable();
        $user = $this->createUser();

        // 2. A row only a non-strict MySQL could have written: the channel's UUID
        //    coerced into a number.
        DB::table('notifications')->insert([
            'id' => 7,
            'type' => DatabaseChannelNotification::class,
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->getKey(),
            'data' => json_encode([
                'title' => 'Carried over',
            ]),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->migrate('rekey_notifications_table_by_uuid.php');

        // 3. The key is no longer an auto-increment, and the row survived under a UUID.
        $this->assertFalse($this->idAutoIncrements());
        $carried = DatabaseNotification::query()->sole();
        $this->assertTrue(Str::isUuid($carried->id));
        $this->assertSame('Carried over', $carried->data['title']);
        $this->assertSame((string) $user->getKey(), (string) $carried->notifiable_id);

        // 4. Both lookup indexes are back under the names the create stub gives them.
        $this->assertTrue(Schema::hasIndex('notifications', 'notifications_notifiable_type_notifiable_id_index'));
        $this->assertTrue(
            Schema::hasIndex('notifications', 'notifications_notifiable_type_notifiable_id_read_at_index'),
        );

        // 5. The channel can write again.
        $sent = new DatabaseChannelNotification;
        $sent->id = (string) Str::uuid();
        $user->notify($sent);
        $this->assertTrue(DatabaseNotification::query()->whereKey($sent->id)->exists());
    }

    public function test_the_rekey_leaves_a_uuid_keyed_table_untouched(): void
    {
        config()->set('magic-starter.use_uuids', false);
        $this->migrate('create_users_table.php');
        $this->migrate('create_notifications_table.php');
        $sent = new DatabaseChannelNotification;
        $sent->id = (string) Str::uuid();
        $this->createUser()->notify($sent);

        $this->migrate('rekey_notifications_table_by_uuid.php');

        $this->assertSame($sent->id, DatabaseNotification::query()->sole()->id);
    }

    public function test_the_rekey_skips_an_install_without_the_table(): void
    {
        $this->migrate('rekey_notifications_table_by_uuid.php');

        $this->assertFalse(Schema::hasTable('notifications'));
        $this->assertFalse(Schema::hasTable('notifications_rekeyed'));
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

    private function createUser(): NotifiableMigrationUser
    {
        return NotifiableMigrationUser::create([
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'password' => 'secret',
        ]);
    }

    /**
     * The shape the create stub produced in integer mode before the fix.
     */
    private function createIntegerKeyedNotificationsTable(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index([
                'notifiable_type',
                'notifiable_id',
                'read_at',
            ]);
        });
    }

    private function idAutoIncrements(): bool
    {
        $id = collect(Schema::getColumns('notifications'))->firstWhere('name', 'id');

        return (bool) $id['auto_increment'];
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
