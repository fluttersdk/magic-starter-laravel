<?php

namespace FlutterSdk\MagicStarter\Mail;

use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\MailSend;
use FlutterSdk\MagicStarter\Support\OneSignalSubscriptions;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use onesignal\client\api\DefaultApi;
use onesignal\client\ApiException;
use onesignal\client\model\CreateNotificationSuccessResponse;
use onesignal\client\model\Notification as OneSignalNotification;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;
use Throwable;

/**
 * Sends a Laravel-rendered mail through OneSignal's email channel.
 *
 * Laravel still renders the message; this transport only hands the finished
 * subject and HTML to OneSignal, addressed to explicit email addresses. It never
 * targets a segment or a filter, so a send cannot reach anybody the message was
 * not addressed to.
 *
 * Contract: every failure throws a `TransportException`, so a failover mailer
 * moves on to its next member. That covers an unprovisioned OneSignal, a message
 * OneSignal has no field for (cc, bcc, attachments, no HTML body), a failed
 * request, and an answer that sent nothing. No recipient, subject or body is
 * ever logged or placed in an exception message: Symfony's failover logs every
 * member failure at ERROR together with the exception, and OneSignal error
 * values can hold addresses, so a message names error keys and counts only.
 *
 * The transport is built with the container, not the client. Laravel builds
 * every member of a failover mailer eagerly, and the `DefaultApi` singleton
 * throws when the REST key is missing, so resolving it here would break the
 * whole failover mailer for an app that has not provisioned OneSignal.
 */
class OneSignalTransport extends AbstractTransport
{
    public function __construct(private Container $container)
    {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'onesignal://api';
    }

    /**
     * @throws TransportException On every failure, so the next failover member takes the message.
     */
    protected function doSend(SentMessage $message): void
    {
        // 1. Refuse before touching the client: resolving it without a REST key throws a RuntimeException
        //    that a failover mailer would not catch.
        if (! OneSignalSubscriptions::provisioned()) {
            throw new TransportException('OneSignal is not provisioned: the app id or the REST API key is missing.');
        }

        // 2. Refuse what OneSignal has no field for, so a failover member delivers it whole.
        $email = $this->toEmail($message);
        $this->assertDeliverable($email);

        // 3. Send. The SDK and Guzzle failures are wrapped with `previous` kept and the text dropped.
        $notification = $this->buildNotification($email, $message);

        try {
            $response = $this->container->make(DefaultApi::class)->createNotification($notification);
        } catch (ApiException|GuzzleException $exception) {
            throw new TransportException(
                sprintf('OneSignal email request failed with status %d.', $exception->getCode()),
                0,
                $exception,
            );
        }

        // 4. A union answer (GenericError, RateLimitError) or an empty id means nothing was sent.
        if (! $response instanceof CreateNotificationSuccessResponse
            || $response->getId() === null
            || $response->getId() === '') {
            throw new TransportException(sprintf(
                'OneSignal did not send the email (%s): %s.',
                get_debug_type($response),
                $this->describeErrors(is_object($response) && method_exists($response, 'getErrors')
                    ? $response->getErrors()
                    : null),
            ));
        }

        // 5. An accepted id can still carry addresses OneSignal skipped; surface the count only.
        $notificationId = $response->getId();
        $invalidTokens = $this->errorMap($response->getErrors())['invalid_email_tokens'] ?? null;
        $invalidCount = is_array($invalidTokens) ? count($invalidTokens) : 0;

        if ($invalidCount > 0) {
            $this->container->make(LoggerInterface::class)->warning(
                'OneSignal skipped invalid email addresses.',
                ['count' => $invalidCount],
            );
        }

        // 6. Keep the proof of delivery last: the mail is already accepted.
        $this->recordSend($notificationId, $email, $invalidCount);
    }

