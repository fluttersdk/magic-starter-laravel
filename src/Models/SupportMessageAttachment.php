<?php

namespace FlutterSdk\MagicStarter\Models;

use FlutterSdk\MagicStarter\Enums\SupportAttachmentOrigin;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One file stored on a support message.
 *
 * The bytes live on `disk` at `path`, a generated name on a private disk;
 * `original_name` is display text only. The audit trail skips this model: the
 * row carries no `user_id`, so an erasure could not find its audit copies.
 *
 * @property string|int $id
 * @property string|int $conversation_id
 * @property string|int $message_id
 * @property SupportAttachmentOrigin $origin
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime
 * @property int $size Bytes, as stored (after the re-encode of an image).
 * @property int|null $width Pixels; null for a PDF.
 * @property int|null $height Pixels; null for a PDF.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SupportConversation $conversation
 * @property-read SupportMessage $message
 */
class SupportMessageAttachment extends Model
{
    use ConditionallyUsesUuids;

    /** @var list<string> */
    protected $fillable = [
        'conversation_id',
        'message_id',
        'origin',
        'disk',
        'path',
        'original_name',
        'mime',
        'size',
        'width',
        'height',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'origin' => SupportAttachmentOrigin::class,
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<SupportConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SupportConversation::class, 'conversation_id');
    }

    /**
     * @return BelongsTo<SupportMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(SupportMessage::class, 'message_id');
    }
}
