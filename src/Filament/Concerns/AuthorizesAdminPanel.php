<?php

namespace FlutterSdk\MagicStarter\Filament\Concerns;

use Filament\Panel;
use FlutterSdk\MagicStarter\Filament\MagicStarterPlugin;

/**
 * Panel access for the user model: the gate in front of every admin write.
 *
 * The consuming user model implements `Filament\Models\Contracts\FilamentUser`
 * and uses this trait. Access fails closed: a panel without the plugin, a
 * guest, an empty or absent allowlist, an empty address and an unverified
 * address all deny. A plugin `authorizeUsing()` callback replaces every check
 * after it, the guest refusal included.
 *
 * A guest is refused by name, because neither of the other checks keeps one
 * out: `hasVerifiedEmail()` is true for any guest (the starter's
 * MustVerifyEmail trait), and a guest can set any address, an allowlisted one
 * included, through the profile endpoint.
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

        // 3. A guest, whatever address it holds.
        if (method_exists($this, 'isGuest') && $this->isGuest()) {
            return false;
        }

        // 4. The candidate address, normalised on this side. An empty one is
        //    refused so it can never meet an empty allowlist entry.
        $email = mb_strtolower(trim((string) $this->email));

        if ($email === '') {
            return false;
        }

        // 5. Allowlist membership, both sides normalised: the config value may
        //    arrive raw from a hand edit. An empty list admits nobody.
        $allowlist = array_map(
            static fn (mixed $entry): string => mb_strtolower(trim((string) $entry)),
            (array) config('magic-starter.admin.emails', []),
        );

        if (! in_array($email, $allowlist, true)) {
            return false;
        }

        // 6. A verified address.
        return $this->hasVerifiedEmail();
    }
}
