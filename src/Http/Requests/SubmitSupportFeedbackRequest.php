<?php

namespace FlutterSdk\MagicStarter\Http\Requests;

use Closure;
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
                // `integer` alone reads a JSON true as 1, which would file a low score.
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_bool($value)) {
                        $fail('validation.integer')->translate();
                    }
                },
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
