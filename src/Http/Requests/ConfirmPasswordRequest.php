<?php

namespace FlutterSdk\MagicStarter\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Validator;

/**
 * Validates password confirmation for sensitive operations (sudo mode).
 *
 * Used by any endpoint that requires the user to re-confirm their
 * password before proceeding (e.g., 2FA enable/disable, recovery codes).
 */
class ConfirmPasswordRequest extends FormRequest
{
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
        return [
            'password' => [
                'required',
                'string',
            ],
        ];
    }

    /**
     * Configure the validator instance.
     *
     * Verifies that the provided password matches the authenticated
     * user's current password via Hash::check().
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            if (! Hash::check((string) $this->input('password'), (string) $this->user()?->getAuthPassword())) {
                $validator->errors()->add('password', 'The provided password does not match your current password.');
            }
        });
    }
}
