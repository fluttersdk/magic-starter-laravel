<?php

namespace FlutterSdk\MagicStarter\Tests\Traits;

use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\NotificationSetting;
use FlutterSdk\MagicStarter\NotificationPreferenceRegistry;
use FlutterSdk\MagicStarter\Tests\TestCase;
use FlutterSdk\MagicStarter\Traits\HasNotifications;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;

final class HasNotificationsTest extends TestCase
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
            // The column this package writes at registration and login, and
            // the one the locale-preference test reads back.
            $table->string('locale')->nullable();
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

    public function test_notification_preference_matrix_translates_a_label_key_per_request_locale(): void
    {
        // An app that ships more than one language cannot register a finished
        // sentence: the registry is filled in a service provider's boot(),
        // before the locale middleware has run, and under Octane it is filled
        // once for the worker's whole life. So the label has to be resolvable
        // per request, which is what registering a key buys.
        NotificationPreferenceRegistry::flush();
        NotificationPreferenceRegistry::register([
            'monitor_down' => [
                'label' => 'notifications.type_monitor_down',
                'channels' => ['mail'],
                'default' => ['mail'],
                'locked' => [],
            ],
        ]);

        \call_user_func([\call_user_func('app', 'translator'), 'addLines'], [
            'notifications.type_monitor_down' => 'Monitor went down',
        ], 'en');
        \call_user_func([\call_user_func('app', 'translator'), 'addLines'], [
            'notifications.type_monitor_down' => 'İzleyici düştü',
        ], 'tr');

        $user = HasNotifPrefsTestUser::query()->create([
            'name' => 'Test User',
            'email' => 'test@example.test',
        ]);
        $user->load('notificationSettings');

        \call_user_func([\call_user_func('app'), 'setLocale'], 'en');
        $this->assertSame(
            'Monitor went down',
            $user->notificationPreferenceMatrix()['monitor_down']['label'],
        );

        // The same registry entry, the same booted app, a different locale.
        // This is the assertion a boot-time __() call cannot satisfy.
        \call_user_func([\call_user_func('app'), 'setLocale'], 'tr');
        $this->assertSame(
            'İzleyici düştü',
            $user->notificationPreferenceMatrix()['monitor_down']['label'],
        );
    }

    public function test_notification_preference_matrix_honours_a_stored_locale_preference(): void
    {
        // This package WRITES users.locale at registration and login, and used
        // to apply it nowhere: an adopter with no locale middleware of its own
        // stored a preference this package then ignored. The label now resolves
        // through the notifiable's own HasLocalePreference, which is the same
        // contract Laravel's NotificationSender honours before rendering.
        //
        // Nothing here calls App::setLocale(), deliberately: the app locale
        // stays 'en' for the whole test, so the only thing that can produce
        // Turkish is the stored preference being read.
        NotificationPreferenceRegistry::flush();
        NotificationPreferenceRegistry::register([
            'monitor_down' => [
                'label' => 'notifications.type_monitor_down',
                'channels' => ['mail'],
                'default' => ['mail'],
                'locked' => [],
            ],
        ]);

        \call_user_func([\call_user_func('app', 'translator'), 'addLines'], [
            'notifications.type_monitor_down' => 'Monitor went down',
        ], 'en');
        \call_user_func([\call_user_func('app', 'translator'), 'addLines'], [
            'notifications.type_monitor_down' => 'İzleyici düştü',
        ], 'tr');

        \call_user_func([\call_user_func('app'), 'setLocale'], 'en');

        \call_user_func('config', [
            'magic-starter.models.user' => HasNotifPrefsLocaleAwareUser::class,
        ]);

        $user = HasNotifPrefsLocaleAwareUser::query()->create([
            'name' => 'Test User',
            'email' => 'tr@example.test',
            'locale' => 'tr',
        ]);
        $user->load('notificationSettings');

        $this->assertSame(
            'İzleyici düştü',
            $user->notificationPreferenceMatrix()['monitor_down']['label'],
        );

        // And a notifiable that declares no preference still follows the app.
        $plain = HasNotifPrefsTestUser::query()->create([
            'name' => 'Test User',
            'email' => 'en@example.test',
        ]);
        $plain->load('notificationSettings');

        $this->assertSame(
            'Monitor went down',
            $plain->notificationPreferenceMatrix()['monitor_down']['label'],
        );
    }

    public function test_notification_preference_matrix_falls_back_when_a_key_names_a_group(): void
    {
        // `Translator::get()` returns the line UNCHANGED when it is an array,
        // so a key naming a group of lines would put a nested object where the
        // response shape promises a string.
        NotificationPreferenceRegistry::flush();
        NotificationPreferenceRegistry::register([
            'monitor_down' => [
                'label' => 'notifications.types',
                'channels' => ['mail'],
                'default' => ['mail'],
                'locked' => [],
            ],
        ]);

        \call_user_func([\call_user_func('app', 'translator'), 'addLines'], [
            'notifications.types' => ['down' => 'Down', 'up' => 'Up'],
        ], 'en');

        $user = HasNotifPrefsTestUser::query()->create([
            'name' => 'Test User',
            'email' => 'group@example.test',
        ]);
        $user->load('notificationSettings');

        $label = $user->notificationPreferenceMatrix()['monitor_down']['label'];

        $this->assertIsString($label);
        $this->assertSame('notifications.types', $label);
    }

    public function test_notification_preference_matrix_leaves_a_plain_label_alone(): void
    {
        // The non-breaking half: every app on 0.0.x registers a finished
        // English sentence, and __() hands back an argument it has no line for.
        NotificationPreferenceRegistry::flush();
        NotificationPreferenceRegistry::register([
            'monitor_down' => [
                'label' => 'Monitor Down',
                'channels' => ['mail'],
                'default' => ['mail'],
                'locked' => [],
            ],
        ]);

        $user = HasNotifPrefsTestUser::query()->create([
            'name' => 'Test User',
            'email' => 'test@example.test',
        ]);
        $user->load('notificationSettings');

        $this->assertSame(
            'Monitor Down',
            $user->notificationPreferenceMatrix()['monitor_down']['label'],
        );
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

    public function test_prefers_works_with_fqcn_registered_types(): void
    {
        // Re-register with FQCN key instead of string slug.
        NotificationPreferenceRegistry::flush();
        NotificationPreferenceRegistry::register([
            'App\\Notifications\\MonitorDownNotification' => [
                'label' => 'Monitor Down',
                'channels' => ['database', 'mail', 'push'],
                'default' => ['database', 'mail'],
                'locked' => [],
            ],
        ]);

        $user = HasNotifPrefsTestUser::query()->create([
            'name' => 'FQCN User',
            'email' => 'fqcn@example.test',
        ]);

        // prefers() called with auto-derived slug should still work.
        $this->assertTrue($user->prefers('monitor_down', 'database'));
        $this->assertTrue($user->prefers('monitor_down', 'mail'));
        $this->assertFalse($user->prefers('monitor_down', 'push'));
    }

    public function test_prefers_works_with_fqcn_and_explicit_slug(): void
    {
        NotificationPreferenceRegistry::flush();
        NotificationPreferenceRegistry::register([
            'App\\Notifications\\MonitorDownNotification' => [
                'slug' => 'mon_down',
                'label' => 'Monitor Down',
                'channels' => ['database', 'mail'],
                'default' => ['database'],
                'locked' => [],
            ],
        ]);

        $user = HasNotifPrefsTestUser::query()->create([
            'name' => 'Slug User',
            'email' => 'slug@example.test',
        ]);

        // prefers() with explicit slug should work.
        $this->assertTrue($user->prefers('mon_down', 'database'));
        $this->assertFalse($user->prefers('mon_down', 'mail'));
    }

    public function test_matrix_uses_slug_keys_when_registered_with_fqcn(): void
    {
        NotificationPreferenceRegistry::flush();
        NotificationPreferenceRegistry::register([
            'App\\Notifications\\MonitorDownNotification' => [
                'label' => 'Monitor Down',
                'channels' => ['database', 'mail'],
                'default' => ['database', 'mail'],
                'locked' => [],
            ],
        ]);

        $user = HasNotifPrefsTestUser::query()->create([
            'name' => 'Matrix User',
            'email' => 'matrix@example.test',
        ]);

        $user->load('notificationSettings');
        $matrix = $user->notificationPreferenceMatrix();

        // Matrix key should be auto-derived slug, not FQCN.
        $this->assertArrayHasKey('monitor_down', $matrix);
        $this->assertArrayNotHasKey(
            'App\\Notifications\\MonitorDownNotification',
            $matrix,
        );
        $this->assertSame('Monitor Down', $matrix['monitor_down']['label']);
    }

    public function test_matrix_with_fqcn_reflects_db_overrides_by_slug(): void
    {
        NotificationPreferenceRegistry::flush();
        NotificationPreferenceRegistry::register([
            'App\\Notifications\\MonitorDownNotification' => [
                'label' => 'Monitor Down',
                'channels' => ['database', 'mail'],
                'default' => ['database', 'mail'],
                'locked' => [],
            ],
        ]);

        $user = HasNotifPrefsTestUser::query()->create([
            'name' => 'Override User',
            'email' => 'override@example.test',
        ]);

        // DB stores slug, not FQCN.
        NotificationSetting::query()->create([
            'notifiable_id' => $user->getKey(),
            'notifiable_type' => HasNotifPrefsTestUser::class,
            'type' => 'monitor_down',
            'channel' => 'mail',
            'is_enabled' => false,
        ]);

        $user->load('notificationSettings');
        $matrix = $user->notificationPreferenceMatrix();

        $this->assertFalse($matrix['monitor_down']['channels']['mail']['enabled']);
        $this->assertTrue($matrix['monitor_down']['channels']['database']['enabled']);
    }

    public function test_route_notification_for_onesignal_returns_alias_payload(): void
    {
        $user = HasNotifPrefsTestUser::query()->create([
            'name' => 'OneSignal User',
            'email' => 'onesignal@example.test',
        ]);

        $routing = $user->routeNotificationForOneSignal();

        $this->assertIsArray($routing);
        $this->assertArrayHasKey('external_id', $routing);
        $this->assertSame(['user_' . $user->getKey()], $routing['external_id']);
    }

    public function test_route_notification_for_onesignal_returns_prefixed_string_id(): void
    {
        $user = HasNotifPrefsTestUser::query()->create([
            'name' => 'Prefix User',
            'email' => 'prefix@example.test',
        ]);

        $routing = $user->routeNotificationForOneSignal();

        $this->assertIsString($routing['external_id'][0]);
        $this->assertStringStartsWith('user_', $routing['external_id'][0]);
    }
}

/**
 * @internal Test stub only.
 */
final class HasNotifPrefsTestUser extends Authenticatable
{
    use HasNotifications;
    use HasUuids;

    protected $table = 'users';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}

/**
 * The same notifiable, declaring Laravel's locale-preference contract.
 *
 * Its presence is the whole test: `$this instanceof HasLocalePreference`
 * answers false for an unresolvable class name rather than erroring, so a
 * mistyped import would leave the label resolving in the app locale with
 * nothing failing anywhere.
 */
final class HasNotifPrefsLocaleAwareUser extends Authenticatable implements HasLocalePreference
{
    use HasNotifications;
    use HasUuids;

    protected $table = 'users';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function preferredLocale(): ?string
    {
        return $this->locale;
    }
}
