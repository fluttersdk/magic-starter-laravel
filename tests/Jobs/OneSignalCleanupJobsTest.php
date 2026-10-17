<?php

namespace FlutterSdk\MagicStarter\Tests\Jobs;

use FlutterSdk\MagicStarter\Jobs\DeleteOneSignalUser;
use FlutterSdk\MagicStarter\Jobs\PruneSmsSubscriptions;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Support\Facades\Schema;
use onesignal\client\api\DefaultApi;
use onesignal\client\model\PropertiesObject;
use onesignal\client\model\Subscription;
use onesignal\client\model\User as OneSignalUser;

final class OneSignalCleanupJobsTest extends TestCase
{
    private const PHONE = '+15557654321';

    private const STALE_PHONE = '+15550000001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootUsersTable();
        config(['magic-starter.onesignal.app_id' => 'app-xyz']);
    }

    public function test_prune_deletes_the_subscription_of_the_old_number_and_keeps_the_current_one(): void
    {
        // Arrange: the phone changes in the database AFTER the job was queued.
        $this->configureRestKey();
        $user = $this->userWithPhone(self::STALE_PHONE);
        $payload = serialize(new PruneSmsSubscriptions($user));
        $user->forceFill(['phone' => self::PHONE])->save();
        $job = unserialize($payload);

        $deleted = [];
        $client = $this->createMock(DefaultApi::class);
        $client->expects($this->once())
            ->method('getUser')
            ->with('app-xyz', 'external_id', 'user_' . $user->getKey())
            ->willReturn($this->oneSignalUser([
                $this->subscription('sub-keep', self::PHONE),
                $this->subscription('sub-old', self::STALE_PHONE),
                $this->subscription('sub-push', 'device-token', 'iOSPush'),
            ]));
        $client->method('deleteSubscription')
            ->willReturnCallback(function (string $appId, string $id) use (&$deleted): void {
                $deleted[] = $id;
            });
        $this->app->instance(DefaultApi::class, $client);

        // Act
        $job->handle();

        // Assert: the number read at handle time wins, not the one at dispatch time.
        $this->assertSame(['sub-old'], $deleted);
    }

    public function test_prune_with_a_cleared_phone_deletes_every_sms_subscription(): void
    {
        // Arrange
        $this->configureRestKey();
        $user = $this->userWithPhone(self::PHONE);
        $user->forceFill(['phone' => null])->save();

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
        $this->app->instance(DefaultApi::class, $client);

        // Act
        (new PruneSmsSubscriptions($user))->handle();

        // Assert
        $this->assertSame(['sub-a', 'sub-b'], $deleted);
    }

    public function test_prune_treats_a_blank_phone_as_cleared(): void
    {
        // Arrange
        $this->configureRestKey();
        $user = $this->userWithPhone('   ');

        $deleted = [];
        $client = $this->createMock(DefaultApi::class);
        $client->method('getUser')->willReturn($this->oneSignalUser([
            $this->subscription('sub-a', self::PHONE),
        ]));
        $client->method('deleteSubscription')
            ->willReturnCallback(function (string $appId, string $id) use (&$deleted): void {
                $deleted[] = $id;
            });
        $this->app->instance(DefaultApi::class, $client);

        // Act
        (new PruneSmsSubscriptions($user))->handle();

        // Assert
        $this->assertSame(['sub-a'], $deleted);
    }

    public function test_prune_is_a_no_op_when_onesignal_is_not_provisioned(): void
    {
        // Arrange
        $this->clearRestKey();
        $user = $this->userWithPhone(self::PHONE);

        $client = $this->createMock(DefaultApi::class);
        $client->expects($this->never())->method('getUser');
        $client->expects($this->never())->method('deleteSubscription');
        $this->app->instance(DefaultApi::class, $client);

        // Act
        (new PruneSmsSubscriptions($user))->handle();

        // Assert
        $this->addToAssertionCount(1);
    }

    public function test_both_jobs_run_without_resolving_the_client_when_nothing_is_configured(): void
    {
        // Arrange: no REST key and no bound client. The singleton throws when
        // it is built without a key, so any resolution before the provisioned
        // check would fail this test.
        $this->clearRestKey();
        $user = $this->userWithPhone(self::PHONE);

        // Act
        (new PruneSmsSubscriptions($user))->handle();
        (new DeleteOneSignalUser('user_' . $user->getKey()))->handle();

        // Assert
        $this->assertFalse($this->app->resolved(DefaultApi::class));
    }

    public function test_delete_removes_the_onesignal_user_by_external_id(): void
    {
        // Arrange
        $this->configureRestKey();

        $client = $this->createMock(DefaultApi::class);
        $client->expects($this->once())
            ->method('deleteUser')
            ->with('app-xyz', 'external_id', 'user_42');
        $this->app->instance(DefaultApi::class, $client);

        // Act
        (new DeleteOneSignalUser('user_42'))->handle();
    }

    public function test_delete_is_a_no_op_when_onesignal_is_not_provisioned(): void
    {
        // Arrange
        $this->clearRestKey();

        $client = $this->createMock(DefaultApi::class);
        $client->expects($this->never())->method('deleteUser');
        $this->app->instance(DefaultApi::class, $client);

        // Act
        (new DeleteOneSignalUser('user_42'))->handle();

        // Assert
        $this->addToAssertionCount(1);
    }

    public function test_the_prune_payload_never_carries_the_phone(): void
    {
        // Arrange
        $user = $this->userWithPhone(self::PHONE);

        // Act
        $payload = serialize(new PruneSmsSubscriptions($user));

        // Assert
        $this->assertStringNotContainsString(self::PHONE, $payload);
    }

    public function test_prune_drops_itself_when_the_user_is_purged_before_it_runs(): void
    {
        $this->assertTrue((new PruneSmsSubscriptions($this->userWithPhone(self::PHONE)))->deleteWhenMissingModels);
    }

    private function configureRestKey(): void
    {
        config([
            'magic-starter.onesignal.rest_api_key' => 'key',
            'services.onesignal.rest_api_key' => null,
        ]);
    }

    private function clearRestKey(): void
    {
        config([
            'magic-starter.onesignal.rest_api_key' => null,
            'services.onesignal.rest_api_key' => null,
        ]);
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
