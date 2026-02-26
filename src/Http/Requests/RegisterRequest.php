<?php

namespace FlutterSdk\MagicStarter\Http\Requests;

use DateTimeZone;
use FlutterSdk\MagicStarter\MagicStarter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the registration request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique((new (MagicStarter::userModel()))->getTable(), 'email')],
            'password' => [
                'required',
                'string',
                Password::min(8)->letters()->numbers()->mixedCase(),
                'confirmed',
            ],
            'locale' => [
                'nullable',
                'string',
                Rule::in(
                    config(
                        'magic-starter.supported_locales',
                        ['en'],
                    ),
                ),
            ],
            'timezone' => [
                'nullable',
                'string',
                Rule::in(
                    config(
                        'magic-starter.supported_timezones',
                        DateTimeZone::listIdentifiers(),
                    ),
                ),
            ],
            'subscribe_newsletter' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Get the custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.min' => 'The password must be at least 8 characters.',
        ];
    }
}
