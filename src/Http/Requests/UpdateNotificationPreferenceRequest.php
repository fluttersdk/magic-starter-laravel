<?php

namespace FlutterSdk\MagicStarter\Http\Requests;

use FlutterSdk\MagicStarter\NotificationPreferenceRegistry;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates notification preference updates (single or bulk).
 *
 * Supports both single `{type, channel, is_enabled}` and
 * bulk `{preferences: [{type, channel, is_enabled}, ...]}` payloads.
 */
class UpdateNotificationPreferenceRequest extends FormRequest
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
        if ($this->has('preferences')) {
            return [
                'preferences' => [
                    'required',
                    'array',
                    'min:1',
                ],
                'preferences.*.type' => [
                    'required',
                    'string',
                ],
                'preferences.*.channel' => [
                    'required',
                    'string',
                ],
                'preferences.*.is_enabled' => [
                    'required',
                    'boolean',
                ],
            ];
        }

        return [
            'type' => [
                'required',
                'string',
            ],
            'channel' => [
                'required',
                'string',
            ],
            'is_enabled' => [
                'required',
                'boolean',
            ],
        ];
    }

    /**
     * Configure the validator instance with custom after-validation checks.
     *
     * Ensures that the requested type is registered, the channel is available
     * for that type, and the channel is not locked.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $items = $this->has('preferences')
                ? $this->input('preferences', [])
                : [
                    [
                        'type' => $this->input('type'),
                        'channel' => $this->input('channel'),
                    ],
                ];

            foreach ($items as $index => $item) {
                $prefix = $this->has('preferences') ? "preferences.{$index}." : '';
                $type = $item['type'] ?? null;
                $channel = $item['channel'] ?? null;

                if ($type === null || $channel === null) {
                    continue;
                }

                // 1. Verify the notification type is registered.
                if (! NotificationPreferenceRegistry::has($type)) {
                    $validator->errors()->add(
                        "{$prefix}type",
                        "The notification type '{$type}' is not registered.",
                    );

                    continue;
                }

                // 2. Verify the channel is available for this type.
                $availableChannels = NotificationPreferenceRegistry::channels($type);

                if (! in_array($channel, $availableChannels, true)) {
                    $validator->errors()->add(
                        "{$prefix}channel",
                        "The channel '{$channel}' is not available for type '{$type}'.",
                    );

                    continue;
                }

                // 3. Verify the channel is not locked.
                $lockedChannels = NotificationPreferenceRegistry::locked($type);

                if (in_array($channel, $lockedChannels, true)) {
                    $validator->errors()->add(
                        "{$prefix}channel",
                        "The channel '{$channel}' is locked for type '{$type}' and cannot be changed.",
                    );
                }
            }
        });
    }
}
