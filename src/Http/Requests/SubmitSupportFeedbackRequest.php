<?php

namespace FlutterSdk\MagicStarter\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The answer to the in-app feedback card: a 0-10 score, or none when the card was dismissed.
 *
 * Whether the caller may answer at all is decided by the action, never here.
 */
class SubmitSupportFeedbackRequest extends FormRequest
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
            'score' => [
                'nullable',
                'integer',
                'between:0,10',
            ],
            'comment' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }
}
