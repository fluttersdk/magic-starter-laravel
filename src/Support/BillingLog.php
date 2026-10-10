<?php

namespace FlutterSdk\MagicStarter\Support;

use Illuminate\Support\Facades\Log;

/**
 * The one way a billing line reaches the log.
 *
 * With `magic-starter.billing.log_channel` set, every line goes to
 * `Log::channel(<that name>)`, so billing can be routed to its own file or
 * alert sink. With it null (the default) the line goes through the plain
 * `Log::info()`, `Log::warning()` or `Log::error()` call, never through
 * `Log::channel()`: an unconfigured application logs exactly as it did before
 * this class existed, and a `Log::spy()` assertion on those methods keeps
 * matching, since a spy answers `channel()` with null.
 */
class BillingLog
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function info(string $message, array $context = []): void
    {
        $channel = self::channel();

        if ($channel === null) {
            Log::info($message, $context);

            return;
        }

        Log::channel($channel)->info($message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function warning(string $message, array $context = []): void
    {
        $channel = self::channel();

        if ($channel === null) {
            Log::warning($message, $context);

            return;
        }

        Log::channel($channel)->warning($message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function error(string $message, array $context = []): void
    {
        $channel = self::channel();

        if ($channel === null) {
            Log::error($message, $context);

            return;
        }

        Log::channel($channel)->error($message, $context);
    }

    private static function channel(): ?string
    {
        $channel = config('magic-starter.billing.log_channel');

        return is_string($channel) ? $channel : null;
    }
}
