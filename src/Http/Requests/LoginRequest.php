<?php

namespace FlutterSdk\MagicStarter\Http\Requests;

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Rules\E164Phone;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates incoming login requests.
 *
 * Rules are dynamically built from the identity strategy config
 * (`auth.email` / `auth.phone`). When both identifiers are enabled,
 * the user may provide either one — at least one is required.
 */
class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the login request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'password' => [
                'required',
                'string',
            ],
        ];

        $emailEnabled = Features::emailIdentity();
        $phoneEnabled = Features::phoneIdentity();

        if ($emailEnabled && $phoneEnabled) {
            $rules['email'] = [
                'required_without:phone',
                'nullable',
                'string',
                'email',
            ];
            $rules['phone'] = [
                'required_without:email',
                'nullable',
                'string',
                new E164Phone,
            ];
        } elseif ($phoneEnabled) {
            $rules['phone'] = [
                'required',
                'string',
                new E164Phone,
            ];
        } else {
            $rules['email'] = [
                'required',
                'string',
                'email',
            ];
        }

        return $rules;
    }

    /**
     * Get the custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.required' => 'Password is required.',
        ];
    }
}
