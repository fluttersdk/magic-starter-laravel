<?php

namespace FlutterSdk\MagicStarter\Support;

use FlutterSdk\MagicStarter\Contracts\AdministersBilling;
use FlutterSdk\MagicStarter\Enums\BillingProvider;
use RuntimeException;
use Throwable;

/**
 * An operator's billing action that may not run as asked.
 *
 * An outcome rather than a crash: {@see AdministersBilling} records the
 * `request_refused` row (source `admin`) BEFORE it throws this, outside any
 * transaction it opened, so the refusal is on record whatever the caller does
 * with its own transaction. The message is the translated
 * `magic-starter::admin_billing.refusals.<reason>` sentence the panel shows,
 * and `reason()` is the stable snake_case code the row carries.
 */
class BillingAdministrationRefused extends RuntimeException
{
    /**
     * @param  string  $refusal  a key of `lang/<locale>/admin_billing.php` `refusals`, such as `paid_rail_active`
     * @param  BillingProvider|null  $provider  the rail the refusal is about, when one is known; the
     *                                          `request_refused` row carries it, and a refusal thrown
     *                                          inside a transaction is recorded only after that
     *                                          transaction rolled back, when the row it read is gone
     * @param  Throwable|null  $previous  the rail failure behind a `rail_error`
     */
    public function __construct(
        private readonly string $refusal,
        private readonly ?BillingProvider $provider = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct((string) __('magic-starter::admin_billing.refusals.' . $refusal), 0, $previous);
    }

    /**
     * The stable snake_case reason the `request_refused` row records.
     */
    public function reason(): string
    {
        return $this->refusal;
    }

    /**
     * The rail the refusal is about, or null when none is known.
     */
    public function provider(): ?BillingProvider
    {
        return $this->provider;
    }
}
