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
 * is this router is refused, since routing a mail to a router would never end, and
 * so is a default mailer that is not defined, since nothing is left to fall back to.
 *
 * A routed mailer that is not defined is a misconfiguration, not a reason to lose
 * the mail: the mail leaves through the default mailer and a `TransportException`
 * naming only the missing mailer is reported, so the error tracker shows it. The
 * name is config, never an address, subject or body.
 */
class RoutingTransport extends AbstractTransport
{
    /**
     * The `mail.mailers.*.transport` value that selects this router.
     */
    public const TRANSPORT = 'magic-starter-router';

    public function __construct(
        private MailManager $manager,
        private string $default,
        private MailRoute $route,
    ) {
        parent::__construct();
    }

    public function __toString(): string
    {
        return self::TRANSPORT . "://{$this->default}";
    }

    /**
     * @throws TransportException When the message is raw, or the chosen mailer is a router or not defined.
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
        $class = $headers->get(MailRoute::CLASS_HEADER)?->getBodyAsString() ?? MailRoute::UNKNOWN_CLASS;
        $headers->remove(MailRoute::CLASS_HEADER);

        // 3. A routed class goes to its mailer, everything else to the default. A routed mailer that does not
        //    exist would make the manager throw a non-transport exception and fail every routed mail, so the
        //    mail takes the default and the misconfiguration is reported instead.
        $routed = $this->route->mailerFor($class);
        $name = $routed ?? $this->default;

        if ($routed !== null && ! $this->isDefined($routed)) {
            report(new TransportException("The routed mailer [{$routed}] is not defined: using [{$this->default}]."));

            $name = $this->default;
        }

        if (! $this->isDefined($name)) {
            throw new TransportException("The mailer [{$name}] is not defined.");
        }

        if (config("mail.mailers.{$name}.transport") === self::TRANSPORT) {
            throw new TransportException("The mailer [{$name}] is a router and cannot be a route target.");
        }

        // 4. Deliver through that mailer's transport, with the class known to it for the call.
        $sent = $this->route->sending(
            $class,
            fn () => $this->manager->mailer($name)->getSymfonyTransport()->send($original, $message->getEnvelope()),
        );

        // 5. Laravel reads the id and the debug log off this message, so they must describe the real delivery.
        if ($sent !== null) {
            $message->setMessageId($sent->getMessageId());
            $message->appendDebug($sent->getDebug());
        }
    }

    private function isDefined(string $name): bool
    {
        return config("mail.mailers.{$name}") !== null;
    }
}