    /**
     * Writes the delivery record of a mail OneSignal accepted.
     *
     * A failure here is reported and swallowed on purpose: the mail has left, and a
     * throw would hand it to the next failover member, which would send it twice.
     * Any throwable, not only a query error, because a bookkeeping fault of any kind
     * must stay off the mail's path. The record holds a hash of the first address,
     * never the address, subject or body.
     */
    private function recordSend(string $notificationId, Email $email, int $invalidCount): void
    {
        try {
            $to = $email->getTo();

            MailSend::query()->create([
                'onesignal_id' => $notificationId,
                'mail_class' => $this->container->make(MailRoute::class)->currentClass(),
                'recipient_hash' => hash('sha256', Str::lower(($to[0] ?? null)?->getAddress() ?? '')),
                'recipients' => count($to),
                'invalid_recipients' => $invalidCount,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @throws TransportException When the message cannot be read as an email.
     */
    private function toEmail(SentMessage $message): Email
    {
        $original = $message->getOriginalMessage();

        if (! $original instanceof Message) {
            throw new TransportException('A raw message cannot be converted to an email.');
        }

        try {
            return MessageConverter::toEmail($original);
        } catch (RuntimeException $exception) {
            throw new TransportException('The message cannot be converted to an email.', 0, $exception);
        }
    }

    /**
     * @throws TransportException When OneSignal has no field for part of the message.
     */
    private function assertDeliverable(Email $email): void
    {
        if ($email->getCc() !== [] || $email->getBcc() !== []) {
            throw new TransportException('OneSignal email has no cc or bcc field.');
        }

        if ($email->getAttachments() !== []) {
            throw new TransportException('OneSignal email has no attachment field.');
        }

        $html = $email->getHtmlBody();

        if (! is_string($html) || $html === '') {
            throw new TransportException('OneSignal email needs an HTML body.');
        }
    }

    /**
     * @return OneSignalNotification<int|null, mixed>
     */
    private function buildNotification(Email $email, SentMessage $message): OneSignalNotification
    {
        $notification = (new OneSignalNotification)
            ->setAppId((string) MagicStarter::onesignalAppId())
            ->setEmailTo(array_map(fn ($address) => $address->getAddress(), $email->getTo()))
            ->setEmailSubject((string) $email->getSubject())
            ->setEmailBody($email->getHtmlBody())
            // Transactional and tenant audience mail: our own database is the opt-out of record.
            ->setIncludeUnsubscribed(true)
            // A rewritten link would break reset tokens and one-time URLs.
            ->setDisableEmailClickTracking(true)
            ->setIdempotencyKey($this->idempotencyKey($message));

        $from = $email->getFrom()[0] ?? null;

        if ($from !== null) {
            $notification->setEmailFromAddress($from->getAddress());

            if ($from->getName() !== '') {
                $notification->setEmailFromName($from->getName());
            }
        }

        $replyTo = $email->getReplyTo()[0] ?? null;

        if ($replyTo !== null) {
            $notification->setEmailReplyToAddress($replyTo->getAddress());
        }

        return $notification;
    }

    /**
     * Ties the OneSignal request to the Message-ID of this send attempt.
     *
     * Symfony adds the Message-ID to the clone its `SentMessage` keeps, never to the message
     * Laravel built, so the id is read from the `SentMessage`. A replay of the same
     * `SentMessage` is deduplicated by OneSignal; a queue retry builds a new message and
     * is a new send with a new key.
     */
    private function idempotencyKey(SentMessage $message): string
    {
        $messageId = $message->getMessageId();

        if ($messageId === '') {
            return Uuid::uuid4()->toString();
        }

        return Uuid::uuid5(Uuid::NAMESPACE_URL, $messageId)->toString();
    }

    /**
     * The error keys or the error count of an answer, never a value: values can hold addresses.
     */
    private function describeErrors(mixed $errors): string
    {
        $errors = $this->errorMap($errors);

        if ($errors === []) {
            return 'no error detail';
        }

        $keys = array_filter(array_keys($errors), 'is_string');

        return $keys !== []
            ? 'error keys ' . implode(', ', $keys)
            : count($errors) . ' error(s)';
    }

    /**
     * @return array<int|string, mixed>
     */
    private function errorMap(mixed $errors): array
    {
        if (is_object($errors)) {
            $errors = get_object_vars($errors);
        }

        return is_array($errors) ? $errors : [];
    }
}
