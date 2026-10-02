<?php

namespace FlutterSdk\MagicStarter\Http\Requests;

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Rules\E164Phone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

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
     * Lower-case the email before validation so `unique` and every lookup compare normalized values.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower($this->input('email'))]);
        }
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
            'password.required' => __('magic-starter::auth.password.required'),
        ];
    }
}
