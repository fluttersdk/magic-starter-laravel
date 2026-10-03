<?php

namespace FlutterSdk\MagicStarter\Http\Requests;

use FlutterSdk\MagicStarter\Social\SocialFlowStore;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a provider ID token posted by a native client.
 *
 * Only an ID token is taken, never an access token: an access token proves
 * nothing about who it was issued to. Apple needs the raw `nonce` whose hash
 * the client gave Apple, and may carry the one-time `authorization_code` that
 * yields the refresh token account deletion revokes. `intent` defaults to a
 * sign-in; `connect` and `confirm` act for the caller's bearer.
 */
class SocialTokenRequest extends FormRequest
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
        $apple = $this->route('provider') === 'apple';

        return [
            'id_token' => ['required', 'string', 'max:8192'],
            'nonce' => [Rule::requiredIf($apple), Rule::prohibitedIf(! $apple), 'string', 'max:255'],
            'authorization_code' => ['nullable', Rule::prohibitedIf(! $apple), 'string', 'max:2048'],
            'intent' => [
                'nullable',
                'string',
                Rule::in([
                    SocialFlowStore::INTENT_SIGNIN,
                    SocialFlowStore::INTENT_CONNECT,
                    SocialFlowStore::INTENT_CONFIRM,
                ]),
            ],
        ];
    }

    /**
     * The flow the caller asked for; a sign-in unless it named another.
     */
    public function intent(): string
    {
        return (string) ($this->validated('intent') ?? SocialFlowStore::INTENT_SIGNIN);
    }
}
