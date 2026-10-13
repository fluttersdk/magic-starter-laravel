<?php

namespace FlutterSdk\MagicStarter\Http\Resources;

use FlutterSdk\MagicStarter\Enums\SupportSenderType;
use FlutterSdk\MagicStarter\Models\SupportMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

/**
 * One customer-visible entry of a support thread.
 *
 * Carries who wrote it by role, and for staff and the AI desk a display name
 * a customer may see: a staff member's first name and photo, never their id,
 * surname or email. The AI desk record in `meta` never leaves the server.
 *
 * @mixin SupportMessage
 */
class SupportMessageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sender_type' => $this->sender_type->value,
            'kind' => $this->kind->value,
            'sender' => self::senderOf($this->resource),
            'body' => $this->body,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * The name and photo a customer sees for who wrote a message.
     *
     * Staff is the first word of the account's name and its profile photo; the
     * AI desk is `support.ai.name`, or the localized ":app AI". A customer or a
     * system line has none, and neither has a staff message whose author's
     * account is gone.
     *
     * @return array{name: string, avatar_url: string|null}|null
     */
    public static function senderOf(SupportMessage $message): ?array
    {
        return match ($message->sender_type) {
            SupportSenderType::STAFF => self::staffSender($message),
            SupportSenderType::AI => [
                'name' => self::aiName(),
                'avatar_url' => null,
            ],
            SupportSenderType::CUSTOMER, SupportSenderType::SYSTEM => null,
        };
    }

    /**
     * @return array{name: string, avatar_url: string|null}|null
     */
    private static function staffSender(SupportMessage $message): ?array
    {
        $staff = $message->sender;

        if ($staff === null) {
            return null;
        }

        return [
            'name' => Str::before(Str::squish((string) $staff->getAttribute('name')), ' '),
            'avatar_url' => method_exists($staff, 'getProfilePhotoUrlAttribute')
                ? $staff->getAttribute('profile_photo_url')
                : null,
        ];
    }

    /**
     * The AI desk's display name, the one rule the customer JSON and the staff panel share.
     *
     * `support.ai.name` when it is a non-empty string; otherwise, null or blank
     * alike, the localized ":app AI".
     */
    public static function aiName(): string
    {
        $configured = config('magic-starter.support.ai.name');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return Lang::get('magic-starter::support.ai.name', [
            'app' => (string) config('app.name'),
        ]);
    }
}
