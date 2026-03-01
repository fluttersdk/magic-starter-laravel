<?php

namespace FlutterSdk\MagicStarter\Http\Requests;

use DateTimeZone;
use FlutterSdk\MagicStarter\Rules\E164Phone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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

        return [
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
            'language' => [
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
    }
}
