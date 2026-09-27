<?php

namespace FlutterSdk\MagicStarter\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a device release: the sign-out that withdraws one device's vouch
 * for the caller's reachability.
 *
 * The body names the device and nothing else. `subscription_id` is required:
 * a body without one names no device, and reading it as "release all of
 * mine" would strand the caller's other handsets. The row itself is always
 * resolved against the session's user by {@see PushDevice::release()}, and
 * this request carries no user field.
 */
class ReleasePushDeviceRequest extends FormRequest
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
        return [
            'subscription_id' => [
                'required',
                'string',
                'max:255',
            ],
        ];
    }
}
