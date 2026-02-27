<?php

namespace FlutterSdk\MagicStarter\Http\Requests;

use FlutterSdk\MagicStarter\Rules\E164Phone;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a request to verify an OTP code.
 */
class VerifyOtpRequest extends FormRequest
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
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'phone' => [
                'required',
                'string',
                new E164Phone,
            ],
            'code' => [
                'required',
                'string',
                'size:6',
            ],
        ];
    }
}
