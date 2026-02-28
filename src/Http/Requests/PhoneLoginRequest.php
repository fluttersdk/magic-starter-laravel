<?php

namespace FlutterSdk\MagicStarter\Http\Requests;

use FlutterSdk\MagicStarter\Rules\E164Phone;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates incoming phone-based authentication requests.
 *
 * Requires a valid E.164 phone number and a password.
 * No email field — phone is the sole login identifier.
 */
class PhoneLoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the phone login request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phone' => [
                'required',
                'string',
                new E164Phone,
            ],
            'password' => [
                'required',
                'string',
            ],
        ];
    }
}
