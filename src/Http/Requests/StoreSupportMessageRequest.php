<?php

namespace FlutterSdk\MagicStarter\Http\Requests;

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Support\SupportRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A customer's message on an existing conversation, signed in or through the
 * public thread link.
 *
 * Only the body is read, and on the signed-in route the `attachments` that
 * make it optional: who wrote it and what kind of entry it is are decided by
 * the action, never by the request. The public thread takes no files.
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
        return SupportRules::message(
            attachments: Features::hasSupportAttachmentsFeatures() && $this->route('conversation') !== null,
        );
    }
}
