<?php

namespace FlutterSdk\MagicStarter\Mail;

use Illuminate\Mail\MailManager;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Message;

/**
 * Hands each mail to the mailer its class is routed to, or to the default mailer.
 *
 * The class arrives as the `X-Magic-Starter-Mail-Class` header written by
 * `StampMailClass`. The router reads it, removes it from the message so it never
 * leaves the server, and delegates to the chosen mailer's own transport, so a
 * failover mailer keeps its own fallback. While the delegate runs, `MailRoute`
 * exposes the class to any transport further down the chain.
 *
 * Contract: every refusal is a `TransportException`. A mailer whose own transport
 * is this router is refused, since routing a mail to a router would never end.
 */
class RoutingTransport extends AbstractTransport
{
    public function __construct(
        private MailManager $manager,
        private string $default,
        private MailRoute $route,
    ) {
        parent::__construct();
    }

    public function __toString(): string
    {
        return "magic-starter-router://{$this->default}";
    }

    /**
     * @throws TransportException When the message is raw or the chosen mailer is a router.
     */
    protected function doSend(SentMessage $message): void
    {
        // 1. Only a built message carries headers to read.
        $original = $message->getOriginalMessage();

        if (! $original instanceof Message) {
            throw new TransportException('A raw message cannot be routed.');
        }

        // 2. Read the class, then strip it: the header is an internal hand-off, not part of the mail.
        $headers = $original->getHeaders();
        $class = $headers->get(MailRoute::CLASS_HEADER)?->getBodyAsString() ?? 'unknown';
        $headers->remove(MailRoute::CLASS_HEADER);

        // 3. A routed class goes to its mailer, everything else to the default.
        $name = $this->route->mailerFor($class) ?? $this->default;

        if (config("mail.mailers.{$name}.transport") === 'magic-starter-router') {
            throw new TransportException("The mailer [{$name}] is a router and cannot be a route target.");
        }

        // 4. Deliver through that mailer's transport, with the class known to it for the call.
        $this->route->sending(
            $class,
            fn () => $this->manager->mailer($name)->getSymfonyTransport()->send($original, $message->getEnvelope()),
        );
    }
}
