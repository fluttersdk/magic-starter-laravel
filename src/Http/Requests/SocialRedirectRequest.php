<?php

namespace FlutterSdk\MagicStarter\Http\Requests;

use FlutterSdk\MagicStarter\Social\SocialFlowStore;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validates the start of a social flow, which a browser opens rather than an API client.
 *
 * `challenge` is the S256 challenge of a verifier only the app holds: the
 * one-time code the flow ends in is bound to it, so a code intercepted on its
 * way back to the app is worthless without that verifier. A `ticket` turns
 * the flow into a connect, `intent=confirm` into a step-up re-authentication;
 * they are mutually exclusive.
 */
class SocialRedirectRequest extends FormRequest
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
            'platform' => ['required', 'string', Rule::in(SocialFlowStore::PLATFORMS)],
            // RFC 7636: base64url(sha256(verifier)) without padding is exactly 43 characters.
            'challenge' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/'],
            'ticket' => ['nullable', 'string', 'max:255'],
            'intent' => ['nullable', 'string', Rule::in([SocialFlowStore::INTENT_CONFIRM]), 'prohibits:ticket'],
        ];
    }

    /**
     * Answer a refusal as JSON whatever the browser accepts.
     *
     * The default for a request that does not ask for JSON is a redirect back
     * with errors flashed to the session, and this flow has no session and no
     * page to go back to.
     *
     * @throws ValidationException
     */
    protected function failedValidation(Validator $validator): void
    {
        $exception = new ValidationException($validator);
        $exception->response = new JsonResponse([
            'message' => $exception->getMessage(),
            'errors' => $exception->errors(),
        ], 422);

        throw $exception;
    }
}
