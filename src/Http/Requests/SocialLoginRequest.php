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
     * Only an authorization code is accepted. A provider access token proves
     * who owns it, not which client it was minted for, so accepting one would
     * let any app the user ever signed in to replay it here.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'authorization_code' => ['required', 'string'],
        ];
    }
}
