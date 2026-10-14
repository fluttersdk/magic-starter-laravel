<?php

namespace FlutterSdk\MagicStarter\Models;

use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One answer, or dismissal, of the in-app 0-10 feedback card.
 *
 * A null `score` means the customer dismissed the card. The audit trail skips
 * this model: the comment is customer text the trail does not need.
 *
 * @property string|int $id
 * @property string|int $user_id
 * @property string|int|null $team_id
 * @property int|null $score 0 to 10; null when the card was dismissed.
 * @property string|null $comment
 * @property string|int|null $conversation_id The conversation a low score opened or continued.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model $user
 * @property-read SupportConversation|null $conversation
 */
class SupportFeedback extends Model
{
    use ConditionallyUsesUuids;

    /** @var string */
    protected $table = 'support_feedback';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'team_id',
        'score',
        'comment',
        'conversation_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'integer',
        ];
    }

    /**
     * The customer who answered.
     *
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(MagicStarter::userModel(), 'user_id');
    }

    /**
     * The conversation a low score opened or continued, or null.
     *
     * @return BelongsTo<SupportConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SupportConversation::class, 'conversation_id');
    }
}
