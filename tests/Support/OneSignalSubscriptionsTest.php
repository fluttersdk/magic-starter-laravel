<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use ArrayObject;
use FlutterSdk\MagicStarter\Support\OneSignalSubscriptions;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Schema;
use onesignal\client\api\DefaultApi;
use onesignal\client\ApiException;
use onesignal\client\Configuration;
use onesignal\client\model\PropertiesObject;
use onesignal\client\model\Subscription;
use onesignal\client\model\User as OneSignalUser;
use RuntimeException;
use Throwable;
use TypeError;

final class OneSignalSubscriptionsTest extends TestCase
{
    private const PHONE = '+15557654321';

    private const STALE_PHONE = '+15550000001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootUsersTable();
        config(['magic-starter.onesignal.app_id' => 'app-xyz']);
    }

    public function test_ensure_sms_subscription_registers_and_persists_once(): void
    {
        // Arrange
        $user = $this->userWithPhone('+15551234567');

        $calls = [];
        $client = $this->createMock(DefaultApi::class);
        $client->expects($this->once())
            ->method('getUser')
            ->willReturnCallback(function (string $appId, string $label, string $id) use (&$calls): OneSignalUser {
                $calls[] = ['getUser', $appId, $label, $id];

                return $this->oneSignalUser([]);
            });
        $client->expects($this->once())
            ->method('createUser')
            ->willReturnCallback(function (string $appId, OneSignalUser $body) use (&$calls): OneSignalUser {
                $calls[] = ['createUser', $appId, $body];

                return $body;
            });

        $helper = new OneSignalSubscriptions($client);

        // Act
        $this->assertTrue($helper->ensureSmsSubscription($user));

        // Assert: guard persisted, read before write, one SMS subscription for the phone.
        $this->assertNotNull($user->fresh()->sms_registered_at);
        $this->assertSame(['getUser', 'app-xyz', 'external_id', 'user_' . $user->getKey()], $calls[0]);
        $this->assertSame(['createUser', 'app-xyz'], array_slice($calls[1], 0, 2));

        $subscriptions = $calls[1][2]->getSubscriptions();
        $this->assertCount(1, $subscriptions);
        $this->assertSame(Subscription::TYPE_SMS, $subscriptions[0]->getType());
        $this->assertSame('+15551234567', $subscriptions[0]->getToken());
        $this->assertTrue($subscriptions[0]->getEnabled());

        // Idempotent: a second call short-circuits on the persisted guard
        // (the once() expectations above fail if OneSignal is called again).
        $this->assertFalse($helper->ensureSmsSubscription($user));
    }

    public function test_ensure_sms_subscription_treats_an_already_deleted_stale_subscription_as_success(): void
    {
        // Arrange
        $user = $this->userWithPhone(self::PHONE);

        $client = $this->createMock(DefaultApi::class);
        $client->method('getUser')->willReturn(
            $this->oneSignalUser([$this->subscription('sub-old', self::STALE_PHONE)]),
        );
        $client->expects($this->once())
            ->method('deleteSubscription')
            ->willThrowException(new ApiException('[404] gone', 404));
        $client->expects($this->once())->method('createUser');

        // Act + Assert
        $this->assertTrue((new OneSignalSubscriptions($client))->ensureSmsSubscription($user));
        $this->assertNotNull($user->fresh()->sms_registered_at);
    }

    public function test_ensure_sms_subscription_never_reports_the_phone_when_the_create_fails(): void
    {
        // Arrange
        $user = $this->userWithPhone(self::PHONE);

        $client = $this->createMock(DefaultApi::class);
        $client->method('getUser')->willThrowException(new ApiException('[404] none', 404));
        $client->method('createUser')->willThrowException(
            new ApiException('[400] invalid sms token ' . self::PHONE . ' in body', 400, null, self::PHONE),
        );

        $reported = $this->captureReports();

        // Act: the failure is reported without throwing and the guard stays unset (retryable).
        $this->assertFalse((new OneSignalSubscriptions($client))->ensureSmsSubscription($user));

        // Assert
        $this->assertNull($user->fresh()->sms_registered_at);
        $this->assertCount(1, $reported);
        $this->assertStringNotContainsString(self::PHONE, $reported[0]->getMessage());
        $this->assertSame('OneSignal SMS registration failed: createUser returned HTTP 400', $reported[0]->getMessage());
        $this->assertNull($reported[0]->getPrevious());
    }

    public function test_ensure_sms_subscription_reports_an_sdk_error_without_throwing(): void
    {
        // Arrange: an `Error`, not an `Exception`, as the SDK raises when it
        // deserialises a response shape it does not expect.
        $user = $this->userWithPhone(self::PHONE);

        $client = $this->createMock(DefaultApi::class);
        $client->method('getUser')->willThrowException(new TypeError('unexpected response shape'));
        $client->expects($this->never())->method('createUser');

        $reported = $this->captureReports();

        // Act: the send is not poisoned, and the guard stays unset (retryable).
        $this->assertFalse((new OneSignalSubscriptions($client))->ensureSmsSubscription($user));

        // Assert
        $this->assertNull($user->fresh()->sms_registered_at);
        $this->assertCount(1, $reported);
        $this->assertSame('OneSignal SMS registration failed: getUser threw TypeError', $reported[0]->getMessage());
        $this->assertNull($reported[0]->getPrevious());
    }

    public function test_ensure_sms_subscription_skips_a_user_without_a_phone(): void
    {
        // Arrange
        $user = ConcreteUser::create(['name' => 'Delta']);

        $client = $this->createMock(DefaultApi::class);
        $client->expects($this->never())->method('getUser');
        $client->expects($this->never())->method('createUser');

        // Act + Assert: no phone means nothing to subscribe, and the guard stays
        // unset so the user registers as soon as a phone lands on the account.
        $this->assertFalse((new OneSignalSubscriptions($client))->ensureSmsSubscription($user));
        $this->assertNull($user->fresh()->sms_registered_at);
    }

    public function test_ensure_sms_subscription_skips_a_model_with_no_resolvable_external_id(): void
    {
        // Arrange: a user model that does not route to OneSignal has no alias to
        // register the subscription against.
        $user = StubUnroutableUser::create([
            'name' => 'Epsilon',
            'phone' => '+15559998888',
        ]);

        $client = $this->createMock(DefaultApi::class);
        $client->expects($this->never())->method('getUser');
        $client->expects($this->never())->method('createUser');

        // Act + Assert
        $this->assertFalse((new OneSignalSubscriptions($client))->ensureSmsSubscription($user));
        $this->assertNull($user->fresh()->sms_registered_at);
    }

    public function test_ensure_sms_subscription_creates_a_never_seen_user_in_the_users_language(): void
    {
        // Arrange: ConcreteUser has no locale preference, so MailLocale reads the app locale.
        app()->setLocale('tr');
        $user = $this->userWithPhone(self::PHONE);

        $created = null;
        $client = $this->createMock(DefaultApi::class);
        $client->method('getUser')->willThrowException(new ApiException('[404] none', 404));
        $client->expects($this->once())
            ->method('createUser')
            ->willReturnCallback(function (string $appId, OneSignalUser $body) use (&$created): OneSignalUser {
                $created = $body;

                return $body;
            });
        $client->expects($this->never())->method('deleteSubscription');

        // Act
        $this->assertTrue((new OneSignalSubscriptions($client))->ensureSmsSubscription($user));

        // Assert
        $this->assertSame(['external_id' => 'user_' . $user->getKey()], $created->getIdentity());
        $this->assertCount(1, $created->getSubscriptions());
        $this->assertSame(Subscription::TYPE_SMS, $created->getSubscriptions()[0]->getType());
        $this->assertSame(self::PHONE, $created->getSubscriptions()[0]->getToken());
        $this->assertSame('tr', $created->getProperties()->getLanguage());
        $this->assertNotNull($user->fresh()->sms_registered_at);
    }

    public function test_ensure_sms_subscription_deletes_a_stale_number_before_registering_the_current_one(): void
    {
        // Arrange
        $user = $this->userWithPhone(self::PHONE);

        $calls = [];
        $client = $this->createMock(DefaultApi::class);
        $client->method('getUser')->willReturn($this->oneSignalUser([
            $this->subscription('sub-old', self::STALE_PHONE),
            $this->subscription('sub-push', 'device-token', 'iOSPush'),
        ]));
        $client->expects($this->once())
            ->method('deleteSubscription')
            ->willReturnCallback(function (string $appId, string $id) use (&$calls): void {
                $calls[] = ['deleteSubscription', $appId, $id];
            });
        $client->expects($this->once())
            ->method('createUser')
            ->willReturnCallback(function () use (&$calls): OneSignalUser {
                $calls[] = ['createUser'];

                return new OneSignalUser;
            });

        // Act
        $this->assertTrue((new OneSignalSubscriptions($client))->ensureSmsSubscription($user));

        // Assert: only the stale SMS subscription is deleted, and before the create.
        $this->assertSame([['deleteSubscription', 'app-xyz', 'sub-old'], ['createUser']], $calls);
        $this->assertNotNull($user->fresh()->sms_registered_at);
    }

    public function test_ensure_sms_subscription_creates_nothing_when_the_current_number_is_registered(): void
    {
        // Arrange
        $user = $this->userWithPhone(self::PHONE);

        $client = $this->createMock(DefaultApi::class);
        $client->method('getUser')->willReturn(
            $this->oneSignalUser([$this->subscription('sub-current', self::PHONE)]),
        );
        $client->expects($this->never())->method('createUser');
        $client->expects($this->never())->method('deleteSubscription');

        // Act + Assert
        $this->assertTrue((new OneSignalSubscriptions($client))->ensureSmsSubscription($user));
        $this->assertNotNull($user->fresh()->sms_registered_at);
    }

    public function test_ensure_sms_subscription_never_re_enables_a_disabled_subscription_for_the_current_number(): void
    {
        // Arrange: a disabled subscription is an opt-out, and re-enabling it
        // without consent breaches carrier rules.
        $user = $this->userWithPhone(self::PHONE);

        $client = $this->createMock(DefaultApi::class);
        $client->method('getUser')->willReturn(
            $this->oneSignalUser([$this->subscription('sub-current', self::PHONE, enabled: false)]),
        );
        $client->expects($this->never())->method('createUser');
        $client->expects($this->never())->method('updateSubscription');
        $client->expects($this->never())->method('deleteSubscription');

        // Act + Assert
        $this->assertTrue((new OneSignalSubscriptions($client))->ensureSmsSubscription($user));
        $this->assertNotNull($user->fresh()->sms_registered_at);
    }

    public function test_ensure_sms_subscription_keeps_a_disabled_stale_subscription_and_registers_the_current_number(): void
    {
        // Arrange: a disabled subscription is a carrier opt-out (STOP). Deleting it would
        // wipe OneSignal's record and let createUser re-register that number as enabled
        // if the user ever returns to it; it delivers nothing, so keeping it costs nothing.
        $user = $this->userWithPhone(self::PHONE);

        $created = null;
        $client = $this->createMock(DefaultApi::class);
        $client->method('getUser')->willReturn(
            $this->oneSignalUser([$this->subscription('sub-optout', self::STALE_PHONE, enabled: false)]),
        );
        $client->expects($this->never())->method('deleteSubscription');
        $client->expects($this->once())
            ->method('createUser')
            ->willReturnCallback(function (string $appId, OneSignalUser $body) use (&$created): OneSignalUser {
                $created = $body;

                return $body;
            });

        // Act
        $this->assertTrue((new OneSignalSubscriptions($client))->ensureSmsSubscription($user));

        // Assert
        $this->assertSame(self::PHONE, $created->getSubscriptions()[0]->getToken());
        $this->assertNotNull($user->fresh()->sms_registered_at);
    }

    public function test_ensure_sms_subscription_sends_and_compares_the_phone_in_its_normalised_form(): void
    {
        // Arrange: Filament forms and consumer code store the number as typed.
        $user = $this->userWithPhone('+1 (555) 000-0001');

        $created = null;
        $client = $this->createMock(DefaultApi::class);
        $client->method('getUser')->willThrowException(new ApiException('[404] none', 404));
        $client->expects($this->once())
            ->method('createUser')
            ->willReturnCallback(function (string $appId, OneSignalUser $body) use (&$created): OneSignalUser {
                $created = $body;

                return $body;
            });

        // Act
        $this->assertTrue((new OneSignalSubscriptions($client))->ensureSmsSubscription($user));

        // Assert
        $this->assertSame(self::STALE_PHONE, $created->getSubscriptions()[0]->getToken());
    }

    public function test_ensure_sms_subscription_treats_a_formatted_phone_matching_the_token_as_registered(): void
    {
        // Arrange
        $user = $this->userWithPhone('+1 (555) 000-0001');

        $client = $this->createMock(DefaultApi::class);
        $client->method('getUser')->willReturn(
            $this->oneSignalUser([$this->subscription('sub-current', self::STALE_PHONE)]),
        );
        $client->expects($this->never())->method('createUser');
        $client->expects($this->never())->method('deleteSubscription');

        // Act + Assert
        $this->assertTrue((new OneSignalSubscriptions($client))->ensureSmsSubscription($user));
        $this->assertNotNull($user->fresh()->sms_registered_at);
    }

    public function test_ensure_sms_subscription_sends_the_primary_language_subtag(): void
    {
        // Arrange
        app()->setLocale('pt_BR');
        $user = $this->userWithPhone(self::PHONE);

        $created = null;
        $client = $this->createMock(DefaultApi::class);
        $client->method('getUser')->willThrowException(new ApiException('[404] none', 404));
        $client->method('createUser')
            ->willReturnCallback(function (string $appId, OneSignalUser $body) use (&$created): OneSignalUser {
                $created = $body;

                return $body;
            });

        // Act
        $this->assertTrue((new OneSignalSubscriptions($client))->ensureSmsSubscription($user));

        // Assert
        $this->assertSame('pt', $created->getProperties()->getLanguage());
    }

    public function test_ensure_sms_subscription_leaves_the_guard_unset_when_a_delete_fails(): void
    {
        // Arrange
        $user = $this->userWithPhone(self::PHONE);

        $client = $this->createMock(DefaultApi::class);
        $client->method('getUser')->willReturn(
            $this->oneSignalUser([$this->subscription('sub-old', self::STALE_PHONE)]),
        );
        $client->method('deleteSubscription')->willThrowException(
            new ApiException('[500] ' . self::STALE_PHONE . ' ' . self::PHONE, 500),
        );
        $client->expects($this->never())->method('createUser');

        $reported = $this->captureReports();

        // Act
        $this->assertFalse((new OneSignalSubscriptions($client))->ensureSmsSubscription($user));

        // Assert
        $this->assertNull($user->fresh()->sms_registered_at);
        $this->assertCount(1, $reported);
        $this->assertStringNotContainsString(self::PHONE, $reported[0]->getMessage());
        $this->assertStringNotContainsString(self::STALE_PHONE, $reported[0]->getMessage());
        $this->assertSame(
            'OneSignal SMS registration failed: deleteSubscription returned HTTP 500',
            $reported[0]->getMessage(),
        );
    }

    public function test_prune_sms_subscriptions_keeps_the_kept_number_and_deletes_the_others(): void
    {
        // Arrange
        $deleted = [];
        $client = $this->createMock(DefaultApi::class);
        $client->expects($this->once())
            ->method('getUser')
            ->with('app-xyz', 'external_id', 'user_1')
            ->willReturn($this->oneSignalUser([
                $this->subscription('sub-keep', self::PHONE),
                $this->subscription('sub-old', self::STALE_PHONE),
                $this->subscription('sub-push', 'device-token', 'iOSPush'),
            ]));
        $client->method('deleteSubscription')
            ->willReturnCallback(function (string $appId, string $id) use (&$deleted): void {
                $deleted[] = $id;
            });

        // Act
        (new OneSignalSubscriptions($client))->pruneSmsSubscriptions('user_1', self::PHONE);

        // Assert
        $this->assertSame(['sub-old'], $deleted);
    }

    public function test_prune_sms_subscriptions_deletes_every_enabled_sms_subscription_without_a_kept_number(): void
    {
        // Arrange
        $deleted = [];
        $client = $this->createMock(DefaultApi::class);
        $client->method('getUser')->willReturn($this->oneSignalUser([
            $this->subscription('sub-a', self::PHONE),
            $this->subscription('sub-b', self::STALE_PHONE, enabled: false),
            $this->subscription('sub-push', 'device-token', 'iOSPush'),
        ]));
        $client->method('deleteSubscription')
            ->willReturnCallback(function (string $appId, string $id) use (&$deleted): void {
                $deleted[] = $id;
            });

        // Act
        (new OneSignalSubscriptions($client))->pruneSmsSubscriptions('user_1', null);

        // Assert: the disabled one is a carrier opt-out and stays on record.
        $this->assertSame(['sub-a'], $deleted);
    }

    public function test_prune_sms_subscriptions_compares_the_kept_phone_in_its_normalised_form(): void
    {
        // Arrange
        $deleted = [];
        $client = $this->createMock(DefaultApi::class);
        $client->method('getUser')->willReturn($this->oneSignalUser([
            $this->subscription('sub-keep', self::STALE_PHONE),
            $this->subscription('sub-old', self::PHONE),
        ]));
        $client->method('deleteSubscription')
            ->willReturnCallback(function (string $appId, string $id) use (&$deleted): void {
                $deleted[] = $id;
            });

        // Act
        (new OneSignalSubscriptions($client))->pruneSmsSubscriptions('user_1', '+1 (555) 000-0001');

        // Assert
        $this->assertSame(['sub-old'], $deleted);
    }

    public function test_prune_sms_subscriptions_is_a_no_op_for_a_user_onesignal_never_saw(): void
    {
        // Arrange
        $client = $this->createMock(DefaultApi::class);
        $client->method('getUser')->willThrowException(new ApiException('[404] none', 404));
        $client->expects($this->never())->method('deleteSubscription');

        // Act + Assert
        (new OneSignalSubscriptions($client))->pruneSmsSubscriptions('user_1', null);
        $this->addToAssertionCount(1);
    }

    public function test_prune_sms_subscriptions_throws_a_composed_exception_on_failure(): void
    {
        // Arrange
        $client = $this->createMock(DefaultApi::class);
        $client->method('getUser')->willThrowException(new ApiException('[500] ' . self::PHONE, 500));

        // Assert
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('getUser returned HTTP 500');

        // Act
        try {
            (new OneSignalSubscriptions($client))->pruneSmsSubscriptions('user_1', null);
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString(self::PHONE, $exception->getMessage());
            $this->assertNull($exception->getPrevious());

            throw $exception;
        }
    }

    public function test_prune_and_delete_user_make_no_call_without_a_configured_app_id(): void
    {
        // Arrange
        config(['magic-starter.onesignal.app_id' => null]);

        $client = $this->createMock(DefaultApi::class);
        $client->expects($this->never())->method('getUser');
        $client->expects($this->never())->method('deleteUser');

        $helper = new OneSignalSubscriptions($client);

        // Act
        $helper->pruneSmsSubscriptions('user_1', null);
        $helper->deleteUser('user_1');

        // Assert
        $this->addToAssertionCount(1);
    }

    public function test_delete_user_is_a_no_op_when_the_user_is_already_gone(): void
    {
        // Arrange
        $client = $this->createMock(DefaultApi::class);
        $client->expects($this->once())
            ->method('deleteUser')
            ->with('app-xyz', 'external_id', 'user_1')
            ->willThrowException(new ApiException('[404] gone', 404));

        // Act + Assert
        (new OneSignalSubscriptions($client))->deleteUser('user_1');
        $this->addToAssertionCount(1);
    }

    public function test_delete_user_throws_a_composed_exception_on_failure(): void
    {
        // Arrange
        $client = $this->createMock(DefaultApi::class);
        $client->method('deleteUser')->willThrowException(new ApiException('[500] boom', 500));

        // Assert
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('deleteUser returned HTTP 500');

        // Act
        (new OneSignalSubscriptions($client))->deleteUser('user_1');
    }

    public function test_provisioned_is_false_without_an_app_id(): void
    {
        config([
            'magic-starter.onesignal.app_id' => null,
            'magic-starter.onesignal.rest_api_key' => 'key',
        ]);

        $this->assertFalse(OneSignalSubscriptions::provisioned());
    }

    public function test_provisioned_is_false_without_a_rest_key(): void
    {
        config([
            'magic-starter.onesignal.rest_api_key' => null,
            'services.onesignal.rest_api_key' => '',
        ]);

        $this->assertFalse(OneSignalSubscriptions::provisioned());
    }

    public function test_provisioned_is_true_with_an_app_id_and_a_rest_key(): void
    {
        config([
            'magic-starter.onesignal.rest_api_key' => null,
            'services.onesignal.rest_api_key' => 'key',
        ]);

        $this->assertTrue(OneSignalSubscriptions::provisioned());
    }

    public function test_real_sdk_deletes_only_the_stale_sms_subscription_then_creates_the_user(): void
    {
        // Arrange
        $user = $this->userWithPhone(self::PHONE);
        $externalId = 'user_' . $user->getKey();

        $history = [];
        $client = $this->realClient([
            new Response(200, [], json_encode([
                'identity' => ['external_id' => $externalId],
                'subscriptions' => [
                    ['id' => 'sub-old', 'type' => 'SMS', 'token' => self::STALE_PHONE, 'enabled' => true],
                    ['id' => 'sub-push', 'type' => 'iOSPush', 'token' => 't', 'enabled' => true],
                ],
            ])),
            new Response(202, [], '{}'),
            new Response(202, [], json_encode(['identity' => ['external_id' => $externalId]])),
        ], $history);

        app()->setLocale('tr');

        // Act
        $this->assertTrue((new OneSignalSubscriptions($client))->ensureSmsSubscription($user));

        // Assert: GET, then DELETE of the stale SMS subscription only, then POST of the user.
        $this->assertCount(3, $history);

        $this->assertSame('GET', $history[0]['request']->getMethod());
        $this->assertSame("/apps/app-xyz/users/by/external_id/{$externalId}", $history[0]['request']->getUri()->getPath());

        $this->assertSame('DELETE', $history[1]['request']->getMethod());
        $this->assertSame('/apps/app-xyz/subscriptions/sub-old', $history[1]['request']->getUri()->getPath());

        $this->assertSame('POST', $history[2]['request']->getMethod());
        $this->assertSame('/apps/app-xyz/users', $history[2]['request']->getUri()->getPath());

        $body = json_decode((string) $history[2]['request']->getBody(), true);
        $this->assertSame($externalId, $body['identity']['external_id']);
        $this->assertSame('SMS', $body['subscriptions'][0]['type']);
        $this->assertSame(self::PHONE, $body['subscriptions'][0]['token']);
        $this->assertSame('tr', $body['properties']['language']);
        $this->assertNotNull($user->fresh()->sms_registered_at);
    }

    public function test_real_sdk_creates_the_user_straight_away_when_onesignal_answers_404(): void
    {
        // Arrange
        $user = $this->userWithPhone(self::PHONE);

        $history = [];
        $client = $this->realClient([
            new Response(404, [], json_encode(['errors' => [['title' => 'User not found']]])),
            new Response(202, [], '{}'),
        ], $history);

        // Act
        $this->assertTrue((new OneSignalSubscriptions($client))->ensureSmsSubscription($user));

        // Assert
        $this->assertCount(2, $history);
        $this->assertSame('GET', $history[0]['request']->getMethod());
        $this->assertSame('POST', $history[1]['request']->getMethod());
        $this->assertSame('/apps/app-xyz/users', $history[1]['request']->getUri()->getPath());
        $this->assertNotNull($user->fresh()->sms_registered_at);
    }

    public function test_real_sdk_failure_reports_neither_the_phone_nor_the_response_body(): void
    {
        // Arrange
        $user = $this->userWithPhone(self::PHONE);

        $history = [];
        $client = $this->realClient([
            new Response(500, [], 'upstream echoed ' . self::PHONE . ' and ' . self::STALE_PHONE),
        ], $history);

        $reported = $this->captureReports();

        // Act
        $this->assertFalse((new OneSignalSubscriptions($client))->ensureSmsSubscription($user));

        // Assert
        $this->assertCount(1, $history);
        $this->assertNull($user->fresh()->sms_registered_at);
        $this->assertCount(1, $reported);
        $this->assertSame('OneSignal SMS registration failed: getUser returned HTTP 500', $reported[0]->getMessage());
        $this->assertStringNotContainsString(self::PHONE, $reported[0]->getMessage());
        $this->assertStringNotContainsString('upstream echoed', $reported[0]->getMessage());
        $this->assertNull($reported[0]->getPrevious());
    }

    /**
     * A real DefaultApi over a Guzzle mock handler, so the SDK's own request
     * building and response mapping are exercised instead of a PHPUnit double.
     *
     * @param  array<int, Response>  $responses
     * @param  array<int, array<string, mixed>>  $history  Filled by reference with every sent request.
     */
    private function realClient(array $responses, array &$history): DefaultApi
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        $config = Configuration::getDefaultConfiguration()->setRestApiKeyToken('key');

        return new DefaultApi(new Client(['handler' => $stack]), $config);
    }

    /**
     * Collects every exception the helper reports, in order.
     *
     * @return ArrayObject<int, Throwable>
     */
    private function captureReports(): ArrayObject
    {
        $reported = new ArrayObject;

        $handler = $this->createMock(ExceptionHandler::class);
        $handler->method('report')->willReturnCallback(function (Throwable $exception) use ($reported): void {
            $reported->append($exception);
        });
        $this->app->instance(ExceptionHandler::class, $handler);

        return $reported;
    }

    private function userWithPhone(string $phone): ConcreteUser
    {
        return ConcreteUser::create([
            'name' => 'Alpha',
            'phone' => $phone,
        ]);
    }

    /**
     * @param  array<int, Subscription>  $subscriptions
     */
    private function oneSignalUser(array $subscriptions): OneSignalUser
    {
        return (new OneSignalUser)
            ->setIdentity(['external_id' => 'user_x'])
            ->setProperties(new PropertiesObject)
            ->setSubscriptions($subscriptions);
    }

    private function subscription(
        string $id,
        string $token,
        string $type = Subscription::TYPE_SMS,
        bool $enabled = true,
    ): Subscription {
        return (new Subscription)
            ->setId($id)
            ->setType($type)
            ->setToken($token)
            ->setEnabled($enabled);
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

/**
 * A user model with no OneSignal routing, so no alias can be resolved for it.
 */
class StubUnroutableUser extends \Illuminate\Database\Eloquent\Model
{
    use \FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;

    protected $table = 'users';

    protected $guarded = [];
}
