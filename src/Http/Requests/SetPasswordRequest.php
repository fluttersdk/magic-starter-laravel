<?php

namespace FlutterSdk\MagicStarter\Http\Requests;

use FlutterSdk\MagicStarter\Http\Requests\Concerns\ConfirmsIdentity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * Validates a social-only account's first password.
 *
 * The rules are a password change's ({@see UpdatePasswordRequest}) without
 * the current password, which a password-less account cannot give. A first
 * password is a new way into the account, so it is gated like any other
 * sensitive action: the caller steps up with a `code` or a
 * `confirmation_token` (see {@see ConfirmsIdentity}); a guest keeps its bypass.
 * An account that already has a password is refused with 422
 * `password_already_set`, pointing it at a password change.
 */
class SetPasswordRequest extends FormRequest
{
    use ConfirmsIdentity;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // `password` carries the new password here, so the proof field must not share its name.
            ...$this->identityRules('current_password'),
            'password' => [
                'required',
                'string',
                Password::min(8)->letters()->numbers()->mixedCase(),
                'confirmed',
            ],
            'password_confirmation' => [
                'required',
                'string',
            ],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->userHasPassword()) {
                $this->refuseWithCode(
                    'password_already_set',
                    (string) __('magic-starter::social.password_already_set'),
                    [
                        'password',
                    ],
                );
            }

            $this->confirmIdentity(
                $validator,
                __('magic-starter::auth.password.current_incorrect'),
                'current_password',
            );
        });
    }
}
