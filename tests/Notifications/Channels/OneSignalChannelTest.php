<?php

namespace FlutterSdk\MagicStarter\Tests\Notifications\Channels;

use FlutterSdk\MagicStarter\Notifications\Channels\OneSignalChannel;
use FlutterSdk\MagicStarter\Support\OneSignalSubscriptions;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use onesignal\client\api\DefaultApi;
use onesignal\client\model\CreateNotificationSuccessResponse;
use onesignal\client\model\Notification as OneSignalNotification;
use onesignal\client\model\Subscription;
use onesignal\client\model\SubscriptionBody;
use RuntimeException;
use Throwable;

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

    public function test_send_error_names_the_configured_builder_on_a_wrong_payload_type(): void
    {
        // Arrange: the sms driver runs toSms, so the error must name toSms and
        // not the toOneSignal default it shares the class with.
        $client = $this->createMock(DefaultApi::class);
        $client->expects($this->never())->method('createNotification');

        $notification = new StubBadSmsNotification;
        $notifiable = new StubRoutableNotifiable('delta');

        $channel = new OneSignalChannel($client, 'toSms');

        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('::toSms() must return');

        // Act
        $channel->send($notifiable, $notification);
    }

    public function test_send_rejects_a_notifiable_it_cannot_address(): void
    {
        // Arrange: no routeNotificationForOneSignal and no getKey means there is
        // no alias to target, so the send must fail loudly rather than broadcast.
        $client = $this->createMock(DefaultApi::class);
        $client->expects($this->never())->method('createNotification');

        $channel = new OneSignalChannel($client);

        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must implement routeNotificationForOneSignal() or getKey()');

        // Act
        $channel->send(new StubUnaddressableNotifiable, new StubOneSignalNotification);
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

    public function test_send_reports_empty_id_response_without_throwing(): void
    {
        // Arrange: a zero-recipient send returns HTTP 200 with an empty id.
        $response = (new CreateNotificationSuccessResponse)->setId('');

        $client = $this->createMock(DefaultApi::class);
        $client->expects($this->once())
            ->method('createNotification')
            ->willReturn($response);

        $handlerMock = $this->createMock(ExceptionHandler::class);
        $handlerMock->expects($this->once())->method('report');
        $this->app->instance(ExceptionHandler::class, $handlerMock);

        $notification = new StubOneSignalNotification;
        $notifiable = new StubBasicNotifiable('55');
        $channel = new OneSignalChannel($client);

        // Act: an honest no-delivery is reported but must NOT throw.
        $result = $channel->send($notifiable, $notification);

        // Assert
        $this->assertSame($response, $result);
    }

    public function test_send_does_not_report_on_non_empty_id_response(): void
    {
        // Arrange: a genuine delivery returns a non-empty notification id.
        $response = (new CreateNotificationSuccessResponse)->setId('notif-123');

        $client = $this->createMock(DefaultApi::class);
        $client->expects($this->once())
            ->method('createNotification')
            ->willReturn($response);

        $handlerMock = $this->createMock(ExceptionHandler::class);
        $handlerMock->expects($this->never())->method('report');
        $this->app->instance(ExceptionHandler::class, $handlerMock);

        $notification = new StubOneSignalNotification;
        $notifiable = new StubBasicNotifiable('55');
        $channel = new OneSignalChannel($client);

        // Act
        $result = $channel->send($notifiable, $notification);

        // Assert
        $this->assertSame($response, $result);
    }

    public function test_ensure_sms_subscription_registers_and_persists_once(): void
    {
        // Arrange
        $this->bootUsersTable();
        config(['magic-starter.onesignal.app_id' => 'app-xyz']);

        $user = ConcreteUser::create([
            'name' => 'Alpha',
            'phone' => '+15551234567',
        ]);

        $captured = [];
        $client = $this->createMock(DefaultApi::class);
        $client->expects($this->once())
            ->method('createSubscription')
            ->willReturnCallback(function ($appId, $label, $id, $body) use (&$captured): SubscriptionBody {
                $captured = [$appId, $label, $id, $body];

                return new SubscriptionBody;
            });

        $helper = new OneSignalSubscriptions($client);

        // Act
        $this->assertTrue($helper->ensureSmsSubscription($user));

        // Assert: subscription persisted + correct POST arguments.
        $this->assertNotNull($user->fresh()->sms_registered_at);
        $this->assertSame('app-xyz', $captured[0]);
        $this->assertSame('external_id', $captured[1]);
        $this->assertSame('user_' . $user->getKey(), $captured[2]);

        $subscription = $captured[3]->getSubscription();
        $this->assertSame(Subscription::TYPE_SMS, $subscription->getType());
        $this->assertSame('+15551234567', $subscription->getToken());
        $this->assertTrue($subscription->getEnabled());

        // Idempotent: a second call short-circuits on the persisted guard
        // (the once() expectation above fails if createSubscription fires twice).
        $this->assertFalse($helper->ensureSmsSubscription($user));
    }

    public function test_ensure_sms_subscription_handles_202_transfer_as_success(): void
    {
        // Arrange
        $this->bootUsersTable();
        config(['magic-starter.onesignal.app_id' => 'app-xyz']);

        $user = ConcreteUser::create([
            'name' => 'Gamma',
            'phone' => '+15550001111',
        ]);

        // A 202 transfer returns a SubscriptionBody just like a 200 (new); the SDK
        // only throws on non-2xx, so a normal return models both new and transfer.
        $client = $this->createMock(DefaultApi::class);
        $client->expects($this->once())
            ->method('createSubscription')
            ->willReturn(new SubscriptionBody);

        $helper = new OneSignalSubscriptions($client);

        // Act + Assert
        $this->assertTrue($helper->ensureSmsSubscription($user));
        $this->assertNotNull($user->fresh()->sms_registered_at);
    }

    public function test_ensure_sms_subscription_never_logs_the_phone_on_failure(): void
    {
        // Arrange
        $this->bootUsersTable();
        config(['magic-starter.onesignal.app_id' => 'app-xyz']);

        $phone = '+15557654321';
        $user = ConcreteUser::create([
            'name' => 'Beta',
            'phone' => $phone,
        ]);

        $client = $this->createMock(DefaultApi::class);
        $client->method('createSubscription')->willThrowException(
            new RuntimeException('[400] Error connecting to the API (/apps/app-xyz/users/by/external_id/user_x/subscriptions)'),
        );

        $reported = null;
        $handlerMock = $this->createMock(ExceptionHandler::class);
        $handlerMock->method('report')->willReturnCallback(function (Throwable $exception) use (&$reported): void {
            $reported = $exception;
        });
        $this->app->instance(ExceptionHandler::class, $handlerMock);

        $helper = new OneSignalSubscriptions($client);

        // Act: failure is reported without throwing and the guard stays unset (retryable).
        $this->assertFalse($helper->ensureSmsSubscription($user));

        // Assert
        $this->assertNull($user->fresh()->sms_registered_at);
        $this->assertNotNull($reported);
        $this->assertStringNotContainsString($phone, $reported->getMessage());
    }

    public function test_ensure_sms_subscription_skips_a_user_without_a_phone(): void
    {
        // Arrange
        $this->bootUsersTable();
        config(['magic-starter.onesignal.app_id' => 'app-xyz']);

        $user = ConcreteUser::create(['name' => 'Delta']);

        $client = $this->createMock(DefaultApi::class);
        $client->expects($this->never())->method('createSubscription');

        $helper = new OneSignalSubscriptions($client);

        // Act + Assert: no phone means nothing to subscribe, and the guard stays
        // unset so the user registers as soon as a phone lands on the account.
        $this->assertFalse($helper->ensureSmsSubscription($user));
        $this->assertNull($user->fresh()->sms_registered_at);
    }

    public function test_ensure_sms_subscription_skips_a_model_with_no_resolvable_external_id(): void
    {
        // Arrange: a user model that does not route to OneSignal has no alias to
        // register the subscription against.
        $this->bootUsersTable();
        config(['magic-starter.onesignal.app_id' => 'app-xyz']);

        $user = StubUnroutableUser::create([
            'name' => 'Epsilon',
            'phone' => '+15559998888',
        ]);

        $client = $this->createMock(DefaultApi::class);
        $client->expects($this->never())->method('createSubscription');

        $helper = new OneSignalSubscriptions($client);

        // Act + Assert
        $this->assertFalse($helper->ensureSmsSubscription($user));
        $this->assertNull($user->fresh()->sms_registered_at);
    }

    /**
     * Sends [$data] through the channel and returns the `web_url` it settled on.
     *
     * `$data` is passed through untouched so a caller can hand it either shape
     * the SDK accepts: an array, or the `object` its own field is typed as.
     */
    private function webUrlFor(mixed $data, ?string $preset = null, ?string $url = null): ?string
    {
        $payload = (new OneSignalNotification)->setData($data);

        if ($preset !== null) {
            $payload->setWebUrl($preset);
        }

        if ($url !== null) {
            $payload->setUrl($url);
        }

        $captured = null;
        $client = $this->createMock(DefaultApi::class);
        $client->method('createNotification')
            ->willReturnCallback(function (OneSignalNotification $sent) use (&$captured): null {
                $captured = $sent;

                return null;
            });

        (new OneSignalChannel($client))->send(
            new StubRoutableNotifiable('alpha'),
            new StubOneSignalNotification($payload),
        );

        return $captured?->getWebUrl();
    }

    public function test_web_url_is_derived_from_the_deep_link(): void
    {
        config(['magic-starter.onesignal.web_origin' => 'https://app.example.com']);

        // A browser reads `web_url` and nothing else: a click is handled by the
        // service worker, which opens the launch url as an ordinary page load,
        // so no Dart is running yet to read the custom data the mobile clients
        // navigate from. Without this the recipient lands on the home page and
        // nothing reports a failure.
        $this->assertSame(
            'https://app.example.com/incidents/7',
            $this->webUrlFor(['deep_link' => '/incidents/7']),
        );
    }

    public function test_web_url_follows_the_key_order_the_flutter_client_uses(): void
    {
        config(['magic-starter.onesignal.web_origin' => 'https://app.example.com']);

        // `url` before `deep_link`, matching
        // `OneSignalDeeplinkHandler.extractUri`. Disagreeing would send the two
        // platforms to different screens from one payload.
        $this->assertSame(
            'https://app.example.com/first',
            $this->webUrlFor(['deep_link' => '/second', 'url' => '/first']),
        );
    }

    public function test_web_url_set_by_the_builder_is_left_alone(): void
    {
        config(['magic-starter.onesignal.web_origin' => 'https://app.example.com']);

        // A default, not a policy: an application that wants the browser
        // somewhere other than the in-app route has already said so.
        $this->assertSame(
            'https://marketing.example.com/promo',
            $this->webUrlFor(
                ['deep_link' => '/incidents/7'],
                'https://marketing.example.com/promo',
            ),
        );
    }

    public function test_no_web_url_without_a_configured_origin(): void
    {
        config(['magic-starter.onesignal.web_origin' => null]);

        // Absent means off, and off is the old behaviour rather than a broken
        // one. Guessing an origin would send every web recipient to a host the
        // client is not served from.
        $this->assertNull($this->webUrlFor(['deep_link' => '/incidents/7']));
    }

    public function test_web_url_is_derived_when_data_is_an_object(): void
    {
        config(['magic-starter.onesignal.web_origin' => 'https://app.example.com']);

        // The shape the SDK's own field is typed as (`object|null`), and the
        // one this package's push-test endpoint uses:
        // `PushTestController` sets `(object) $this->data` deliberately.
        // An is_array check skipped exactly the endpoint an adopter would
        // reach for to verify this feature.
        $this->assertSame(
            'https://app.example.com/incidents/7',
            $this->webUrlFor((object) ['deep_link' => '/incidents/7']),
        );
    }

    public function test_a_builder_set_url_opts_out_of_the_derivation(): void
    {
        config(['magic-starter.onesignal.web_origin' => 'https://app.example.com']);

        // The SDK documents `url` as "Omit if including web_url or app_url",
        // so setting both produces a payload contradicting its own contract.
        // A builder that named a launch url has already decided where the
        // click goes.
        $this->assertNull(
            $this->webUrlFor(
                ['deep_link' => '/incidents/7'],
                url: 'https://app.example.com/somewhere-else',
            ),
        );
    }

    public function test_an_absolute_deep_link_is_not_rewritten_onto_the_origin(): void
    {
        config(['magic-starter.onesignal.web_origin' => 'https://app.example.com']);

        // Only a rooted path is joinable. Concatenating an absolute link would
        // produce nonsense, and silently moving one onto another origin is
        // worse than leaving the browser on the home page.
        $this->assertNull($this->webUrlFor(['deep_link' => 'https://elsewhere.example/x']));
    }

    private function bootUsersTable(): void
    {
        Schema::dropIfExists('users');

        Schema::create('users', function ($table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('phone')->nullable();
            $table->timestamp('sms_registered_at')->nullable();
            $table->timestamps();
        });
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

class StubBadSmsNotification extends \Illuminate\Notifications\Notification
{
    public function toSms(mixed $notifiable): string
    {
        return 'not a OneSignal notification';
    }
}

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

class StubUnaddressableNotifiable {}

/**
 * A user model with no OneSignal routing, so no alias can be resolved for it.
 */
class StubUnroutableUser extends \Illuminate\Database\Eloquent\Model
{
    use \FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;

    protected $table = 'users';

    protected $guarded = [];
}
