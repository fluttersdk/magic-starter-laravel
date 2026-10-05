<?php

namespace FlutterSdk\MagicStarter\Filament\Ops;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\Access\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Telescope\Telescope;

/**
 * One access rule for Horizon, Pulse and Telescope: the admin panel's own.
 *
 * A request passes when the user signed in on the panel's guard may access the
 * panel, so the allowlist (or the plugin's `authorizeUsing()` callback) decides
 * for the tools exactly as it does for the panel.
 *
 * Each tool keeps its rule in one slot that the last writer owns, and an app's
 * own tool provider writes it during boot. Wire these after the application has
 * booted. The panel is captured once, which is safe under Octane; the user is
 * read from the guard on every check.
 */
class OpsAuthorization
{
    public function __construct(
        protected Panel $panel,
    ) {}

    /**
     * Whether the panel's current user may reach the operations tools.
     */
    public function allows(): bool
    {
        $user = $this->panel->auth()->user();

        return $user instanceof FilamentUser && $user->canAccessPanel($this->panel);
    }

    public function guardHorizon(): void
    {
        Horizon::auth(fn (): bool => $this->allows());
    }

    /**
     * Pulse asks the gate for `viewPulse`; the nullable user keeps the ability
     * evaluated for a guest, who is then refused by the panel rule.
     */
    public function guardPulse(Gate $gate): void
    {
        $gate->define('viewPulse', fn (mixed $user = null): bool => $this->allows());
    }

    public function guardTelescope(): void
    {
        Telescope::auth(fn (): bool => $this->allows());
    }
}
