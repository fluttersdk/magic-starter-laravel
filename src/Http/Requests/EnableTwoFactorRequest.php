<?php

namespace FlutterSdk\MagicStarter\Http\Requests;

use FlutterSdk\MagicStarter\Http\Requests\Concerns\ConfirmsIdentity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Confirms the caller's identity before enabling two-factor authentication.
 *
 * A password-less account without 2FA proves itself with a confirmation token
 * from the social confirm flow, so it can still enrol.
 */
class EnableTwoFactorRequest extends FormRequest
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
