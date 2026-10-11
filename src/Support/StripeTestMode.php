<?php

namespace FlutterSdk\MagicStarter\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Model;

/**
 * Whether this server is selling on a Stripe TEST key where it should not be,
 * and who may buy anyway.
 *
 * A test key accepts test cards, so a public box left on one would grant a
 * paid tier to anybody who types `4242 4242 4242 4242`. The billing controller
 * refuses checkout and swap for everybody but an allowlisted person while
 * {@see self::active()} holds, and `billing:doctor` reports the same state, so
 * the predicate exists once rather than in both.
 *
 * Fails CLOSED on the environment: only `local` and `testing` are exempt, so a
 * box named `staging`, `prod` or anything else on a test key is guarded.
 */
final class StripeTestMode
{
    /**
     * Whether the guard is in force: a test key outside local development.
     *
     * Restricted keys count too, because `rk_test_` is as much a test key as
     * `sk_test_`. A live key, or no key at all, answers no.
     */
    public static function active(): bool
    {
        $secret = (string) config('cashier.secret');

        if (! str_starts_with($secret, 'sk_test_') && ! str_starts_with($secret, 'rk_test_')) {
            return false;
        }

        return ! app()->environment(['local', 'testing']);
    }

    /**
     * Whether [$user] may buy while the guard is in force.
     *
     * Every condition has to hold: a signed-in, non-guest user whose email is
     * on the allowlist (compared lower-cased) and verified. A guest is refused
     * by name because the package's `hasVerifiedEmail()` answers yes for every
     * guest, so the verification check alone would let an allowlisted guest
     * through. A model that does not implement {@see MustVerifyEmail} has no
     * verification to ask about and is judged on the list alone.
     *
     * @param  Authenticatable|null  $user  The request's user; null always answers no.
     */
    public static function allows(?Authenticatable $user): bool
    {
        if (! $user instanceof Model) {
            return false;
        }

        if (method_exists($user, 'isGuest') && $user->isGuest()) {
            return false;
        }

        $email = $user->getAttribute('email');

        if (! is_string($email) || $email === '') {
            return false;
        }

        if (! in_array(strtolower($email), self::allowlist(), true)) {
            return false;
        }

        return ! $user instanceof MustVerifyEmail || $user->hasVerifiedEmail();
    }

    /**
     * How many addresses the allowlist carries, for a report that must not
     * print the addresses themselves.
     */
    public static function allowlistCount(): int
    {
        return count(self::allowlist());
    }

    /**
     * The allowlist, trimmed, lower-cased and without blanks.
     *
     * Normalised here as well as in the shipped config, because an
     * application that publishes its own `billing` block may parse the env
     * list without lower-casing it, and a mixed-case entry would then never
     * match.
     *
     * @return array<int, string>
     */
    private static function allowlist(): array
    {
        $emails = array_filter(
            (array) config('magic-starter.billing.stripe_test_mode.emails', []),
            is_string(...),
        );

        return array_values(array_filter(array_map(
            fn (string $email): string => strtolower(trim($email)),
            $emails,
        )));
    }
}
