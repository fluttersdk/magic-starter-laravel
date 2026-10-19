<?php

namespace FlutterSdk\MagicStarter\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What a customer has typed so far, and the language to suggest in.
 *
 * `locale` is optional and falls back to the default locale in the controller.
 */
class SearchSupportArticlesRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => [
                'required',
                'string',
                'min:3',
                'max:200',
            ],
            'locale' => [
                'nullable',
                'string',
                Rule::in((array) config('magic-starter.supported_locales', ['en'])),
            ],
        ];
    }
}
