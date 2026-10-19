<?php

namespace FlutterSdk\MagicStarter\Http\Resources;

use FlutterSdk\MagicStarter\Enums\SupportSenderType;
use FlutterSdk\MagicStarter\Models\SupportArticle;
use FlutterSdk\MagicStarter\Models\SupportMessage;
use FlutterSdk\MagicStarter\Models\SupportMessageAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

/**
 * One customer-visible entry of a support thread.
 *
 * Carries who wrote it by role, and for staff and the AI desk a display name
 * a customer may see: a staff member's first name and photo, never their id,
 * surname or email. The AI desk record in `meta` never leaves the server, but
 * the knowledge-base cards the job validated and stored under `meta.articles`:
 * an AI message carries them as `articles`, each a slug, a question and a `url`
 * to the public FAQ page, and no other message carries the key.
 *
 * `attachments` is present only once the signed-in customer's controller
 * calls {@see self::withAttachments()}: the public thread link is a bearer
 * credential in a mailbox, so its payload never names a file.
 *
 * @mixin SupportMessage
 */
class SupportMessageResource extends JsonResource
{
    /**
     * Whether `attachments` is serialised; set only on the signed-in path.
     */
    private bool $withAttachments = false;

    /**
     * Serialise the message's files, each with its API-relative `url`.
     *
     * The caller loads `attachments` first, so a thread costs one query for all of them.
     */
    public function withAttachments(bool $withAttachments = true): static
    {
        $this->withAttachments = $withAttachments;

        return $this;
    }

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
            'attachments' => $this->when(
                $this->withAttachments,
                fn (): array => $this->resource->attachments
                    ->map(static fn (SupportMessageAttachment $attachment): array => self::attachment($attachment))
                    ->values()
                    ->all(),
            ),
            'articles' => $this->when(
                $this->sender_type === SupportSenderType::AI,
                fn (): array => $this->articleCards(),
            ),
        ];
    }

    /**
     * The validated cards stored with an AI answer; `url` is the public FAQ page of the language the card was
     * read in, anchored on the slug, or null while no page is configured. A card stored before cards carried
     * their `locale` links the default locale's page.
     *
     * @return list<array{slug: string, question: string, url: string|null}>
     */
    private function articleCards(): array
    {
        $default = (string) config('magic-starter.defaults.locale');

        return array_map(
            static fn (array $card): array => [
                'slug' => $card['slug'],
                'question' => $card['question'],
                'url' => SupportArticle::publicUrl($card['slug'], (string) ($card['locale'] ?? $default)),
            ],
            $this->meta['articles'] ?? [],
        );
    }

    /**
     * One file as the customer sees it; `url` is relative to the API base.
     *
     * @return array{id: mixed, name: string, mime: string, size: int, width: int|null, height: int|null,
     *               kind: string, url: string}
     */
    private static function attachment(SupportMessageAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'name' => $attachment->original_name,
            'mime' => $attachment->mime,
            'size' => $attachment->size,
            'width' => $attachment->width,
            'height' => $attachment->height,
            'kind' => $attachment->mime === 'application/pdf' ? 'pdf' : 'image',
            'url' => "support/conversations/{$attachment->conversation_id}/attachments/{$attachment->id}",
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
