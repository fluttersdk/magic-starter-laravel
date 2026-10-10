<?php

namespace FlutterSdk\MagicStarter\Filament\Support;

use Filament\Facades\Filament;
use FlutterSdk\MagicStarter\Filament\MagicStarterPlugin;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Who among the panel's admins may run the billing actions: grant or revoke a
 * plan, move a trial, cancel or resume a subscription, refund, sync.
 *
 * It narrows panel access, never widens it: the panel gate has already admitted
 * the admin. The billing actions are hidden from anybody this refuses, and
 * Filament refuses to mount or call a hidden action, so the check also holds
 * against a crafted Livewire call.
 */
class BillingAuthorization
{
    /**
     * Whether `$admin` may run the billing actions.
     *
     * A plugin `authorizeBillingUsing()` callback decides alone when set, and
     * only a literal `true` allows. Otherwise an empty
     * `magic-starter.admin.billing_emails` lets every panel admin through, and
     * a non-empty one admits only the addresses it lists, compared trimmed and
     * lowercased on both sides.
     */
    public static function allows(Authenticatable $admin): bool
    {
        // 1. An application rule replaces the list entirely.
        $callback = static::plugin()?->getAuthorizeBillingUsing();

        if ($callback !== null) {
            return $callback($admin, Filament::getCurrentPanel()) === true;
        }

        // 2. No list: billing is every admin's job.
        $allowlist = array_map(
            static fn (mixed $entry): string => mb_strtolower(trim((string) $entry)),
            (array) config('magic-starter.admin.billing_emails', []),
        );

        if ($allowlist === []) {
            return true;
        }

        // 3. Membership. An admin without an address cannot meet an entry.
        $email = $admin instanceof Model ? mb_strtolower(trim((string) $admin->getAttribute('email'))) : '';

        return $email !== '' && in_array($email, $allowlist, true);
    }

    /**
     * The plugin on the current panel, or null when that panel does not carry it.
     */
    protected static function plugin(): ?MagicStarterPlugin
    {
        $panel = Filament::getCurrentPanel();

        if ($panel === null || ! $panel->hasPlugin(MagicStarterPlugin::ID)) {
            return null;
        }

        /** @var MagicStarterPlugin $plugin */
        $plugin = $panel->getPlugin(MagicStarterPlugin::ID);

        return $plugin;
    }
}
