<?php

namespace FlutterSdk\MagicStarter\Support;

use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use FlutterSdk\MagicStarter\Filament\MagicStarterPlugin;
use FlutterSdk\MagicStarter\MagicStarter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * The staff who answer support: who is told about a conversation and who may
 * be assigned one.
 *
 * A recipient is on `magic-starter.admin.emails`, is not a guest, holds a
 * verified address and, once a Filament panel carries the package plugin, is
 * admitted by `canAccessPanel()` on one such panel. Every check has to pass:
 * a plugin `authorizeUsing()` rule narrows the allowlist here, it never
 * replaces it, because the allowlist is what keeps a support alert from
 * reaching an account the operator did not name.
 *
 * The verified check reads `email_verified_at` itself rather than
 * `hasVerifiedEmail()`, which answers true for every guest.
 */
final class SupportStaffRecipients
{
    /**
     * Every user who currently qualifies.
     *
     * @return Collection<int, Model>
     */
    public function all(): Collection
    {
        $allowlist = $this->allowlist();

        if ($allowlist === []) {
            return new Collection;
        }

        /** @var class-string<Model> $userModel */
        $userModel = MagicStarter::userModel();

        return $userModel::query()
            ->whereIn('email', $allowlist)
            ->whereNotNull('email_verified_at')
            ->get()
            ->filter(fn (Model $user): bool => $this->includes($user))
            ->values();
    }

    /**
     * Whether the user qualifies as a support recipient right now.
     */
    public function includes(Model $user): bool
    {
        $email = $this->normalise($user->getAttribute('email'));

        if ($email === '' || ! in_array($email, $this->allowlist(), true)) {
            return false;
        }

        if (method_exists($user, 'isGuest') && $user->isGuest()) {
            return false;
        }

        if ($user->getAttribute('email_verified_at') === null) {
            return false;
        }

        return $this->passesPanelGate($user);
    }

    /**
     * The allowlist, normalised the way the panel gate normalises it.
     *
     * @return list<string>
     */
    private function allowlist(): array
    {
        return array_values(array_filter(array_map(
            fn (mixed $entry): string => $this->normalise($entry),
            (array) config('magic-starter.admin.emails', []),
        )));
    }

    /**
     * Without Filament, or without a panel carrying the plugin, the allowlist
     * stands alone. With one, the user must be let into at least one of them;
     * a user model that is not a FilamentUser is let into none.
     */
    private function passesPanelGate(Model $user): bool
    {
        if (! class_exists(Panel::class) || ! app()->bound('filament')) {
            return true;
        }

        $panels = array_filter(
            Filament::getPanels(),
            static fn (Panel $panel): bool => $panel->hasPlugin(MagicStarterPlugin::ID),
        );

        if ($panels === []) {
            return true;
        }

        if (! $user instanceof FilamentUser) {
            return false;
        }

        foreach ($panels as $panel) {
            if ($user->canAccessPanel($panel)) {
                return true;
            }
        }

        return false;
    }

    private function normalise(mixed $email): string
    {
        return mb_strtolower(trim((string) $email));
    }
}
