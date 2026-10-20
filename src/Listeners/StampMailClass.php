<?php

namespace FlutterSdk\MagicStarter\Listeners;

use FlutterSdk\MagicStarter\Mail\MailRoute;
use Illuminate\Mail\Events\MessageSending;

/**
 * Tells the mail router which class a message came from.
 *
 * Laravel names the notification or the mailable only in the send data, never on
 * the message, and a transport sees the message alone. The stamp carries the name
 * across as a header that the router reads and removes before it delegates.
 *
 * Only a mailer whose transport is the router is stamped, so a mail on any other
 * mailer leaves with the headers it always had. The handler returns nothing:
 * `MessageSending` is dispatched with `until()`, where a `false` would cancel the send.
 */
class StampMailClass
{
    public function handle(MessageSending $event): void
    {
        // 1. Leave every mailer but the router alone.
        $mailer = $event->data['mailer'] ?? null;

        if (! is_string($mailer)
            || config("mail.mailers.{$mailer}.transport") !== 'magic-starter-router') {
            return;
        }

        // 2. Replace rather than add, so a spoofed or repeated stamp never leaves two values.
        $class = $event->data['__laravel_notification'] ?? $event->data['__laravel_mailable'] ?? 'unknown';
        $headers = $event->message->getHeaders();

        $headers->remove(MailRoute::CLASS_HEADER);
        $headers->addTextHeader(MailRoute::CLASS_HEADER, $class);
    }
}
