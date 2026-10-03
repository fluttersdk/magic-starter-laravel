<?php

namespace FlutterSdk\MagicStarter\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates an authenticated app asking for a link ticket before a connect.
 *
 * `challenge` is the S256 challenge of the verifier the app will finish the
 * connect with; the ticket is bound to it, so the redirect has to carry the
 * same one.
 */
class LinkTicketRequest extends FormRequest
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
            'provider' => ['required', 'string', 'max:32'],
            // RFC 7636: base64url(sha256(verifier)) without padding is exactly 43 characters.
            'challenge' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/'],
        ];
    }
}
