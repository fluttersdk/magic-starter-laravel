<?php

namespace FlutterSdk\MagicStarter\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SocialLoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the social login request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'access_token' => ['required_without:authorization_code', 'string'],
            'authorization_code' => ['required_without:access_token', 'string'],
        ];
    }
}
