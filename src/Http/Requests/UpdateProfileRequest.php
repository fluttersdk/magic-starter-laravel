<?php

namespace FlutterSdk\MagicStarter\Http\Requests;

use DateTimeZone;
use FlutterSdk\MagicStarter\Rules\E164Phone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateProfileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the profile update request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->user();
        $isGuest = $user && (bool) ($user->is_guest ?? false);
        $userTable = (new (\FlutterSdk\MagicStarter\MagicStarter::userModel()))->getTable();

        $rules = [
            'name' => [
                $isGuest ? 'nullable' : 'required',
                'string',
                'min:2',
                'max:255',
            ],
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique($userTable, 'email')->ignore($user?->id),
            ],
            'phone_country' => [
                'nullable',
                'string',
                'size:2',
            ],
            'phone' => [
                'nullable',
                'string',
                'max:20',
                new E164Phone,
            ],
            'timezone' => [
                'nullable',
                'string',
                Rule::in(DateTimeZone::listIdentifiers()),
            ],
            'locale' => [
                'nullable',
                'string',
                Rule::in(
                    config(
                        'magic-starter.supported_locales',
                        ['en'],
                    ),
                ),
            ],
        ];

        // Guest users may set a password during profile upgrade (single-call flow).
        // Non-guest users must use the dedicated PUT /user/password endpoint.
        if ($isGuest) {
            $rules['password'] = [
                'nullable',
                'string',
                Password::min(8)->letters()->numbers()->mixedCase(),
                'confirmed',
            ];
            $rules['password_confirmation'] = [
                'nullable',
                'string',
            ];
        }

        return $rules;
    }
}
