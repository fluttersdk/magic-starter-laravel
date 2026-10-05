<?php

namespace FlutterSdk\MagicStarter\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the exchange of a social flow's one-time code.
 *
 * `code_verifier` is the app's PKCE verifier whose S256 challenge started the
 * flow; possession of the code alone redeems nothing.
 */
class SocialExchangeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:255'],
            // RFC 7636 section 4.1: 43 to 128 unreserved characters.
            'code_verifier' => ['required', 'string', 'regex:/^[A-Za-z0-9._~-]{43,128}$/'],
        ];
    }
}
