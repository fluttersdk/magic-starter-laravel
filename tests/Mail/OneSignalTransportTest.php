<?php

namespace FlutterSdk\MagicStarter\Tests\Mail;

use FlutterSdk\MagicStarter\Mail\OneSignalTransport;
use FlutterSdk\MagicStarter\Tests\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use onesignal\client\api\DefaultApi;
use onesignal\client\ApiException;
use onesignal\client\Configuration;
use onesignal\client\model\CreateNotificationSuccessResponse;
use onesignal\client\model\GenericError;
use onesignal\client\model\Notification as OneSignalNotification;
use onesignal\client\model\RateLimitError;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;

final class OneSignalTransportTest extends TestCase
{
    private const SECRET_ADDRESS = 'secret.person@example.org';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'magic-starter.onesignal.app_id' => 'app-xyz',
            'magic-starter.onesignal.rest_api_key' => 'rest-key',
        ]);
    }

    public function test_send_maps_the_message_onto_the_notification(): void
    {
        // Arrange
        $payload = null;
        $client = $this->createMock(DefaultApi::class);
        $client->expects($this->once())
            ->method('createNotification')
            ->willReturnCallback(function (OneSignalNotification $notification) use (&$payload) {
                $payload = $notification;

                return $this->accepted('notif-1');
            });
        $this->app->instance(DefaultApi::class, $client);

        $email = $this->email()
            ->to('first@example.test', 'second@example.test')
            ->from('Uptizm <hello@example.test>')
            ->replyTo('Support <support@example.test>');

        // Act
        $this->transport()->send($email);

        // Assert
        $this->assertSame('app-xyz', $payload->getAppId());
        $this->assertSame(['first@example.test', 'second@example.test'], $payload->getEmailTo());
        $this->assertSame('Welcome', $payload->getEmailSubject());
        $this->assertSame('<p>Hello</p>', $payload->getEmailBody());
        $this->assertSame('hello@example.test', $payload->getEmailFromAddress());
        $this->assertSame('Uptizm', $payload->getEmailFromName());
        $this->assertSame('support@example.test', $payload->getEmailReplyToAddress());
        $this->assertTrue($payload->getIncludeUnsubscribed());
        $this->assertTrue($payload->getDisableEmailClickTracking());
        $this->assertNull($payload->getIncludedSegments());
        $this->assertNull($payload->getFilters());
    }

    public function test_send_omits_reply_to_when_the_message_has_none(): void
    {
        // Arrange
        $payload = null;
        $client = $this->createMock(DefaultApi::class);
        $client->method('createNotification')
            ->willReturnCallback(function (OneSignalNotification $notification) use (&$payload) {
                $payload = $notification;

                return $this->accepted('notif-1');
            });
        $this->app->instance(DefaultApi::class, $client);

        // Act
        $this->transport()->send($this->email());

        // Assert
        $this->assertNull($payload->getEmailReplyToAddress());
    }

    public function test_send_derives_a_stable_idempotency_key_from_the_message_id(): void
    {
        // Arrange
        $keys = [];
        $client = $this->createMock(DefaultApi::class);
        $client->method('createNotification')
            ->willReturnCallback(function (OneSignalNotification $notification) use (&$keys) {
                $keys[] = $notification->getIdempotencyKey();

                return $this->accepted('notif-1');
            });
        $this->app->instance(DefaultApi::class, $client);

        $withId = fn (): Email => tap(
            $this->email(),
            fn (Email $email) => $email->getHeaders()->addIdHeader('Message-ID', 'fixed-id@uptizm.test'),
        );

        // Act
        $this->transport()->send($withId());
        $this->transport()->send($withId());
        $this->transport()->send($this->email());
        $this->transport()->send($this->email());

        // Assert: same Message-ID, same v5 key; no Message-ID, a fresh v4 key per send.
        $this->assertSame($keys[0], $keys[1]);
        $this->assertSame(5, Uuid::fromString($keys[0])->getFields()->getVersion());
        $this->assertNotSame($keys[2], $keys[3]);
        $this->assertSame(4, Uuid::fromString($keys[2])->getFields()->getVersion());
    }

    public function test_api_exception_becomes_a_transport_exception_without_the_response_text(): void
    {
        // Arrange
        $previous = new ApiException(
            '[400] rejected ' . self::SECRET_ADDRESS,
            400,
            null,
            '{"errors":{"invalid_email_tokens":["' . self::SECRET_ADDRESS . '"]}}',
        );
        $client = $this->createMock(DefaultApi::class);
        $client->method('createNotification')->willThrowException($previous);
        $this->app->instance(DefaultApi::class, $client);

        // Act
        try {
            $this->transport()->send($this->email());
            $this->fail('A TransportException was expected.');
        } catch (TransportException $exception) {
            // Assert
            $this->assertSame($previous, $exception->getPrevious());
            $this->assertStringContainsString('400', $exception->getMessage());
            $this->assertStringNotContainsString('@', $exception->getMessage());
        }
    }

    public function test_guzzle_exception_becomes_a_transport_exception(): void
    {
        // Arrange
        $previous = new ConnectException('cURL error 6 for ' . self::SECRET_ADDRESS, new Request('POST', '/'));
        $client = $this->createMock(DefaultApi::class);
        $client->method('createNotification')->willThrowException($previous);
        $this->app->instance(DefaultApi::class, $client);

        // Act
        try {
            $this->transport()->send($this->email());
            $this->fail('A TransportException was expected.');
        } catch (TransportException $exception) {
            // Assert
            $this->assertSame($previous, $exception->getPrevious());
            $this->assertStringNotContainsString('@', $exception->getMessage());
        }
    }

    /**
     * @return array<string, array{0: callable(): mixed}>
     */
    public static function unacceptedResponses(): array
    {
        return [
            'empty id' => [fn () => new CreateNotificationSuccessResponse([
                'id' => '',
                'errors' => ['invalid_email_tokens' => [self::SECRET_ADDRESS]],
            ])],
            'null id' => [fn () => new CreateNotificationSuccessResponse([
                'id' => null,
                'errors' => ['invalid_email_tokens' => [self::SECRET_ADDRESS]],
            ])],
            'generic error' => [fn () => new GenericError([
                'errors' => ['bad ' . self::SECRET_ADDRESS],
            ])],
            'rate limit error' => [fn () => new RateLimitError([
                'errors' => ['slow down ' . self::SECRET_ADDRESS],
                'limit' => '10',
            ])],
        ];
    }

    /**
     * @param  callable(): mixed  $response
     */
    #[DataProvider('unacceptedResponses')]
    public function test_a_response_that_sent_nothing_becomes_a_transport_exception(callable $response): void
    {
        // Arrange
        $client = $this->createMock(DefaultApi::class);
        $client->method('createNotification')->willReturn($response());
        $this->app->instance(DefaultApi::class, $client);

        // Act
        try {
            $this->transport()->send($this->email());
            $this->fail('A TransportException was expected.');
        } catch (TransportException $exception) {
            // Assert
            $this->assertStringNotContainsString('@', $exception->getMessage());
            $this->assertStringNotContainsString(self::SECRET_ADDRESS, $exception->getMessage());
        }
    }

    public function test_an_empty_id_names_the_error_keys_only(): void
    {
        // Arrange
        $client = $this->createMock(DefaultApi::class);
        $client->method('createNotification')->willReturn(new CreateNotificationSuccessResponse([
            'id' => '',
            'errors' => ['invalid_email_tokens' => [self::SECRET_ADDRESS]],
        ]));
        $this->app->instance(DefaultApi::class, $client);

        // Act
        try {
            $this->transport()->send($this->email());
            $this->fail('A TransportException was expected.');
        } catch (TransportException $exception) {
            // Assert
            $this->assertStringContainsString('invalid_email_tokens', $exception->getMessage());
        }
    }

    public function test_invalid_tokens_next_to_an_accepted_id_log_a_count_only(): void
    {
        // Arrange
        $client = $this->createMock(DefaultApi::class);
        $client->method('createNotification')->willReturn(new CreateNotificationSuccessResponse([
            'id' => 'notif-1',
            'errors' => ['invalid_email_tokens' => [self::SECRET_ADDRESS, 'other@example.org']],
        ]));
        $this->app->instance(DefaultApi::class, $client);

        $lines = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->willReturnCallback(function (string $message, array $context = []) use (&$lines): void {
                $lines[] = $message . ' ' . json_encode($context);
            });
        $this->app->instance(LoggerInterface::class, $logger);

        // Act
        $sent = $this->transport()->send($this->email());

        // Assert
        $this->assertNotNull($sent);
        $this->assertStringContainsString('2', $lines[0]);
        $this->assertStringNotContainsString('@', $lines[0]);
    }

    public function test_a_missing_rest_key_throws_without_breaking_the_failover_mailer(): void
    {
        // Arrange
        config([
            'magic-starter.onesignal.rest_api_key' => null,
            'services.onesignal.rest_api_key' => null,
            'mail.mailers.onesignal' => ['transport' => 'onesignal'],
            'mail.mailers.of' => ['transport' => 'failover', 'mailers' => ['onesignal', 'array']],
        ]);

        // Act + Assert: resolving the failover mailer builds the member eagerly and must not throw.
        $this->assertNotNull(Mail::mailer('of'));

        $this->expectException(TransportException::class);
        $this->transport()->send($this->email());
    }

    public function test_a_missing_rest_key_falls_through_to_the_next_failover_member(): void
    {
        // Arrange
        config([
            'magic-starter.onesignal.rest_api_key' => null,
            'services.onesignal.rest_api_key' => null,
            'mail.mailers.onesignal' => ['transport' => 'onesignal'],
            'mail.mailers.of' => ['transport' => 'failover', 'mailers' => ['onesignal', 'array']],
        ]);
        $sent = $this->countSentMessages();

        // Act
        Mail::mailer('of')->html('<p>Hello</p>', fn ($message) => $message->to('a@example.test')->subject('Hi'));

        // Assert
        $this->assertSame(1, $sent->count);
    }

    /**
     * @return array<string, array{0: callable(Email): Email}>
     */
    public static function unsupportedMessages(): array
    {
        return [
            'cc' => [fn (Email $email) => $email->cc('cc@example.test')],
            'bcc' => [fn (Email $email) => $email->bcc('bcc@example.test')],
            'attachment' => [fn (Email $email) => $email->attach('data', 'report.txt', 'text/plain')],
            'no html body' => [fn (Email $email) => (new Email)
                ->from('hello@example.test')
                ->to('a@example.test')
                ->subject('Welcome')
                ->text('Plain only')],
        ];
    }

    /**
     * @param  callable(Email): Email  $shape
     */
    #[DataProvider('unsupportedMessages')]
    public function test_a_message_onesignal_has_no_field_for_is_refused_before_the_client_is_called(callable $shape): void
    {
        // Arrange
        $client = $this->createMock(DefaultApi::class);
        $client->expects($this->never())->method('createNotification');
        $this->app->instance(DefaultApi::class, $client);

        // Act + Assert
        $this->expectException(TransportException::class);
        $this->transport()->send($shape($this->email()));
    }

    public function test_the_failover_mailer_sends_exactly_once_after_onesignal_throws(): void
    {
        // Arrange
        $client = $this->createMock(DefaultApi::class);
        $client->expects($this->once())
            ->method('createNotification')
            ->willThrowException(new ApiException('[500] upstream', 500));
        $this->app->instance(DefaultApi::class, $client);

        config([
            'mail.mailers.onesignal' => ['transport' => 'onesignal'],
            'mail.mailers.of' => ['transport' => 'failover', 'mailers' => ['onesignal', 'array']],
        ]);
        $sent = $this->countSentMessages();

        // Act
        Mail::mailer('of')->html('<p>Hello</p>', fn ($message) => $message->to('a@example.test')->subject('Hi'));

        // Assert
        $this->assertSame(1, $sent->count);
    }

    public function test_the_mailer_sends_through_onesignal_when_it_accepts(): void
    {
        // Arrange
        $client = $this->createMock(DefaultApi::class);
        $client->expects($this->once())
            ->method('createNotification')
            ->willReturn($this->accepted('notif-1'));
        $this->app->instance(DefaultApi::class, $client);

        config([
            'mail.mailers.onesignal' => ['transport' => 'onesignal'],
            'mail.mailers.of' => ['transport' => 'failover', 'mailers' => ['onesignal', 'array']],
        ]);
        $sent = $this->countSentMessages();

        // Act
        Mail::mailer('of')->html('<p>Hello</p>', fn ($message) => $message->to('a@example.test')->subject('Hi'));

        // Assert
        $this->assertSame(1, $sent->count);
    }

    public function test_the_request_on_the_wire_carries_the_email_fields(): void
    {
        // Arrange
        $history = [];
        $this->app->instance(DefaultApi::class, $this->realClient([
            new Response(200, ['Content-Type' => 'application/json'], '{"id":"notif-1","errors":[]}'),
        ], $history));

        // Act
        $this->transport()->send($this->email());

        // Assert
        $this->assertCount(1, $history);
        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertStringEndsWith('/notifications', $request->getUri()->getPath());
        $this->assertSame('Key key', $request->getHeaderLine('Authorization'));

        $body = json_decode((string) $request->getBody(), true);
        $this->assertTrue($body['include_unsubscribed']);
        $this->assertTrue($body['disable_email_click_tracking']);
        $this->assertSame(['a@example.test'], $body['email_to']);
        $this->assertSame('Welcome', $body['email_subject']);
        $this->assertSame('<p>Hello</p>', $body['email_body']);
        $this->assertSame('app-xyz', $body['app_id']);
        $this->assertArrayNotHasKey('included_segments', $body);
        $this->assertArrayNotHasKey('filters', $body);
    }

    public function test_a_wire_response_with_an_empty_id_raises_a_transport_exception(): void
    {
        // Arrange
        $history = [];
        $this->app->instance(DefaultApi::class, $this->realClient([
            new Response(
                200,
                ['Content-Type' => 'application/json'],
                '{"id":"","errors":["All included players are not subscribed"]}',
            ),
        ], $history));

        // Act
        try {
            $this->transport()->send($this->email());
            $this->fail('A TransportException was expected.');
        } catch (TransportException $exception) {
            // Assert
            $this->assertStringNotContainsString('All included players', $exception->getMessage());
        }
    }

    public function test_a_wire_http_failure_raises_a_transport_exception(): void
    {
        // Arrange
        $history = [];
        $this->app->instance(DefaultApi::class, $this->realClient([
            new Response(400, ['Content-Type' => 'application/json'], '{"errors":["' . self::SECRET_ADDRESS . '"]}'),
        ], $history));

        // Act
        try {
            $this->transport()->send($this->email());
            $this->fail('A TransportException was expected.');
        } catch (TransportException $exception) {
            // Assert
            $this->assertStringNotContainsString('@', $exception->getMessage());
            $this->assertInstanceOf(ApiException::class, $exception->getPrevious());
        }
    }

    public function test_it_names_itself_without_leaking_configuration(): void
    {
        $this->assertSame('onesignal://api', (string) $this->transport());
    }

    private function transport(): OneSignalTransport
    {
        return new OneSignalTransport($this->app);
    }

    private function email(): Email
    {
        return (new Email)
            ->from('hello@example.test')
            ->to('a@example.test')
            ->subject('Welcome')
            ->html('<p>Hello</p>')
            ->text('Hello');
    }

    private function accepted(string $id): CreateNotificationSuccessResponse
    {
        return new CreateNotificationSuccessResponse(['id' => $id, 'errors' => []]);
    }

    /**
     * Counts every message the mail layer reports as sent.
     */
    private function countSentMessages(): object
    {
        $counter = new class
        {
            public int $count = 0;
        };

        Event::listen(MessageSent::class, function () use ($counter): void {
            $counter->count++;
        });

        return $counter;
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
}
