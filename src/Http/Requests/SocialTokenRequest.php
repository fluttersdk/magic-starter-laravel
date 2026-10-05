<?php

namespace FlutterSdk\MagicStarter\Http\Requests;

use FlutterSdk\MagicStarter\Http\Requests\Concerns\ConfirmsIdentity;
use FlutterSdk\MagicStarter\Social\SocialFlowStore;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a provider ID token posted by a native client.
 *
 * Only an ID token is taken, never an access token: an access token proves
 * nothing about who it was issued to. Apple needs the raw `nonce` whose hash
 * the client gave Apple, and may carry the one-time `authorization_code` that
 * yields the refresh token account deletion revokes. `intent` defaults to a
 * sign-in; `connect` and `confirm` act for the caller's bearer.
 *
 * A connect adds a way into the bearer's account, so it re-confirms the
 * bearer first, see {@see ConfirmsIdentity}. A connect without a bearer is
 * left to the controller's 401.
 */
class SocialTokenRequest extends FormRequest
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
        $apple = $this->route('provider') === 'apple';

        return [
            ...($this->stepsUp() ? $this->identityRules() : []),
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
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->stepsUp()) {
                $this->confirmIdentity($validator, __('magic-starter::auth.password.confirmation_mismatch'));
            }
        });
    }

    /**
     * The bearer, read from Sanctum: the route also serves a sign-in, so it
     * carries no auth middleware to make Sanctum the default guard.
     */
    protected function confirmingUser(): ?Authenticatable
    {
        return Auth::guard('sanctum')->user();
    }

    /**
     * The flow the caller asked for; a sign-in unless it named another.
     */
    public function intent(): string
    {
        return (string) ($this->validated('intent') ?? SocialFlowStore::INTENT_SIGNIN);
    }

    /**
     * Whether this request has to re-confirm its bearer: a connect that has one.
     */
    private function stepsUp(): bool
    {
        return $this->input('intent') === SocialFlowStore::INTENT_CONNECT
            && $this->confirmingUser() !== null;
    }
}
