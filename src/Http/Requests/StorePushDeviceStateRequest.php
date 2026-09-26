<?php

namespace FlutterSdk\MagicStarter\Http\Requests;

use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\PushDevice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a push delivery state report.
 *
 * The body is `PushDeliverySnapshot.toMap()` from `magic_notifications`:
 * `external_id`, `subscription_id`, `reachability`, `captured_at`. The two
 * nullable fields are `present`, because "this device holds no subscription"
 * is the fact being reported and an absent key cannot be told apart from a
 * client too old to send it.
 *
 * `external_id` is the alias the device carries, so it is the client's to
 * state, but it may not name somebody else: an alias other than the caller's
 * own is refused here rather than silently stored. The row itself is always
 * written under the session's user by the controller, and the body carries no
 * user field this request reads.
 */
class StorePushDeviceStateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Authorization is the middleware's business in this package.
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
        $user = $this->user();
        $userModel = MagicStarter::userModel();
        $callerAlias = $user instanceof $userModel ? PushDevice::externalIdFor($user) : null;

        return [
            'external_id' => [
                'present',
                'nullable',
                'string',
                'max:255',
                // A device subscribed as nobody passes on `nullable`: a fresh
                // install is reportable. A device subscribed as somebody else
                // is not this caller's to report.
                Rule::in(array_filter([
                    $callerAlias,
                ])),
            ],
            'subscription_id' => [
                'present',
                'nullable',
                'string',
                'max:255',
            ],
            'reachability' => [
                'required',
                'string',
                Rule::in(PushDevice::REACHABILITY_VALUES),
            ],
            'captured_at' => [
                'required',
                'date',
            ],
        ];
    }
}
