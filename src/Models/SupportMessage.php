<?php

namespace FlutterSdk\MagicStarter\Models;

use FlutterSdk\MagicStarter\Enums\SupportMessageKind;
use FlutterSdk\MagicStarter\Enums\SupportSenderType;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One entry in a support thread: a reply, a staff note or an activity line.
 *
 * @property string|int $id
 * @property string|int $conversation_id
 * @property SupportSenderType $sender_type
 * @property string|int|null $sender_id
 * @property SupportMessageKind $kind
 * @property string $body
 * @property array<string, mixed>|null $meta AI desk record (confidence, reason, tokens); never customer JSON.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SupportConversation $conversation
 * @property-read Model|null $sender
 */
class SupportMessage extends Model
{
    use ConditionallyUsesUuids;

    /**
     * The kinds a customer may see. Listed rather than excluding the note, so a
     * kind added later stays hidden until someone decides it is customer-visible.
     *
     * @var list<SupportMessageKind>
     */
    public const CUSTOMER_VISIBLE_KINDS = [
        SupportMessageKind::MESSAGE,
        SupportMessageKind::ACTIVITY,
    ];

    /** @var list<string> */
    protected $fillable = [
        'conversation_id',
        'sender_type',
        'sender_id',
        'kind',
        'body',
        'meta',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sender_type' => SupportSenderType::class,
            'kind' => SupportMessageKind::class,
            'meta' => 'array',
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
     * The user who wrote it, or null for a visitor on the public link, for the
     * system, and once the sender's account is deleted.
     *
     * @return BelongsTo<Model, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(MagicStarter::userModel(), 'sender_id');
    }

    /**
     * The messages a customer may see: everything but staff notes.
     *
     * The single source of that rule; every customer-facing read goes through
     * it, through {@see self::CUSTOMER_VISIBLE_KINDS}.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCustomerVisible(Builder $query): Builder
    {
        return $query->whereIn('kind', array_map(
            static fn (SupportMessageKind $kind): string => $kind->value,
            self::CUSTOMER_VISIBLE_KINDS,
        ));
    }

    /**
     * Whether this loaded message is one a customer may see; the in-memory twin of `customerVisible()`.
     */
    public function isCustomerVisible(): bool
    {
        return in_array($this->kind, self::CUSTOMER_VISIBLE_KINDS, true);
    }
}
