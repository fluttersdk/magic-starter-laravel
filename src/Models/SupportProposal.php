<?php

namespace FlutterSdk\MagicStarter\Models;

use FlutterSdk\MagicStarter\Enums\SupportProposalAction;
use FlutterSdk\MagicStarter\Enums\SupportProposalStatus;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One change to a customer's subscription that the account owner asked the AI
 * desk for inside a support conversation, waiting for a staff member to approve
 * or reject it. The model only records it; staff approval is what runs it.
 *
 * `billable_type` and `billable_id` name the account the change would touch
 * (`getMorphClass()` and the key) and have no foreign key. `params` is the
 * action's own input, such as `['until' => '2026-11-01']` for a trial extension.
 *
 * @property string|int $id
 * @property string|int $conversation_id
 * @property string $billable_type
 * @property string|int $billable_id
 * @property string|int|null $requested_by The account owner who asked for the change.
 * @property SupportProposalAction $action
 * @property array<string, mixed> $params
 * @property string|null $rationale Why the proposer suggested it; shown beside the approval.
 * @property string|int|null $trigger_message_id The customer message this proposal answers.
 * @property SupportProposalStatus $status
 * @property Carbon $expires_at Past this instant an open row can no longer be approved.
 * @property string|int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SupportConversation $conversation
 * @property-read SupportMessage|null $triggerMessage
 * @property-read Model|null $requester
 * @property-read Model|null $decider
 */
class SupportProposal extends Model
{
    use ConditionallyUsesUuids;

    /** @var list<string> */
    protected $fillable = [
        'conversation_id',
        'billable_type',
        'billable_id',
        'requested_by',
        'action',
        'params',
        'rationale',
        'trigger_message_id',
        'status',
        'expires_at',
        'decided_by',
        'decided_at',
        'decision_reason',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => SupportProposalAction::class,
            'params' => 'array',
            'status' => SupportProposalStatus::class,
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * Turn the drafts a delivered AI turn wrote into pending proposals.
     *
     * A draft stays hidden while its turn runs, so a turn that fails or is
     * superseded never shows a proposal for an answer the customer did not get.
     * Only the drafts of this conversation written for this trigger message move.
     *
     * @param  string|int  $triggerId  The customer message the turn answered.
     * @return int How many drafts were promoted.
     */
    public static function promoteDrafts(SupportConversation $conversation, string|int $triggerId): int
    {
        return static::query()
            ->where('conversation_id', $conversation->getKey())
            ->where('trigger_message_id', $triggerId)
            ->where('status', SupportProposalStatus::DRAFT->value)
            ->update([
                'status' => SupportProposalStatus::PENDING->value,
            ]);
    }

    /**
     * Whether the deadline passed while the proposal was still open.
     *
     * A decided row is never expired, whatever its `expires_at` says.
     */
    public function isExpired(): bool
    {
        return $this->status->isOpen() && $this->expires_at->lte(now());
    }

    /**
     * The proposals that can still be shown or decided: a draft or a pending row before its deadline.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [
                SupportProposalStatus::DRAFT->value,
                SupportProposalStatus::PENDING->value,
            ])
            ->where('expires_at', '>', now());
    }

    /**
     * @return BelongsTo<SupportConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SupportConversation::class, 'conversation_id');
    }

    /**
     * The customer message this proposal answers, or null once it is gone.
     *
     * @return BelongsTo<SupportMessage, $this>
     */
    public function triggerMessage(): BelongsTo
    {
        return $this->belongsTo(SupportMessage::class, 'trigger_message_id');
    }

    /**
     * The account owner who asked for it, or null once the account is deleted.
     *
     * @return BelongsTo<Model, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(MagicStarter::userModel(), 'requested_by');
    }

    /**
     * The staff member who approved or rejected it, or null while open and once the account is deleted.
     *
     * @return BelongsTo<Model, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(MagicStarter::userModel(), 'decided_by');
    }
}
