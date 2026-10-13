<?php

namespace FlutterSdk\MagicStarter\Http\Requests;

use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Support\SupportRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A signed-in user opening a support conversation.
 *
 * The owner and the team are never part of the request: the action takes both
 * from the auth context. The public form source belongs to the public door. A
 * topic is required unless the source is contact sales, which the action files
 * under `sales`; the subject is optional and derived by the action when absent.
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
        return SupportRules::conversation([
            SupportConversationSource::IN_APP,
            SupportConversationSource::CONTACT_SALES,
        ], topicRequired: true);
    }
}
