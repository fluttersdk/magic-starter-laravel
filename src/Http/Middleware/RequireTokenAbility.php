<?php

namespace FlutterSdk\MagicStarter\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Contracts\HasAbilities;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;
use Symfony\Component\HttpFoundation\Response;
use UnexpectedValueException;

/**
 * Refuses a signed-in token that was not issued for the ability the host names.
 *
 * Sanctum mints `*` unless told otherwise and nothing checked what a token said
 * it was for, so a token minted for one surface reached every route of another.
 * The host names the ability in `magic-starter.required_ability`; with none
 * named this does nothing, so a host that never opted in sees no change.
 *
 * It sits on the `auth:sanctum` route groups, after the authentication
 * middleware, and not in `route_middleware`: that list also wraps login and
 * register, which a caller reaches with no token at all.
 *
 * A few public routes act for a bearer when one is sent (the connect and
 * confirm intents of the social flow). They carry no auth middleware, so they
 * read the caller through {@see self::bearer()}, which applies the same rule.
 */
final class RequireTokenAbility
{
    /**
     * A request with no caller passes untouched: `auth:sanctum` runs first and
     * answers it with 401.
     *
     * @param  Closure(Request): Response  $next
     *
     * @throws UnexpectedValueException When the configured ability is not a string.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || self::allows($user)) {
            return $next($request);
        }

        return $this->refusal();
    }

    /**
     * The Sanctum caller of a public route, or null when there is none or its
     * token lacks the required ability: such a bearer acts for nobody.
     *
     * @throws UnexpectedValueException When the configured ability is not a string.
     */
    public static function bearer(): ?Authenticatable
    {
        $user = Auth::guard('sanctum')->user();

        return $user !== null && self::allows($user) ? $user : null;
    }

    /**
     * Whether the user's current token may reach a gated route. Always true
     * while no ability is required.
     *
     * @throws UnexpectedValueException When the configured ability is not a string.
     */
    public static function allows(Authenticatable $user): bool
    {
        $ability = config('magic-starter.required_ability');

        if ($ability === null || $ability === '') {
            return true;
        }

        // A control that fails open on a typo is worse than none: `true` from an
        // env value, or an array where a string belongs, stops the request.
        if (! is_string($ability)) {
            throw new UnexpectedValueException('magic-starter.required_ability must be a string or null, ' . get_debug_type($ability) . ' given.');
        }

        // `HasApiTokens` is a trait the consumer applies to its own user model.
        $token = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;

        return self::admits($token, $ability);
    }

    /**
     * A session (transient) token is the first-party app itself. A NULL
     * `abilities` column is read as unrestricted: Sanctum itself never writes
     * one, so it is a row a host inserted or migrated by hand, and Sanctum's
     * `can()` throws on it (it feeds the column to `in_array()`). Exactly null
     * and not empty: the mock token `Sanctum::actingAs` builds answers `false`
     * here and must fall through to `can()`.
     */
    private static function admits(?HasAbilities $token, string $ability): bool
    {
        if ($token instanceof TransientToken) {
            return true;
        }

        return $token instanceof PersonalAccessToken
            && ($token->getAttribute('abilities') === null || $token->can($ability));
    }

    private function refusal(): JsonResponse
    {
        return response()->json([
            'message' => __('magic-starter::auth.ability.missing'),
            'code' => 'missing_ability',
        ], 403);
    }
}
