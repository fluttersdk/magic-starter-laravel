<?php

namespace FlutterSdk\MagicStarter\Support;

use Illuminate\Support\Facades\Log;

/**
 * The one way a billing line reaches the log.
 *
 * With `magic-starter.billing.log_channel` set, every line goes to
 * `Log::channel(<that name>)`, so billing can be routed to its own file or
 * alert sink. With it null or blank (the default; `MAGIC_STARTER_BILLING_LOG_CHANNEL=`
 * reads as '', and `Log::channel('')` would fall to the emergency logger) the
 * line goes through the plain `Log::info()`, `Log::warning()` or `Log::error()`
 * call, never through `Log::channel()`: an unconfigured application logs
 * exactly as it did before this class existed, and a `Log::spy()` assertion on
 * those methods keeps matching, since a spy answers `channel()` with null.
 */
class BillingLog
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    /**
     * @param  'info'|'warning'|'error'  $level
     * @param  array<string, mixed>  $context
     */
    private static function write(string $level, string $message, array $context): void
    {
        $channel = config('magic-starter.billing.log_channel');

        if (! is_string($channel) || $channel === '') {
            Log::{$level}($message, $context);

            return;
        }

        Log::channel($channel)->{$level}($message, $context);
    }
}
