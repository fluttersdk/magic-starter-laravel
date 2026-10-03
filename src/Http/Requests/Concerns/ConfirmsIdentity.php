<?php

namespace FlutterSdk\MagicStarter\Http\Requests\Concerns;

use FlutterSdk\MagicStarter\Social\StepUpConfirmations;
use FlutterSdk\MagicStarter\Support\TwoFactorAuthenticationProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * Re-confirms the caller's identity before a sensitive action.
 *
 * Three kinds of account meet the gate. One with a password confirms with it,
 * exactly as before. A guest without a password has nothing to prove with and
 * passes, as before. Any other account without a password (a social-only one)
 * steps up: a `code` from its authenticator when 2FA is confirmed, or a
 * single-use `confirmation_token` minted by the social confirm flow. Without
 * either it is refused with 422 `step_up_required`, naming the proofs it can
 * send in `accepts`.
 *
 * A confirmation token is consumed only when nothing else in the request has
 * failed, so a request refused for an unrelated field leaves it spendable.
 *
 * @mixin \Illuminate\Foundation\Http\FormRequest
 */
trait ConfirmsIdentity
{
    /**
     * The validation rules for the proof this user has to send.
     *
     * @param  string  $passwordField  the input carrying the password, `current_password` on a password change
     * @return array<string, array<int, string>>
     */
    protected function identityRules(string $passwordField = 'password'): array
    {
        if ($this->userHasPassword()) {
            return [
                $passwordField => [
                    'required',
                    'string',
                ],
            ];
        }

        if ($this->isGuestWithoutPassword()) {
            return [
                $passwordField => [
                    'sometimes',
                    'string',
                ],
            ];
        }

        return [
            'code' => [
                'sometimes',
                'string',
            ],
            'confirmation_token' => [
                'sometimes',
                'string',
            ],
        ];
    }

    /**
     * Verify the proof from the validator's after hook.
     *
     * @param  string  $mismatchMessage  the translated sentence for a wrong password
     *
     * @throws ValidationException When a password-less account sent no live proof.
     */
    protected function confirmIdentity(
        Validator $validator,
        string $mismatchMessage,
        string $passwordField = 'password',
    ): void {
        // 1. A password account confirms exactly as it always has.
        if ($this->userHasPassword()) {
            if (! Hash::check((string) $this->input($passwordField), (string) $this->user()?->getAuthPassword())) {
                $validator->errors()->add($passwordField, $mismatchMessage);
            }

            return;
        }

        // 2. A guest without a password has nothing to prove with.
        if ($this->isGuestWithoutPassword()) {
            return;
        }

        // 3. Leave a confirmation token unspent while anything else is wrong.
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $this->confirmStepUp($validator);
    }

    /**
     * Determine whether the authenticated user can sign in with a password.
     */
    protected function userHasPassword(): bool
    {
        $user = $this->user();

        if (! $user instanceof Authenticatable) {
            return false;
        }

        return method_exists($user, 'hasPassword')
            ? (bool) $user->hasPassword()
            : (string) $user->getAuthPassword() !== '';
    }

    /**
     * Determine if the authenticated user is a guest without a password.
     */
    protected function isGuestWithoutPassword(): bool
    {
        $user = $this->user();

        return $user instanceof Authenticatable
            && (bool) ($user->is_guest ?? false)
            && ! $this->userHasPassword();
    }

    /**
     * Raise a 422 carrying a stable `code`, the same way the package's other
     * coded refusals do, so a client can switch on the code.
     *
     * @param  array<int, string>  $fields  the inputs the message is reported under
     * @param  array<string, mixed>  $extra  more top-level keys for the client
     *
     * @throws ValidationException
     */
    protected function refuseWithCode(string $code, string $message, array $fields, array $extra = []): never
    {
        $exception = ValidationException::withMessages(
            array_fill_keys($fields, $message),
        );

        $exception->response = new JsonResponse([
            'message' => $message,
            'code' => $code,
            ...$extra,
            'errors' => $exception->errors(),
        ], 422);

        throw $exception;
    }

    /**
     * Accept a TOTP code (confirmed 2FA only) or a confirmation token.
     *
     * A code is judged on its own when 2FA is confirmed, so a mistyped code is
     * reported as one instead of silently spending a token sent alongside it.
     *
     * @throws ValidationException
     */
    private function confirmStepUp(Validator $validator): void
    {
        /** @var Authenticatable $user */
        $user = $this->user();
        $secret = $this->confirmedTwoFactorSecret($user);

        if ($secret !== null && $this->filled('code')) {
            if (! app(TwoFactorAuthenticationProvider::class)->verify($secret, (string) $this->input('code'))) {
                $validator->errors()->add('code', __('magic-starter::auth.two_factor.invalid_challenge_code'));
            }

            return;
        }

        $token = (string) $this->input('confirmation_token');

        if ($token !== '' && app(StepUpConfirmations::class)->consume($user, $token)) {
            return;
        }

        $accepts = $secret !== null
            ? [
                'code',
                'confirmation_token',
            ]
            : [
                'confirmation_token',
            ];

        $this->refuseWithCode(
            'step_up_required',
            (string) __('magic-starter::social.step_up_required'),
            $accepts,
            [
                'accepts' => $accepts,
            ],
        );
    }

    /**
     * The decrypted 2FA secret, only once enrolment has been confirmed.
     */
    private function confirmedTwoFactorSecret(Authenticatable $user): ?string
    {
        if (! method_exists($user, 'hasEnabledTwoFactorAuthentication') || ! $user->hasEnabledTwoFactorAuthentication()) {
            return null;
        }

        return method_exists($user, 'twoFactorSecret') ? $user->twoFactorSecret() : null;
    }
}
