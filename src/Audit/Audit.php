<?php

namespace FlutterSdk\MagicStarter\Audit;

use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One audit row: what happened to which subject, who did it, and the values
 * before and after. Rows are written once and never updated, which is why the
 * table carries `created_at` only.
 *
 * @property int|string $id
 * @property string $event
 * @property string|null $auditable_type
 * @property string|null $auditable_id
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property string|null $related_user_id
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property array<string, mixed>|null $context
 * @property Carbon|null $created_at
 */
class Audit extends Model
{
    use ConditionallyUsesUuids;

    public const UPDATED_AT = null;

    protected $table = 'magic_starter_audits';

    protected $fillable = [
        'event',
        'auditable_type',
        'auditable_id',
        'actor_type',
        'actor_id',
        'related_user_id',
        'old_values',
        'new_values',
        'context',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'context' => 'array',
        ];
    }

    /**
     * The user who acted, matched on `actor_id` alone.
     *
     * The key is shared with every other actor type, so the result means
     * something only while {@see actedByUser()} holds; read it through that.
     *
     * @return BelongsTo<Model, $this>
     */
    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(MagicStarter::userModel(), 'actor_id');
    }

    /**
     * The user the subject belongs to, when the subject has one.
     *
     * @return BelongsTo<Model, $this>
     */
    public function relatedUser(): BelongsTo
    {
        return $this->belongsTo(MagicStarter::userModel(), 'related_user_id');
    }

    /**
     * Whether the actor recorded on the row is of the configured user model.
     *
     * Still true after that user was deleted: the row keeps `actor_type` and
     * loses `actor_id`, so {@see actorUser} is then null.
     */
    public function actedByUser(): bool
    {
        $userModel = MagicStarter::userModel();

        return $this->actor_type === (new $userModel)->getMorphClass();
    }
}
