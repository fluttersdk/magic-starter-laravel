<?php

declare(strict_types=1);

namespace FlutterSdk\MagicStarter\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates team photo upload requests.
 */
class UpdateTeamPhotoRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules for the team photo upload.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'photo' => [
                'required',
                'image',
                'max:2048',
            ],
        ];
    }
}
