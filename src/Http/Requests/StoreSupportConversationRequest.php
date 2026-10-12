<?php

namespace FlutterSdk\MagicStarter\Http\Requests;

use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A signed-in user opening a support conversation.
 *
 * The owner and the team are never part of the request: the action takes both
 * from the auth context. The public form source belongs to the public door.
 */
class StoreSupportConversationRequest extends FormRequest
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
            'subject' => [
                'required',
                'string',
                'max:255',
            ],
            'body' => [
                'required',
                'string',
                'max:' . (int) config('magic-starter.support.message_max_length', 2000),
            ],
            'source' => [
                'sometimes',
                Rule::enum(SupportConversationSource::class)->only([
                    SupportConversationSource::IN_APP,
                    SupportConversationSource::CONTACT_SALES,
                ]),
            ],
        ];
    }
}
