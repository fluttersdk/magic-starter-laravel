<?php

namespace FlutterSdk\MagicStarter\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class UpdatePasswordRequest extends FormRequest
{
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
        $user = $this->user();
        $isGuestWithoutPassword = $user && (bool) ($user->is_guest ?? false) && empty($user->password);

        return [
            'current_password' => $isGuestWithoutPassword ? ['sometimes', 'string'] : ['required', 'string'],
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
        $validator->after(function (Validator $validator) {
            $user = $this->user();
            $isGuestWithoutPassword = $user && (bool) ($user->is_guest ?? false) && empty($user->password);

            if (! $isGuestWithoutPassword && ! Hash::check((string) $this->input('current_password'), (string) $user?->getAuthPassword())) {
                $validator->errors()->add('current_password', __('The current password is incorrect.'));
            }
        });
    }
}
