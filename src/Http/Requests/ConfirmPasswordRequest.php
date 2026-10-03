<?php

namespace FlutterSdk\MagicStarter\Http\Requests;

use FlutterSdk\MagicStarter\Http\Requests\Concerns\ConfirmsIdentity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Confirms the caller's identity for sensitive operations (sudo mode).
 *
 * Used by any endpoint that requires the user to re-confirm themselves before
 * proceeding (e.g., 2FA disable, recovery codes, session revocation). A
 * password-less account steps up instead, see {@see ConfirmsIdentity}.
 */
class ConfirmPasswordRequest extends FormRequest
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
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return $this->identityRules();
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->confirmIdentity($validator, __('magic-starter::auth.password.confirmation_mismatch'));
        });
    }
}
