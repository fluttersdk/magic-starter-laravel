<?php

namespace FlutterSdk\MagicStarter\Http\Requests;

use FlutterSdk\MagicStarter\Http\Requests\Concerns\ConfirmsIdentity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Confirms the caller's identity before their account is scheduled for deletion.
 */
class DeleteAccountRequest extends FormRequest
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
     * Get the validation rules that apply to the account deletion request.
     *
     * @return array<string, mixed>
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
            $this->confirmIdentity($validator, __('magic-starter::auth.password.incorrect'));
        });
    }
}
