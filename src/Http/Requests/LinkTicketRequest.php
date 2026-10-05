<?php

namespace FlutterSdk\MagicStarter\Http\Requests;

use FlutterSdk\MagicStarter\Http\Requests\Concerns\ConfirmsIdentity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates an authenticated app asking for a link ticket before a connect.
 *
 * `challenge` is the S256 challenge of the verifier the app will finish the
 * connect with; the ticket is bound to it, so the redirect has to carry the
 * same one. A linked identity is a new way into the account, so the caller
 * re-confirms first, see {@see ConfirmsIdentity}.
 */
class LinkTicketRequest extends FormRequest
{
    use ConfirmsIdentity;

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
            ...$this->identityRules(),
            'provider' => ['required', 'string', 'max:32'],
            // RFC 7636: base64url(sha256(verifier)) without padding is exactly 43 characters.
            'challenge' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->confirmIdentity($validator, __('magic-starter::auth.password.confirmation_mismatch'));
        });
    }
}
