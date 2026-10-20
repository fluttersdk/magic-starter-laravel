<?php

namespace FlutterSdk\MagicStarter\Tests\Console;

use FlutterSdk\MagicStarter\Console\MailDoctorCommand;
use FlutterSdk\MagicStarter\Models\MailSend;
use FlutterSdk\MagicStarter\Tests\TestCase;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use onesignal\client\api\DefaultApi;
use onesignal\client\ApiException;
use onesignal\client\model\GenericError;
use onesignal\client\model\NotificationWithMeta;

/**
 * `magic-starter:mail:doctor` reads the email channel with the REST key, prints
 * the router state, and fails only when the router points mail at a channel
 * OneSignal has switched off. It never sends mail and never prints the key or an
 * address.
 */
final class MailDoctorCommandTest extends TestCase
{
    private const APPS_URL = 'https://api.onesignal.com/apps/app-xyz';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'magic-starter.use_uuids' => false,
            'magic-starter.onesignal.app_id' => 'app-xyz',
            'magic-starter.onesignal.rest_api_key' => 'rest-key-secret',
            'magic-starter.mail.onesignal.enabled' => true,
            'magic-starter.mail.onesignal.classes' => ['ResetPassword'],
            'magic-starter.mail.onesignal.mailer' => 'onesignal-failover',
            'mail.default' => 'magic-router',
            'mail.mailers.magic-router' => ['transport' => 'magic-starter-router', 'default' => 'smtp'],
            'mail.mailers.onesignal-failover' => ['transport' => 'failover', 'mailers' => ['smtp']],
        ]);
    }

    public function test_it_fails_when_the_channel_is_disabled_and_the_router_is_enabled(): void
    {
        // Arrange
        $this->fakeApp(['channels' => ['email' => ['enabled' => false]]]);

        // Act
        $exit = Artisan::call(MailDoctorCommand::NAME);

        // Assert
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Email channel: disabled', Artisan::output());
    }

    public function test_it_passes_when_the_channel_is_disabled_and_the_router_is_off(): void
    {
        // Arrange
        config(['magic-starter.mail.onesignal.enabled' => false]);
        $this->fakeApp(['channels' => ['email' => ['enabled' => false]]]);

        // Act
        $exit = Artisan::call(MailDoctorCommand::NAME);

        // Assert
        $this->assertSame(0, $exit);
    }

    public function test_it_passes_when_the_channel_is_disabled_but_the_default_mailer_is_not_the_router(): void
    {
        // Arrange: nothing is routed, so a disabled channel costs no mail.
        config(['mail.default' => 'smtp', 'mail.mailers.smtp' => ['transport' => 'smtp']]);
        $this->fakeApp(['channels' => ['email' => ['enabled' => false]]]);

        // Act
        $exit = Artisan::call(MailDoctorCommand::NAME);

        // Assert
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Email channel: disabled', Artisan::output());
    }

    public function test_it_fails_when_the_routed_mailer_is_not_defined_while_routing_is_live(): void
    {
        // Arrange
        config(['magic-starter.mail.onesignal.mailer' => 'missing-mailer']);
        $this->fakeApp(['channels' => ['email' => ['enabled' => true]]]);

        // Act
        $exit = Artisan::call(MailDoctorCommand::NAME);

        // Assert
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Routed mailer: missing-mailer (not defined)', Artisan::output());
    }

    public function test_an_undefined_routed_mailer_is_printed_but_passes_when_nothing_is_routed(): void
    {
        // Arrange
        config(['magic-starter.mail.onesignal.mailer' => 'missing-mailer', 'magic-starter.mail.onesignal.enabled' => false]);
        $this->fakeApp(['channels' => ['email' => ['enabled' => true]]]);

        // Act
        $exit = Artisan::call(MailDoctorCommand::NAME);

        // Assert
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Routed mailer: missing-mailer (not defined)', Artisan::output());
    }

    public function test_a_defined_routed_mailer_prints_no_routed_mailer_line(): void
    {
        // Arrange
        $this->fakeApp(['channels' => ['email' => ['enabled' => true]]]);

        // Act
        Artisan::call(MailDoctorCommand::NAME);

        // Assert
        $this->assertStringNotContainsString('Routed mailer:', Artisan::output());
    }

    public function test_it_passes_when_the_channel_is_enabled(): void
    {
        // Arrange
        $this->fakeApp(['channels' => ['email' => ['enabled' => true]]]);

        // Act
        $exit = Artisan::call(MailDoctorCommand::NAME);

        // Assert
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Email channel: enabled', Artisan::output());
    }

    public function test_a_403_prints_unknown_and_passes_with_the_router_off(): void
    {
        // Arrange
        config(['magic-starter.mail.onesignal.enabled' => false]);
        Http::fake([self::APPS_URL => Http::response(['errors' => ['forbidden']], 403)]);

        // Act
        $exit = Artisan::call(MailDoctorCommand::NAME);

        // Assert
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Email channel: unknown', Artisan::output());
    }

    public function test_a_response_without_the_email_channel_prints_unknown(): void
    {
        // Arrange
        $this->fakeApp(['name' => 'App']);

        // Act
        $exit = Artisan::call(MailDoctorCommand::NAME);

        // Assert: an unknown channel is not a live misconfiguration, so it does not fail the check.
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Email channel: unknown', Artisan::output());
    }

    public function test_it_reads_the_channel_with_the_rest_key_and_never_prints_it(): void
    {
        // Arrange
        $this->fakeApp(['channels' => ['email' => ['enabled' => true]]]);

        // Act
        Artisan::call(MailDoctorCommand::NAME);

        // Assert
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::APPS_URL
            && $request->method() === 'GET'
            && $request->header('Authorization') === ['Key rest-key-secret']);
        $this->assertStringNotContainsString('rest-key-secret', Artisan::output());
    }

    public function test_it_prints_the_router_config_and_the_default_mailer(): void
    {
        // Arrange
        $this->fakeApp(['channels' => ['email' => ['enabled' => true]]]);

        // Act
        Artisan::call(MailDoctorCommand::NAME);
        $output = Artisan::output();

        // Assert
        $this->assertStringContainsString('ResetPassword', $output);
        $this->assertStringContainsString('onesignal-failover', $output);
        $this->assertStringContainsString('magic-router', $output);
    }

    public function test_an_unprovisioned_app_makes_no_request_and_reports_the_missing_credentials(): void
    {
        // Arrange
        config(['magic-starter.onesignal.rest_api_key' => null, 'services.onesignal.rest_api_key' => null]);
        Http::fake();

        // Act
        $exit = Artisan::call(MailDoctorCommand::NAME);
        $output = Artisan::output();

        // Assert
        $this->assertSame(0, $exit);
        Http::assertNothingSent();
        $this->assertStringContainsString('REST key: not set', $output);
        $this->assertStringContainsString('Email channel: unknown', $output);
    }

    public function test_deliveries_print_the_counters_by_class_and_id_and_never_an_address(): void
    {
        // Arrange
        $this->fakeApp(['channels' => ['email' => ['enabled' => true]]]);
        $this->migrateSends();
        $this->seedSend('notif-old', 'OldMailable', 3);
        $this->seedSend('notif-mid', 'MidMailable', 2);
        $this->seedSend('notif-new', null, 1);

        $client = $this->createMock(DefaultApi::class);
        $client->expects($this->exactly(2))
            ->method('getNotification')
            ->willReturnCallback(fn (string $appId, string $id) => $this->assertAppAndAnswer($appId, $id));
        $this->app->instance(DefaultApi::class, $client);

        // Act
        $exit = Artisan::call(MailDoctorCommand::NAME, ['--deliveries' => 2]);
        $output = Artisan::output();

        // Assert
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('notif-new', $output);
        $this->assertStringContainsString('notif-mid', $output);
        $this->assertStringContainsString('MidMailable', $output);
        $this->assertStringNotContainsString('notif-old', $output);
        $this->assertStringContainsString('successful: 9', $output);
        $this->assertStringContainsString('failed: 1', $output);
        $this->assertStringContainsString('errored: 2', $output);
        $this->assertStringContainsString('received: 8', $output);
        $this->assertStringNotContainsString('@', $output);
    }

    public function test_a_delivery_the_api_no_longer_knows_prints_its_status_and_the_run_goes_on(): void
    {
        // Arrange
        $this->fakeApp(['channels' => ['email' => ['enabled' => true]]]);
        $this->migrateSends();
        $this->seedSend('notif-gone', 'GoneMailable', 1);

        $client = $this->createMock(DefaultApi::class);
        $client->method('getNotification')->willThrowException(new ApiException('secret@example.org', 404));
        $this->app->instance(DefaultApi::class, $client);

        // Act
        $exit = Artisan::call(MailDoctorCommand::NAME, ['--deliveries' => 5]);
        $output = Artisan::output();

        // Assert
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('notif-gone', $output);
        $this->assertStringContainsString('404', $output);
        $this->assertStringNotContainsString('secret@example.org', $output);
    }

    public function test_deliveries_without_the_table_say_so_and_do_not_fail(): void
    {
        // Arrange
        $this->fakeApp(['channels' => ['email' => ['enabled' => true]]]);

        // Act
        $exit = Artisan::call(MailDoctorCommand::NAME, ['--deliveries' => 5]);

        // Assert
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('magic_starter_mail_sends', Artisan::output());
    }

    public function test_deliveries_without_credentials_say_so_and_ask_nobody(): void
    {
        // Arrange
        config(['magic-starter.onesignal.rest_api_key' => null, 'services.onesignal.rest_api_key' => null]);
        Http::fake();
        $this->migrateSends();
        $this->seedSend('notif-1', 'SomeMailable', 1);
        $client = $this->createMock(DefaultApi::class);
        $client->expects($this->never())->method('getNotification');
        $this->app->instance(DefaultApi::class, $client);

        // Act
        $exit = Artisan::call(MailDoctorCommand::NAME, ['--deliveries' => 5]);
        $output = Artisan::output();

        // Assert
        $this->assertSame(0, $exit);
        Http::assertNothingSent();
        $this->assertStringContainsString('OneSignal is not provisioned', $output);
        $this->assertStringNotContainsString('notif-1', $output);
    }

    public function test_an_answer_that_is_not_a_notification_prints_its_type_and_never_its_errors(): void
    {
        // Arrange
        $this->fakeApp(['channels' => ['email' => ['enabled' => true]]]);
        $this->migrateSends();
        $this->seedSend('notif-limited', 'LimitedMailable', 1);

        $client = $this->createMock(DefaultApi::class);
        $client->method('getNotification')->willReturn(new GenericError(['errors' => ['bad secret@example.org']]));
        $this->app->instance(DefaultApi::class, $client);

        // Act
        $exit = Artisan::call(MailDoctorCommand::NAME, ['--deliveries' => 5]);
        $output = Artisan::output();

        // Assert
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('notif-limited', $output);
        $this->assertStringContainsString('unavailable (' . GenericError::class . ')', $output);
        $this->assertStringNotContainsString('secret@example.org', $output);
    }

    public function test_a_guzzle_failure_on_a_delivery_prints_its_status_and_never_its_text(): void
    {
        // Arrange
        $this->fakeApp(['channels' => ['email' => ['enabled' => true]]]);
        $this->migrateSends();
        $this->seedSend('notif-down', 'DownMailable', 1);

        $client = $this->createMock(DefaultApi::class);
        $client->method('getNotification')->willThrowException(
            new ConnectException('cURL error 6 for secret@example.org', new GuzzleRequest('GET', '/')),
        );
        $this->app->instance(DefaultApi::class, $client);

        // Act
        $exit = Artisan::call(MailDoctorCommand::NAME, ['--deliveries' => 5]);
        $output = Artisan::output();

        // Assert
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('unavailable (status 0)', $output);
        $this->assertStringNotContainsString('secret@example.org', $output);
    }

    public function test_a_connection_failure_prints_unknown_and_does_not_fail_the_check(): void
    {
        // Arrange
        Http::fake([self::APPS_URL => fn () => throw new ConnectionException('cURL error 6 for secret@example.org')]);

        // Act
        $exit = Artisan::call(MailDoctorCommand::NAME);
        $output = Artisan::output();

        // Assert: an unreachable OneSignal says nothing about the channel, so the check does not fail.
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Email channel: unknown', $output);
        $this->assertStringNotContainsString('secret@example.org', $output);
        $this->assertStringNotContainsString('rest-key-secret', $output);
    }

    public function test_a_deliveries_limit_below_one_reads_one_send(): void
    {
        // Arrange
        $this->fakeApp(['channels' => ['email' => ['enabled' => true]]]);
        $this->migrateSends();
        $this->seedSend('notif-only', null, 1);
        $client = $this->createMock(DefaultApi::class);
        $client->expects($this->once())
            ->method('getNotification')
            ->willReturn(new NotificationWithMeta(['id' => 'notif-only', 'successful' => 1]));
        $this->app->instance(DefaultApi::class, $client);

        // Act
        Artisan::call(MailDoctorCommand::NAME, ['--deliveries' => 0]);
        $output = Artisan::output();

        // Assert: zero would otherwise read nothing, so the limit is clamped to one.
        $this->assertStringContainsString('Last 1 recorded send:', $output);
        $this->assertStringContainsString('(direct)', $output);
    }

    public function test_the_doctor_never_sends_mail_or_creates_a_notification(): void
    {
        // Arrange
        $this->fakeApp(['channels' => ['email' => ['enabled' => true]]]);
        $client = $this->createMock(DefaultApi::class);
        $client->expects($this->never())->method('createNotification');
        $this->app->instance(DefaultApi::class, $client);

        // Act + Assert
        $this->assertSame(0, Artisan::call(MailDoctorCommand::NAME));
    }

    private function assertAppAndAnswer(string $appId, string $id): NotificationWithMeta
    {
        $this->assertSame('app-xyz', $appId);
        $this->assertContains($id, ['notif-new', 'notif-mid']);

        return new NotificationWithMeta([
            'id' => $id,
            'successful' => 9,
            'failed' => 1,
            'errored' => 2,
            'received' => 8,
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function fakeApp(array $body): void
    {
        Http::fake([self::APPS_URL => Http::response($body, 200)]);
    }

    private function migrateSends(): void
    {
        (require __DIR__ . '/../../database/migrations/create_magic_starter_mail_sends_table.php')->up();
    }

    private function seedSend(string $onesignalId, ?string $class, int $daysOld): void
    {
        $send = new MailSend;
        $send->forceFill([
            'onesignal_id' => $onesignalId,
            'mail_class' => $class,
            'recipient_hash' => hash('sha256', $onesignalId),
            'recipients' => 1,
            'invalid_recipients' => 0,
            'created_at' => now()->subDays($daysOld),
        ])->save();
    }
}
