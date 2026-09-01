<?php

namespace FlutterSdk\MagicStarter\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a self-addressed push test send.
 *
 * The body says WHAT to send and nothing about WHO to send it to. There is no
 * recipient rule here and there must never be one: the controller derives the
 * target from the authenticated session, which is the whole reason a client may
 * trigger an outbound push at all. Anything else a caller puts in the body is
 * unvalidated and therefore unread, so an unknown field is ignored rather than
 * honoured.
 *
 * `data` is forwarded to the device as the push's `additionalData`. It is
 * bounded on key count as well as on the request size the framework already
 * caps, because it crosses to OneSignal untouched and the caller chooses every
 * byte of it.
 */
class PushTestRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Authorization is the middleware's business in this package. The guest
     * refusal this endpoint needs lives in the controller beside the
     * provisioning gate, so the two refusals read as one decision.
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
            'title' => [
                'required',
                'string',
                'max:120',
            ],
            'body' => [
                'required',
                'string',
                'max:500',
            ],
            'data' => [
                'sometimes',
                'array',
                'max:20',
            ],
        ];
    }
}
