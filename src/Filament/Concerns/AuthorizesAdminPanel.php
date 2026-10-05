<?php

namespace FlutterSdk\MagicStarter\Filament\Concerns;

use Filament\Panel;
use FlutterSdk\MagicStarter\Filament\MagicStarterPlugin;

/**
 * Panel access for the user model: the gate in front of every admin write.
 *
 * The consuming user model implements `Filament\Models\Contracts\FilamentUser`
 * and uses this trait. Access fails closed: a panel without the plugin, an
 * empty or absent allowlist, an empty address and an unverified address all
 * deny. A plugin `authorizeUsing()` callback replaces the allowlist check whole.
 *
 * `hasVerifiedEmail()` is true for any guest (the starter's MustVerifyEmail
 * trait), so it cannot be what keeps a guest out: the empty-address refusal is.
 *
 * @property ?string $email
 */
trait AuthorizesAdminPanel
{
    public function canAccessPanel(Panel $panel): bool
    {
        // 1. Answer only for a panel this package mounted. Another panel must
        //    state its own rule rather than inherit admin semantics.
        if (! $panel->hasPlugin(MagicStarterPlugin::ID)) {
            return false;
        }

        // 2. An application rule replaces the allowlist entirely.
        /** @var MagicStarterPlugin $plugin */
        $plugin = $panel->getPlugin(MagicStarterPlugin::ID);

        if ($callback = $plugin->getAuthorizeUsing()) {
            return $callback($this, $panel) === true;
        }

        // 3. The candidate address, normalised on this side. An empty one is
        //    refused so it can never meet an empty allowlist entry.
        $email = mb_strtolower(trim((string) $this->email));

        if ($email === '') {
            return false;
        }

        // 4. Allowlist membership, both sides normalised: the config value may
        //    arrive raw from a hand edit. An empty list admits nobody.
        $allowlist = array_map(
            static fn (mixed $entry): string => mb_strtolower(trim((string) $entry)),
            (array) config('magic-starter.admin.emails', []),
        );

        if (! in_array($email, $allowlist, true)) {
            return false;
        }

        // 5. A verified address.
        return $this->hasVerifiedEmail();
    }
}
