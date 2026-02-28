<?php

namespace FlutterSdk\MagicStarter\Http\Requests;

use DateTimeZone;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Rules\E164Phone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Validates incoming phone-based user registration requests.
 *
 * Enforces E.164 phone format, two-character country code, and
 * strong password requirements. Email is intentionally absent —
 * phone is the primary identifier for this registration flow.
 */
class PhoneRegisterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the phone registration request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'phone' => [
                'required',
                'string',
                'max:20',
                new E164Phone,
                Rule::unique((new (MagicStarter::userModel()))->getTable(), 'phone'),
            ],
            'phone_country' => [
                'required',
                'string',
                'size:2',
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
        ];
    }
}
