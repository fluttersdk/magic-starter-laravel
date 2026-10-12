<?php

namespace FlutterSdk\MagicStarter\Models;

use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Enums\SupportConversationStatus;
use FlutterSdk\MagicStarter\Enums\SupportPriority;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use FlutterSdk\MagicStarter\Support\SupportThreadToken;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One customer contact and its thread.
 *
 * The opener (`user_id`) is the only account that may read it; a visitor with
 * no account (`user_id` null) reads it through the public link that
 * {@see SupportThreadToken} mints. `team_id` records the team the opener was in
 * and never widens who may read the thread.
 *
 * The `thread_token_*` columns are deliberately not fillable: only
 * {@see SupportThreadToken} writes them.
 *
 * @property string|int $id
 * @property int $number
 * @property string|int|null $team_id
 * @property string|int|null $user_id
 * @property string|null $email
 * @property string|null $name
 * @property string $subject
 * @property SupportConversationSource $source
 * @property SupportConversationStatus $status
 * @property SupportPriority $priority
 * @property string|int|null $assignee_id
 * @property string|null $handoff_reason
 * @property Carbon|null $waiting_since
 * @property Carbon|null $first_reply_at
 * @property Carbon|null $last_activity_at
 * @property Carbon|null $opener_seen_at
 * @property Carbon|null $staff_seen_at
 * @property Carbon|null $resolved_at
 * @property string|null $thread_token_hash
 * @property int $thread_token_version
 * @property Carbon|null $thread_token_expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model|null $user
 * @property-read Model|null $team
 * @property-read Model|null $assignee
 */
class SupportConversation extends Model
{
    use ConditionallyUsesUuids;

    /** @var list<string> */
    protected $fillable = [
        'team_id',
        'user_id',
        'email',
        'name',
        'subject',
        'source',
        'status',
        'priority',
        'assignee_id',
        'handoff_reason',
        'waiting_since',
        'first_reply_at',
        'last_activity_at',
        'opener_seen_at',
        'staff_seen_at',
        'resolved_at',
    ];

    /**
     * Mirrors the column defaults, so a model fresh from `create()` reads the
     * same as one loaded back.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'open',
        'priority' => 'normal',
        'thread_token_version' => 0,
    ];

    /**
     * Give each new conversation the next display number.
     *
     * Two conversations created at the same instant can read the same maximum;
     * the unique index refuses the second insert rather than letting both carry
     * one number.
     */
    protected static function booted(): void
    {
        static::creating(function (self $conversation): void {
            $conversation->number ??= (int) static::query()->max('number') + 1;
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'source' => SupportConversationSource::class,
            'status' => SupportConversationStatus::class,
            'priority' => SupportPriority::class,
            'waiting_since' => 'datetime',
            'first_reply_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'opener_seen_at' => 'datetime',
            'staff_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
            'thread_token_version' => 'integer',
            'thread_token_expires_at' => 'datetime',
        ];
    }

    /**
     * The opener, or null for a visitor who wrote in without an account.
     *
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(MagicStarter::userModel(), 'user_id');
    }

    /**
     * The team the opener was in when they wrote, or null.
     *
     * @return BelongsTo<Model, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(MagicStarter::teamModel(), 'team_id');
    }

    /**
     * The staff member the conversation is assigned to, or null.
     *
     * @return BelongsTo<Model, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(MagicStarter::userModel(), 'assignee_id');
    }

    /**
     * Every message in the thread, staff notes included. A customer-facing read
     * chains `customerVisible()`.
     *
     * @return HasMany<SupportMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'conversation_id');
    }
}
