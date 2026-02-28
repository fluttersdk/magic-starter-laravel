<?php

namespace FlutterSdk\MagicStarter\Http\Requests;

use DateTimeZone;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Rules\E164Phone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Validates incoming registration requests.
 *
 * Identity rules are dynamically built from the identity strategy config
 * (`auth.email` / `auth.phone`). When both identifiers are enabled,
 * the user must provide at least one — email, phone, or both.
 */
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
        $rules = [
            'name' => [
                'required',
                'string',
                'max:255',
            ],
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
            'subscribe_newsletter' => [
                'nullable',
                'boolean',
            ],
        ];

        $emailEnabled = Features::emailIdentity();
        $phoneEnabled = Features::phoneIdentity();
        $userTable = (new (MagicStarter::userModel()))->getTable();

        if ($emailEnabled && $phoneEnabled) {
            $rules['email'] = [
                'required_without:phone',
                'nullable',
                'string',
                'email',
                'max:255',
                Rule::unique($userTable, 'email'),
            ];
            $rules['phone'] = [
                'required_without:email',
                'nullable',
                'string',
                'max:20',
                new E164Phone,
                Rule::unique($userTable, 'phone'),
            ];
            $rules['phone_country'] = [
                'required_with:phone',
                'nullable',
                'string',
                'size:2',
            ];
        } elseif ($phoneEnabled) {
            $rules['phone'] = [
                'required',
                'string',
                'max:20',
                new E164Phone,
                Rule::unique($userTable, 'phone'),
            ];
            $rules['phone_country'] = [
                'required',
                'string',
                'size:2',
            ];
        } else {
            $rules['email'] = [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique($userTable, 'email'),
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
            'password.min' => 'The password must be at least 8 characters.',
        ];
    }
}
