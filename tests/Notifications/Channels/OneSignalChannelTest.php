<?php

namespace FlutterSdk\MagicStarter\Tests\Notifications\Channels;

use FlutterSdk\MagicStarter\Notifications\Channels\OneSignalChannel;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Contracts\Debug\ExceptionHandler;
use onesignal\client\api\DefaultApi;
use onesignal\client\model\Notification as OneSignalNotification;
use RuntimeException;

final class OneSignalChannelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['magic-starter.onesignal.app_id' => 'test-app-id']);
        config(['magic-starter.onesignal.target_channel' => 'push']);
    }

    public function test_send_invokes_create_notification_with_notification_payload(): void
    {
        // Arrange
        $capturedPayload = null;
        $client = $this->createMock(DefaultApi::class);
        $client->expects($this->once())
            ->method('createNotification')
            ->willReturnCallback(function (OneSignalNotification $payload) use (&$capturedPayload): null {
                $capturedPayload = $payload;

                return null;
            });

        $notification = new StubOneSignalNotification;
        $notifiable = new StubRoutableNotifiable('alpha');

        $channel = new OneSignalChannel($client);

        // Act
        $channel->send($notifiable, $notification);

        // Assert
        $this->assertSame($notification->toOneSignal($notifiable), $capturedPayload);
        $this->assertSame('test-app-id', $capturedPayload->getAppId());
        $this->assertSame('push', $capturedPayload->getTargetChannel());
        $this->assertSame(['external_id' => ['custom_alpha']], $capturedPayload->getIncludeAliases());
    }

    public function test_send_forces_app_id_from_config(): void
    {
        // Arrange
        $capturedPayload = null;
        $client = $this->createMock(DefaultApi::class);
        $client->expects($this->once())
            ->method('createNotification')
            ->willReturnCallback(function (OneSignalNotification $payload) use (&$capturedPayload): null {
                $capturedPayload = $payload;

                return null;
            });

        $seededPayload = new OneSignalNotification;
        $seededPayload->setAppId('user-set-wrong-id');

        $notification = new StubOneSignalNotification($seededPayload);
        $notifiable = new StubRoutableNotifiable('beta');

        $channel = new OneSignalChannel($client);

        // Act
        $channel->send($notifiable, $notification);

        // Assert
        $this->assertSame('test-app-id', $capturedPayload->getAppId());
    }

    public function test_send_applies_aliases_from_notifiable_when_notification_has_none(): void
    {
        // Arrange
        $capturedPayload = null;
        $client = $this->createMock(DefaultApi::class);
        $client->expects($this->once())
            ->method('createNotification')
            ->willReturnCallback(function (OneSignalNotification $payload) use (&$capturedPayload): null {
                $capturedPayload = $payload;

                return null;
            });

        $notification = new StubOneSignalNotification;
        $notifiable = new StubRoutableNotifiable('42');

        $channel = new OneSignalChannel($client);

        // Act
        $channel->send($notifiable, $notification);

        // Assert
        $this->assertSame(['external_id' => ['custom_42']], $capturedPayload->getIncludeAliases());
        $this->assertSame('push', $capturedPayload->getTargetChannel());
    }

    public function test_send_falls_back_to_getkey_when_notifiable_has_no_router(): void
    {
        // Arrange
        $capturedPayload = null;
        $client = $this->createMock(DefaultApi::class);
        $client->expects($this->once())
            ->method('createNotification')
            ->willReturnCallback(function (OneSignalNotification $payload) use (&$capturedPayload): null {
                $capturedPayload = $payload;

                return null;
            });

        $notification = new StubOneSignalNotification;
        $notifiable = new StubBasicNotifiable('777');

        $channel = new OneSignalChannel($client);

        // Act
        $channel->send($notifiable, $notification);

        // Assert
        $this->assertSame(['external_id' => ['777']], $capturedPayload->getIncludeAliases());
    }

    public function test_send_returns_null_when_notification_lacks_toonesignal(): void
    {
        // Arrange
        $client = $this->createMock(DefaultApi::class);
        $client->expects($this->never())->method('createNotification');

        $notification = new StubPlainNotification;
        $notifiable = new StubBasicNotifiable('999');

        $channel = new OneSignalChannel($client);

        // Act
        $result = $channel->send($notifiable, $notification);

        // Assert
        $this->assertNull($result);
    }

    public function test_send_reports_and_rethrows_on_sdk_exception(): void
    {
        // Arrange
        $client = $this->createMock(DefaultApi::class);
        $client->method('createNotification')->willThrowException(new RuntimeException('boom'));

        $handlerMock = $this->createMock(ExceptionHandler::class);
        $handlerMock->expects($this->once())
            ->method('report')
            ->with($this->isInstanceOf(RuntimeException::class));

        $this->app->instance(ExceptionHandler::class, $handlerMock);

        $notification = new StubOneSignalNotification;
        $notifiable = new StubBasicNotifiable('1');

        $channel = new OneSignalChannel($client);

        // Assert + Act
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $channel->send($notifiable, $notification);
    }
}

class StubOneSignalNotification extends \Illuminate\Notifications\Notification
{
    public ?OneSignalNotification $payload;

    public function __construct(?OneSignalNotification $payload = null)
    {
        $this->payload = $payload;
    }

    public function toOneSignal(mixed $notifiable): OneSignalNotification
    {
        return $this->payload ??= new OneSignalNotification;
    }
}

class StubPlainNotification extends \Illuminate\Notifications\Notification {}

class StubRoutableNotifiable
{
    public function __construct(private string $key) {}

    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function routeNotificationForOneSignal(): array
    {
        return ['external_id' => ['custom_' . $this->key]];
    }
}

class StubBasicNotifiable
{
    public function __construct(private string $key) {}

    public function getKey(): string
    {
        return $this->key;
    }
}
