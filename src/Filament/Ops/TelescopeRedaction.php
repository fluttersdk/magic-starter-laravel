<?php

namespace FlutterSdk\MagicStarter\Filament\Ops;

use FlutterSdk\MagicStarter\Http\Controllers\TeamInvitationController;
use FlutterSdk\MagicStarter\Http\Controllers\TwoFactorRecoveryCodeController;
use Illuminate\Support\Collection;
use Laravel\Telescope\EntryType;
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
 * Telescope masks by DOT PATH (`Arr::get($content, $parameter)`), so a response
 * mask names the path the secret actually sits at: the API wraps its payloads
 * under `data`. A secret answered as a list element has no path to name, so the
 * request entry for those endpoints is dropped instead of stored.
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
        'nonce',
        'ticket',
        'code',
        'authorization_code',
        'code_verifier',
        'recovery_code',
        'two_factor_token',
        'confirmation_token',
    ];

    public const RESPONSE_PARAMETERS = [
        'data.token',
        'two_factor_token',
        'data.confirmation_token',
        'data.ticket',
        'data.secret',
        'data.qr_url',
        'data.qr_svg',
        'data.recovery_codes',
    ];

    /**
     * Endpoints that answer secrets as list elements: the recovery codes as a
     * bare list under `data`, and the invitation index as a page of
     * invitations each carrying its acceptance `token`.
     */
    public const UNRECORDED_ACTIONS = [
        TwoFactorRecoveryCodeController::class . '@index',
        TwoFactorRecoveryCodeController::class . '@store',
        TeamInvitationController::class . '@index',
    ];

    /**
     * Telescope masks response headers with this same list, which is why the
     * response-only `set-cookie` (the session) is named here.
     */
    public const REQUEST_HEADERS = [
        'authorization',
        'cookie',
        'x-xsrf-token',
        'set-cookie',
        'x-csrf-token',
    ];

    /**
     * Mask the starter's secrets in every recorded request and response, and
     * keep no request entry for an endpoint whose secrets cannot be masked.
     *
     * The filter runs as each entry is recorded, before any batch filter, so a
     * dropped entry never counts toward keeping its batch.
     */
    public static function hideSecrets(): void
    {
        Telescope::hideRequestParameters(self::REQUEST_PARAMETERS);
        Telescope::hideResponseParameters(self::RESPONSE_PARAMETERS);
        Telescope::hideRequestHeaders(self::REQUEST_HEADERS);
        Telescope::filter(static fn (IncomingEntry $entry): bool => $entry->type !== EntryType::REQUEST
            || ! in_array($entry->content['controller_action'] ?? null, self::UNRECORDED_ACTIONS, true));
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
