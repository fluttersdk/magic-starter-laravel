<?php

namespace FlutterSdk\MagicStarter\Enums;

/**
 * Which rail granted the entitlement a subscriber currently holds.
 *
 * The vocabulary is deliberately neutral. Several payment rails carry the same
 * facts in their own dialects, and a wire that leaks one rail's words forces
 * every reader downstream to learn that dialect and then to relearn it when a
 * second rail arrives. A rail's own vocabulary therefore maps INTO this enum,
 * and the rail's raw word survives verbatim in the separate, debug-only
 * `plan_provider_status` column rather than in this one.
 *
 * This enum is the answer to "who is billing this subscriber", and nothing
 * more. It says nothing about WHEN a period ends or whether it renews; those
 * are their own fields, because a rail is not a lifecycle.
 *
 * The package ships the vocabulary and no rail. Which rails a consuming
 * application actually operates, and how each one's words map into these
 * cases, is that application's business.
 */
enum BillingProvider: string
{
    /**
     * Nobody is billing. The value of a subscriber no rail has ever charged,
     * and also the safe landing place for a value this build does not know
     * (see {@see self::fromWire()}).
     */
    case NONE = 'none';

    /** The card rail, billed by the application directly. */
    case STRIPE = 'stripe';

    /**
     * The two mobile stores, which bill on the application's behalf and keep
     * management of the purchase inside their own account surface.
     */
    case APP_STORE = 'app_store';

    case PLAY_STORE = 'play_store';

    /**
     * Granted by an operator rather than sold: a comp, a migration, a support
     * gesture. It entitles exactly like a paid rail and has no receipt.
     */
    case MANUAL = 'manual';

    /**
     * Resolve a provider from a raw wire or column value, defaulting to
     * {@see self::NONE} when the value is null or unrecognised.
     *
     * The fallback is what lets a newer backend ship a fourth rail without
     * crashing an older reader. It lands on `NONE` rather than on a real rail
     * on purpose: reading an unknown provider as an existing one would let
     * that rail's events act on a grant it never made.
     */
    public static function fromWire(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::NONE;
    }

    /**
     * True when a real rail stands behind the entitlement.
     *
     * `MANUAL` counts, because an operator-granted plan is as entitled as a
     * paid one. Only `NONE` means nothing is backing the plan at all.
     *
     * The `match` carries no `default` arm deliberately: a fifth rail added
     * later has to be decided here, and an unlisted case raises rather than
     * reading as a quiet `false`.
     */
    public function grants(): bool
    {
        return match ($this) {
            self::STRIPE, self::APP_STORE, self::PLAY_STORE, self::MANUAL => true,
            self::NONE => false,
        };
    }

    /**
     * True for the rails where the purchase is managed by the store rather
     * than by the application.
     *
     * The caller that matters is the one deciding WHERE a customer manages a
     * subscription. A store-sold subscription can only be managed in that
     * store's own account surface, so pointing a store subscriber anywhere
     * else is both broken and a steering attempt the store forbids.
     */
    public function isStore(): bool
    {
        return match ($this) {
            self::APP_STORE, self::PLAY_STORE => true,
            self::NONE, self::STRIPE, self::MANUAL => false,
        };
    }

    /**
     * The translated, human-readable name of this rail.
     *
     * Resolved from the package's own `billing` catalogue so the neutral
     * vocabulary reaches a customer in their language without every consumer
     * writing the same five strings again.
     */
    public function label(): string
    {
        return (string) __('magic-starter::billing.providers.' . $this->value);
    }
}
