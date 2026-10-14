<?php

namespace FlutterSdk\MagicStarter\Http\Requests;

use FlutterSdk\MagicStarter\Enums\SupportCsatRating;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A customer's thumbs rating of a resolved conversation, with an optional comment.
 *
 * Whether the conversation is theirs and resolved is decided by the route and the action, never here.
 */
class RateSupportConversationRequest extends FormRequest
{
    /**
     * The longest comment, in characters.
     */
    public const COMMENT_MAX_LENGTH = 1000;

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
            'rating' => [
                'required',
                Rule::enum(SupportCsatRating::class),
            ],
            'comment' => [
                'nullable',
                'string',
                'max:' . self::COMMENT_MAX_LENGTH,
            ],
        ];
    }
}
