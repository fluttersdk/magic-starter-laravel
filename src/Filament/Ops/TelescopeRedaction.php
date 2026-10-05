<?php

namespace FlutterSdk\MagicStarter\Filament\Ops;

use Illuminate\Support\Collection;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;

/**
 * What Telescope may keep from the starter's traffic.
 *
 * Telescope only masks `password` and `password_confirmation` by default, while
 * the starter's API receives ID tokens, OAuth codes, PKCE verifiers, recovery
 * codes and challenge tokens, and answers with Sanctum tokens. Every one of
 * those would otherwise sit in clear text in `telescope_entries`.
 *
 * Telescope keeps its rules in statics, so each call appends to the process.
 * Call once per boot.
 */
class TelescopeRedaction
{
    public const REQUEST_PARAMETERS = [
        'password',
        'password_confirmation',
        'current_password',
        'token',
        'id_token',
        'code',
        'authorization_code',
        'code_verifier',
        'recovery_code',
        'two_factor_token',
        'confirmation_token',
    ];

    public const RESPONSE_PARAMETERS = [
        'token',
    ];

    public const REQUEST_HEADERS = [
        'authorization',
        'cookie',
        'x-xsrf-token',
    ];

    /**
     * Mask the starter's secrets in every recorded request and response.
     */
    public static function hideSecrets(): void
    {
        Telescope::hideRequestParameters(self::REQUEST_PARAMETERS);
        Telescope::hideResponseParameters(self::RESPONSE_PARAMETERS);
        Telescope::hideRequestHeaders(self::REQUEST_HEADERS);
    }

    /**
     * Store a batch only when one of its entries is worth reading in production:
     * a reportable exception, a failed job, a scheduled task, a slow query or a
     * monitored tag. Any other batch is dropped whole.
     */
    public static function keepNoteworthyBatches(): void
    {
        Telescope::filterBatch(static fn (Collection $entries): bool => $entries->contains(
            static fn (IncomingEntry $entry): bool => $entry->isReportableException()
                || $entry->isFailedJob()
                || $entry->isScheduledTask()
                || $entry->isSlowQuery()
                || $entry->hasMonitoredTag(),
        ));
    }
}
