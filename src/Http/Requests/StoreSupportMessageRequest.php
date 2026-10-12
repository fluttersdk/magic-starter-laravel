<?php

namespace FlutterSdk\MagicStarter\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A customer's message on an existing conversation, signed in or through the
 * public thread link.
 *
 * Only the body is read: who wrote it and what kind of entry it is are decided
 * by the action, never by the request.
 */
class StoreSupportMessageRequest extends FormRequest
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
            'body' => [
                'required',
                'string',
                'max:' . (int) config('magic-starter.support.message_max_length', 2000),
            ],
        ];
    }
}
