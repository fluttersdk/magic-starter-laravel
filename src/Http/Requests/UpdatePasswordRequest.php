<?php

namespace FlutterSdk\MagicStarter\Http\Requests;

use FlutterSdk\MagicStarter\Http\Requests\Concerns\ConfirmsIdentity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * Validates a password change against the current password.
 *
 * A guest without a password sets its first one here, as before. Any other
 * password-less account has no current password to change and is refused with
 * 422 `password_not_set`, pointing it at `user/password/set`.
 */
class UpdatePasswordRequest extends FormRequest
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
     * Get the validation rules that apply to the password update request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
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
            if (! $this->userHasPassword() && ! $this->isGuestWithoutPassword()) {
                $this->refuseWithCode(
                    'password_not_set',
                    (string) __('magic-starter::social.password_not_set'),
                    [
                        'current_password',
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
