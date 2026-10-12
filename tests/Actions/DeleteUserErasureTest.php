<?php

namespace FlutterSdk\MagicStarter\Tests\Actions;

use FlutterSdk\MagicStarter\Audit\Audit;
use FlutterSdk\MagicStarter\Contracts\DeletesUsers;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\NewsletterSubscriber;
use FlutterSdk\MagicStarter\Models\NotificationSetting;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Deleting an account erases what the package stored about the person outside
 * the user row: database notifications, notification settings, the newsletter
 * subscription keyed by their email, and the audit rows describing those rows.
 * Another account's rows are untouched.
 */
final class DeleteUserErasureTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('magic-starter.features', [
            Features::teams(),
            Features::notifications(),
            Features::newsletterSubscription(),
            Features::audit(),
        ]);
        $app['config']->set('magic-starter.billing.billable', 'team');
    }

    /**
     * @return iterable<string, array{0: bool}>
     */
    public static function keyModes(): iterable
    {
        yield 'uuid keys' => [true];
        yield 'integer keys' => [false];
    }

    #[DataProvider('keyModes')]
    public function test_deletion_erases_notifications_settings_newsletter_and_their_audit_rows(bool $useUuids): void
    {
        $this->migrate($useUuids);

        $leaving = $this->createUser('leaving@example.com');
        $staying = $this->createUser('staying@example.com');

        $leavingNotifications = [
            $this->notify($leaving, 'Leaving first'),
            $this->notify($leaving, 'Leaving second'),
        ];
        $stayingNotification = $this->notify($staying, 'Staying only');
        $leavingSetting = $this->createSetting($leaving);
        $stayingSetting = $this->createSetting($staying);
        $leavingSubscriber = $this->subscribe('leaving@example.com');
        $stayingSubscriber = $this->subscribe('staying@example.com');

        $this->assertSame(1, $this->auditsAbout($leavingSubscriber), 'The subscription must be audited first.');
        $this->assertSame(1, $this->auditsAbout($leavingSetting), 'The setting must be audited first.');
        $this->assertSame(1, $this->auditsAbout($leavingNotifications[0]), 'The notification must be audited first.');

        $this->app->make(DeletesUsers::class)->delete($leaving);

        // 1. The rows themselves.
        $this->assertSame(0, $leaving->notifications()->count());
        $this->assertSame(0, NotificationSetting::query()->whereKey($leavingSetting->getKey())->count());
        $this->assertSame(0, NewsletterSubscriber::query()->where('email', 'leaving@example.com')->count());

        // 2. Their audit rows, and no new row written by the erasure itself.
        foreach ([...$leavingNotifications, $leavingSetting, $leavingSubscriber] as $erased) {
            $this->assertSame(0, $this->auditsAbout($erased), $erased::class . ' audit rows must be gone.');
        }

        $stored = (string) json_encode(Audit::query()->get()->toArray());

        $this->assertStringNotContainsString('leaving@example.com', $stored);
        $this->assertStringNotContainsString('Leaving first', $stored);

        // 3. Another account keeps everything.
        $this->assertSame(1, $staying->notifications()->count());
        $this->assertSame(1, NotificationSetting::query()->whereKey($stayingSetting->getKey())->count());
        $this->assertSame(1, NewsletterSubscriber::query()->where('email', 'staying@example.com')->count());
        $this->assertSame(1, $this->auditsAbout($stayingNotification));
        $this->assertSame(1, $this->auditsAbout($stayingSetting));
        $this->assertSame(1, $this->auditsAbout($stayingSubscriber));
    }

    /**
     * An application that never installed notifications or the newsletter has
     * none of their tables, and the deletion must not assume them.
     */
    public function test_a_schema_without_the_optional_tables_still_deletes(): void
    {
        $this->migrate(true);

        Schema::drop('notifications');
        Schema::drop('notification_settings');
        Schema::drop('newsletter_subscribers');

        $user = $this->createUser('plain@example.com');

        $this->app->make(DeletesUsers::class)->delete($user);

        $this->assertNull(DeleteUserErasureTestUser::query()->find($user->getKey()));
    }

    private function migrate(bool $useUuids): void
    {
        config()->set('magic-starter.use_uuids', $useUuids);

        foreach ([
            'create_users_table',
            'create_teams_table',
            'create_team_user_table',
            'create_personal_access_tokens_table',
            'create_notifications_table',
            'create_notification_settings_table',
            'create_newsletter_subscribers_table',
            'create_magic_starter_audits_table',
        ] as $migration) {
            (require __DIR__ . "/../../database/migrations/{$migration}.php")->up();
        }

        MagicStarter::useUserModel(DeleteUserErasureTestUser::class);
    }

    private function createUser(string $email): DeleteUserErasureTestUser
    {
        return DeleteUserErasureTestUser::query()->create([
            'name' => 'User',
            'email' => $email,
            'password' => 'secret-password',
        ]);
    }

    /**
     * Store a notification the way Laravel's database channel does, through
     * the Eloquent model, so the trail records it.
     */
    private function notify(DeleteUserErasureTestUser $user, string $message): DatabaseNotification
    {
        return $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'test-notification',
            'data' => [
                'message' => $message,
            ],
        ]);
    }

    private function createSetting(DeleteUserErasureTestUser $user): NotificationSetting
    {
        return $user->notificationSettings()->create([
            'type' => 'marketing',
            'channel' => 'mail',
            'is_enabled' => false,
        ]);
    }

    private function subscribe(string $email): NewsletterSubscriber
    {
        return NewsletterSubscriber::query()->create([
            'email' => $email,
            'is_active' => true,
            'source' => 'profile',
        ]);
    }

    private function auditsAbout(Model $subject): int
    {
        return Audit::query()
            ->where('auditable_type', $subject->getMorphClass())
            ->where('auditable_id', (string) $subject->getKey())
            ->count();
    }
}

/**
 * A user that holds Sanctum tokens and database notifications, as the
 * published User stub does.
 */
class DeleteUserErasureTestUser extends ConcreteUser
{
    use HasApiTokens;
    use Notifiable;
}
