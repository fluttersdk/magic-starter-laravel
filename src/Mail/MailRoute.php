<?php

namespace FlutterSdk\MagicStarter\Mail;

use Closure;
use Illuminate\Contracts\Config\Repository;

/**
 * Decides, per mail class, which mailer carries a mail sent through the router.
 *
 * The decision is read from `magic-starter.mail.onesignal` on every call, so a
 * config change takes effect without a rebuilt container. Everything is off until
 * `enabled` is true and the mail class is listed (or `classes` holds `*`); a class
 * is matched by its basename, so `Illuminate\Auth\Notifications\ResetPassword`
 * is listed as `ResetPassword`.
 *
 * It is bound as a container singleton so the router and a transport further
 * down the chain share one instance: `sending()` holds the class of the mail in
 * flight for the length of a callback, and `currentClass()` lets that downstream
 * transport read it without a header that would otherwise leave the server.
 */
final class MailRoute
{
    /**
     * The header the stamp listener writes and the router strips before it delegates.
     */
    public const CLASS_HEADER = 'X-Magic-Starter-Mail-Class';

    /**
     * The class a message gets when nothing names its notification or mailable (a raw `html()` or `raw()` send).
     */
    public const UNKNOWN_CLASS = 'unknown';

    /**
     * The class of the mail being delivered, set only while `sending()` runs.
     */
    private ?string $currentClass = null;

    public function __construct(private Repository $config) {}

    /**
     * The routed mailer for a mail class, or null when the mail stays on the default mailer.
     */
    public function mailerFor(string $class): ?string
    {
        return $this->isRouted($class) ? $this->routedMailer() : null;
    }

    /**
     * The mailer a routed class leaves through, whether or not the switch is on.
     */
    public function routedMailer(): string
    {
        return (string) $this->config->get('magic-starter.mail.onesignal.mailer');
    }

    /**
     * Whether the switch is on and the class, by full name or basename, is listed.
     */
    public function isRouted(string $class): bool
    {
        if (! (bool) $this->config->get('magic-starter.mail.onesignal.enabled')) {
            return false;
        }

        $classes = (array) $this->config->get('magic-starter.mail.onesignal.classes');

        return in_array('*', $classes, true) || in_array(class_basename($class), $classes, true);
    }

    /**
     * Runs the callback with `$class` as the current class and clears it afterwards, even when the callback throws.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function sending(string $class, Closure $callback): mixed
    {
        $previous = $this->currentClass;
        $this->currentClass = $class;

        try {
            return $callback();
        } finally {
            $this->currentClass = $previous;
        }
    }

    /**
     * The class of the mail being delivered, or null outside a `sending()` call.
     */
    public function currentClass(): ?string
    {
        return $this->currentClass;
    }
}
