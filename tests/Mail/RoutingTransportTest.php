<?php

namespace FlutterSdk\MagicStarter\Tests\Mail;

use FlutterSdk\MagicStarter\Listeners\StampMailClass;
use FlutterSdk\MagicStarter\Mail\MailRoute;
use FlutterSdk\MagicStarter\Mail\RoutingTransport;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use onesignal\client\api\DefaultApi;
use onesignal\client\model\CreateNotificationSuccessResponse;
use Symfony\Component\Mailer\Envelope as MailerEnvelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Throwable;

final class RoutingTransportTest extends TestCase
{
    private const CLASS_HEADER = 'X-Magic-Starter-Mail-Class';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'magic-starter.onesignal.app_id' => 'app-xyz',
            'magic-starter.onesignal.rest_api_key' => 'rest-key',
            'mail.mailers.routed' => ['transport' => 'magic-starter-router', 'default' => 'default-sink'],
            'mail.mailers.default-sink' => ['transport' => 'array'],
            'mail.mailers.routed-sink' => ['transport' => 'array'],
            'mail.mailers.plain' => ['transport' => 'array'],
            'magic-starter.mail.onesignal.mailer' => 'routed-sink',
        ]);
    }

    public function test_switch_off_sends_through_the_default_mailer_and_never_calls_onesignal(): void
    {
        // Arrange: the routed mailer is the real failover over a mocked client, so a leak would show.
        $client = $this->createMock(DefaultApi::class);
        $client->expects($this->never())->method('createNotification');
        $this->app->instance(DefaultApi::class, $client);
        config([
            'mail.mailers.onesignal' => ['transport' => 'onesignal'],
            'mail.mailers.onesignal-failover' => ['transport' => 'failover', 'mailers' => ['onesignal', 'array']],
            'magic-starter.mail.onesignal.mailer' => 'onesignal-failover',
            'magic-starter.mail.onesignal.enabled' => false,
            'magic-starter.mail.onesignal.classes' => ['*'],
        ]);

        // Act
        $this->sendMailable();
        $this->sendNotification();

        // Assert
        $this->assertCount(2, $this->delivered('default-sink'));
        $this->assertCount(0, $this->delivered('routed-sink'));
    }

    public function test_switch_on_sends_a_listed_class_through_the_routed_mailer(): void
    {
        // Arrange
        $this->routeClasses(['RoutedTestMailable']);

        // Act
        $this->sendMailable();

        // Assert
        $this->assertCount(1, $this->delivered('routed-sink'));
        $this->assertCount(0, $this->delivered('default-sink'));
    }

    public function test_switch_on_reaches_onesignal_through_the_failover_mailer(): void
    {
        // Arrange
        $client = $this->createMock(DefaultApi::class);
        $client->expects($this->once())
            ->method('createNotification')
            ->willReturn(new CreateNotificationSuccessResponse(['id' => 'notif-1', 'errors' => []]));
        $this->app->instance(DefaultApi::class, $client);
        config([
            'mail.mailers.onesignal' => ['transport' => 'onesignal'],
            'mail.mailers.onesignal-failover' => ['transport' => 'failover', 'mailers' => ['onesignal', 'array']],
            'magic-starter.mail.onesignal.mailer' => 'onesignal-failover',
        ]);
        $this->routeClasses(['RoutedTestMailable']);

        // Act
        $this->sendMailable();

        // Assert: the mocked client took the mail, so the failover array member and the default sink saw none.
        $this->assertCount(0, $this->delivered('default-sink'));
    }

    public function test_a_star_routes_a_mailable_and_a_notification(): void
    {
        // Arrange
        $this->routeClasses(['*']);

        // Act
        $this->sendMailable();
        $this->sendNotification();

        // Assert
        $this->assertCount(2, $this->delivered('routed-sink'));
        $this->assertCount(0, $this->delivered('default-sink'));
    }

    public function test_a_notification_class_is_routed_by_its_basename(): void
    {
        // Arrange
        $this->routeClasses(['RoutedTestNotification']);

        // Act
        $this->sendMailable();
        $this->sendNotification();

        // Assert
        $this->assertCount(1, $this->delivered('routed-sink'));
        $this->assertCount(1, $this->delivered('default-sink'));
    }

    public function test_an_unlisted_class_goes_to_the_default_mailer(): void
    {
        // Arrange
        $this->routeClasses(['SomeOtherMailable']);

        // Act
        $this->sendMailable();

        // Assert
        $this->assertCount(1, $this->delivered('default-sink'));
        $this->assertCount(0, $this->delivered('routed-sink'));
    }

    public function test_a_raw_mail_has_the_class_unknown_and_is_routed_by_a_star_only(): void
    {
        // Arrange
        $this->routeClasses(['RoutedTestMailable']);

        // Act
        Mail::mailer('routed')->html('<p>Hi</p>', fn ($message) => $message->to('a@example.test')->subject('Hi'));
        config(['magic-starter.mail.onesignal.classes' => ['*']]);
        Mail::mailer('routed')->html('<p>Hi</p>', fn ($message) => $message->to('a@example.test')->subject('Hi'));

        // Assert
        $this->assertCount(1, $this->delivered('default-sink'));
        $this->assertCount(1, $this->delivered('routed-sink'));
    }

    public function test_the_delivered_message_carries_no_class_header(): void
    {
        // Arrange
        $this->routeClasses(['RoutedTestMailable']);

        // Act
        $this->sendMailable();
        $this->sendNotification();

        // Assert: one message per sink, none of them stamped.
        $messages = [...$this->delivered('routed-sink'), ...$this->delivered('default-sink')];
        $this->assertCount(2, $messages);

        foreach ($messages as $sent) {
            $this->assertFalse($sent->getOriginalMessage()->getHeaders()->has(self::CLASS_HEADER));
        }
    }

    public function test_a_mail_on_a_plain_mailer_is_never_stamped(): void
    {
        // Arrange
        $stamped = null;
        Event::listen(MessageSending::class, function (MessageSending $event) use (&$stamped): void {
            $stamped = $event->message->getHeaders()->has(self::CLASS_HEADER);
        });
        $this->routeClasses(['*']);

        // Act
        Mail::mailer('plain')->to('a@example.test')->send(new RoutedTestMailable);
        $this->sendNotification('plain');

        // Assert
        $this->assertFalse($stamped);

        foreach ($this->delivered('plain') as $sent) {
            $this->assertFalse($sent->getOriginalMessage()->getHeaders()->has(self::CLASS_HEADER));
        }

        $this->assertCount(2, $this->delivered('plain'));
    }

    public function test_the_stamp_reads_the_mailer_for_a_mailable_and_a_notification_send(): void
    {
        // Arrange
        $seen = [];
        Event::listen(MessageSending::class, function (MessageSending $event) use (&$seen): void {
            $seen[] = [
                $event->data['mailer'] ?? null,
                $event->message->getHeaders()->get(self::CLASS_HEADER)?->getBodyAsString(),
            ];
        });

        // Act
        $this->sendMailable();
        $this->sendNotification();

        // Assert
        $this->assertSame([
            ['routed', RoutedTestMailable::class],
            ['routed', RoutedTestNotification::class],
        ], $seen);
    }

    public function test_the_stamp_replaces_an_existing_header_instead_of_adding_a_second(): void
    {
        // Arrange
        $email = (new Email)->from('a@example.test')->to('b@example.test')->text('x');
        $email->getHeaders()->addTextHeader(self::CLASS_HEADER, 'Spoofed');

        // Act
        (new StampMailClass)->handle(new MessageSending($email, [
            'mailer' => 'routed',
            '__laravel_mailable' => RoutedTestMailable::class,
        ]));

        // Assert
        $this->assertCount(1, iterator_to_array($email->getHeaders()->all(self::CLASS_HEADER), false));
        $this->assertSame(RoutedTestMailable::class, $email->getHeaders()->get(self::CLASS_HEADER)->getBodyAsString());
    }

    public function test_a_downstream_transport_sees_the_class_without_a_header(): void
    {
        // Arrange
        $observed = [];
        Mail::extend('probe', function () use (&$observed) {
            return new class($observed) extends AbstractTransport
            {
                /** @param array<int, array{0: ?string, 1: bool}> $observed */
                public function __construct(private array &$observed)
                {
                    parent::__construct();
                }

                public function __toString(): string
                {
                    return 'probe://sink';
                }

                protected function doSend(SentMessage $message): void
                {
                    $this->observed[] = [
                        app(MailRoute::class)->currentClass(),
                        $message->getOriginalMessage()->getHeaders()->has('X-Magic-Starter-Mail-Class'),
                    ];
                }
            };
        });
        config(['mail.mailers.routed-sink' => ['transport' => 'probe']]);
        $this->routeClasses(['*']);

        // Act
        $this->sendMailable();

        // Assert: the class travels in the singleton for the call only.
        $this->assertSame([[RoutedTestMailable::class, false]], $observed);
        $this->assertNull(app(MailRoute::class)->currentClass());
    }

    public function test_the_current_class_is_cleared_when_the_delegate_throws(): void
    {
        // Arrange
        Mail::extend('probe', fn () => new class extends AbstractTransport
        {
            public function __toString(): string
            {
                return 'probe://failing';
            }

            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('Downstream refused.');
            }
        });
        config(['mail.mailers.routed-sink' => ['transport' => 'probe']]);
        $this->routeClasses(['*']);

        // Act
        try {
            $this->sendMailable();
            $this->fail('A TransportException was expected.');
        } catch (TransportException $exception) {
            // Assert
            $this->assertSame('Downstream refused.', $exception->getMessage());
            $this->assertNull(app(MailRoute::class)->currentClass());
        }
    }

    public function test_the_routers_sent_message_carries_the_delegates_id_and_debug(): void
    {
        // Arrange
        Mail::extend('probe', fn () => new class extends AbstractTransport
        {
            public function __toString(): string
            {
                return 'probe://sink';
            }

            protected function doSend(SentMessage $message): void
            {
                $message->setMessageId('delegate-id');
                $message->appendDebug('delegate-debug');
            }
        });
        config(['mail.mailers.routed-sink' => ['transport' => 'probe']]);
        $this->routeClasses(['*']);
        $sent = $this->captureSentMessage();

        // Act
        $this->sendMailable();

        // Assert: Laravel's SentMessage reflects the delivery that really happened.
        $this->assertSame('delegate-id', $sent->message?->getMessageId());
        $this->assertStringContainsString('delegate-debug', (string) $sent->message?->getDebug());
    }

    public function test_a_delegate_that_answers_no_sent_message_leaves_the_routers_own_untouched(): void
    {
        // Arrange
        Mail::extend('probe', fn () => new class extends AbstractTransport
        {
            public function __toString(): string
            {
                return 'probe://rejecting';
            }

            public function send(RawMessage $message, ?MailerEnvelope $envelope = null): ?SentMessage
            {
                return null;
            }

            protected function doSend(SentMessage $message): void {}
        });
        config(['mail.mailers.routed-sink' => ['transport' => 'probe']]);
        $this->routeClasses(['*']);
        $sent = $this->captureSentMessage();

        // Act
        $this->sendMailable();

        // Assert
        $this->assertNotNull($sent->message);
        $this->assertNotSame('', $sent->message->getMessageId());
    }

    public function test_a_route_whose_mailer_is_itself_a_router_throws(): void
    {
        // Arrange
        config(['mail.mailers.routed-sink' => ['transport' => 'magic-starter-router', 'default' => 'default-sink']]);
        $this->routeClasses(['*']);

        // Act + Assert
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('router');
        $this->sendMailable();
    }

    public function test_a_default_mailer_that_is_itself_a_router_throws(): void
    {
        // Arrange
        config(['mail.mailers.default-sink' => ['transport' => 'magic-starter-router', 'default' => 'routed']]);

        // Act + Assert
        $this->expectException(TransportException::class);
        $this->sendMailable();
    }

    public function test_an_undefined_routed_mailer_falls_back_to_the_default_and_reports_its_name_only(): void
    {
        // Arrange
        config(['magic-starter.mail.onesignal.mailer' => 'missing-mailer']);
        $this->routeClasses(['*']);
        $reported = [];
        $handler = $this->createMock(ExceptionHandler::class);
        $handler->method('report')->willReturnCallback(function (Throwable $exception) use (&$reported): void {
            $reported[] = $exception;
        });
        $this->app->instance(ExceptionHandler::class, $handler);

        // Act
        $this->sendMailable();

        // Assert: the mail still left through the default mailer, and the misconfiguration surfaced.
        $this->assertCount(1, $this->delivered('default-sink'));
        $this->assertCount(1, $reported);
        $this->assertInstanceOf(TransportException::class, $reported[0]);
        $this->assertStringContainsString('missing-mailer', $reported[0]->getMessage());
        $this->assertStringNotContainsString('example.test', $reported[0]->getMessage());
        $this->assertStringNotContainsString('Routed mailable', $reported[0]->getMessage());
    }

    public function test_an_undefined_default_mailer_throws_a_transport_exception(): void
    {
        // Arrange: nothing routed, so the mail is bound for the default mailer, which does not exist.
        config(['mail.mailers.routed' => ['transport' => 'magic-starter-router', 'default' => 'missing-default']]);

        // Act + Assert
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('missing-default');
        $this->sendMailable();
    }

    public function test_an_undefined_routed_and_default_mailer_throws_a_transport_exception(): void
    {
        // Arrange
        config([
            'mail.mailers.routed' => ['transport' => 'magic-starter-router', 'default' => 'missing-default'],
            'magic-starter.mail.onesignal.mailer' => 'missing-mailer',
        ]);
        $this->routeClasses(['*']);

        // Act + Assert
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('missing-default');
        $this->sendMailable();
    }

    public function test_a_defined_routed_mailer_is_not_reported(): void
    {
        // Arrange
        $this->routeClasses(['*']);
        $handler = $this->createMock(ExceptionHandler::class);
        $handler->expects($this->never())->method('report');
        $this->app->instance(ExceptionHandler::class, $handler);

        // Act
        $this->sendMailable();

        // Assert
        $this->assertCount(1, $this->delivered('routed-sink'));
    }

    public function test_a_raw_message_cannot_be_routed(): void
    {
        // Arrange
        $transport = $this->app->make('mail.manager')->mailer('routed')->getSymfonyTransport();

        // Act + Assert
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('raw message');
        $transport->send(new RawMessage('Subject: Raw' . "\r\n\r\nBody"), new MailerEnvelope(
            new Address('from@example.test'),
            [new Address('to@example.test')],
        ));
    }

    public function test_the_router_names_itself_by_its_default_mailer(): void
    {
        $this->assertSame(
            'magic-starter-router://default-sink',
            (string) new RoutingTransport(app('mail.manager'), 'default-sink', app(MailRoute::class)),
        );
    }

    public function test_the_route_is_one_shared_instance(): void
    {
        $this->assertSame(app(MailRoute::class), app(MailRoute::class));
    }

    public function test_the_route_ships_switched_off_with_an_empty_class_list(): void
    {
        // Arrange
        $this->refreshApplication();

        // Assert
        $this->assertFalse(config('magic-starter.mail.onesignal.enabled'));
        $this->assertSame([], config('magic-starter.mail.onesignal.classes'));
        $this->assertSame('onesignal-failover', config('magic-starter.mail.onesignal.mailer'));
        $this->assertFalse(app(MailRoute::class)->isRouted('AnyMailable'));
    }

    public function test_the_route_matches_a_basename_or_a_full_class_name(): void
    {
        // Arrange
        $this->routeClasses(['RoutedTestMailable']);
        $route = app(MailRoute::class);

        // Assert
        $this->assertTrue($route->isRouted(RoutedTestMailable::class));
        $this->assertTrue($route->isRouted('RoutedTestMailable'));
        $this->assertFalse($route->isRouted(RoutedTestNotification::class));
        $this->assertSame('routed-sink', $route->routedMailer());
    }

    public function test_the_route_answers_nothing_while_the_switch_is_off(): void
    {
        // Arrange
        config([
            'magic-starter.mail.onesignal.enabled' => false,
            'magic-starter.mail.onesignal.classes' => ['*'],
        ]);

        // Assert
        $this->assertFalse(app(MailRoute::class)->isRouted(RoutedTestMailable::class));
    }

    /**
     * @param  array<int, string>  $classes
     */
    private function routeClasses(array $classes): void
    {
        config([
            'magic-starter.mail.onesignal.enabled' => true,
            'magic-starter.mail.onesignal.classes' => $classes,
        ]);
    }

    private function sendMailable(): void
    {
        Mail::mailer('routed')->to('a@example.test')->send(new RoutedTestMailable);
    }

    private function sendNotification(string $mailer = 'routed'): void
    {
        NotificationFacade::route('mail', 'a@example.test')->notify(new RoutedTestNotification($mailer));
    }

    /**
     * Holds the SentMessage Laravel reports for the last mail.
     */
    private function captureSentMessage(): object
    {
        $holder = new class
        {
            public ?SentMessage $message = null;
        };

        Event::listen(MessageSent::class, function (MessageSent $event) use ($holder): void {
            $holder->message = $event->sent->getSymfonySentMessage();
        });

        return $holder;
    }

    /**
     * @return array<int, SentMessage>
     */
    private function delivered(string $mailer): array
    {
        return Mail::mailer($mailer)->getSymfonyTransport()->messages()->all();
    }
}

final class RoutedTestMailable extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Routed mailable');
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Hello</p>');
    }
}

final class RoutedTestNotification extends Notification
{
    public function __construct(private string $mailer = 'routed') {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->mailer($this->mailer)->subject('Routed notification')->line('Hello');
    }
}
