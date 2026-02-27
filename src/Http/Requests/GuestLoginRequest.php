<?php

namespace FlutterSdk\MagicStarter\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the guest authentication request.
 *
 * Requires a device_id to identify the guest session — no credentials needed.
 */
class GuestLoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules for the guest login request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'device_id' => [
                'required',
                'string',
                'max:255',
            ],
        ];
    }
}
