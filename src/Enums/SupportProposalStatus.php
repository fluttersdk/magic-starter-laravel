<?php

namespace FlutterSdk\MagicStarter\Enums;

/**
 * Where a support proposal stands, as `support_proposals.status` stores it.
 *
 * The values are stored and read back, so a value never changes once shipped.
 */
enum SupportProposalStatus: string
{
    /** Written during an AI turn; hidden from staff until that turn is delivered. */
    case DRAFT = 'draft';

    /** Shown to staff, waiting for a decision until `expires_at`. */
    case PENDING = 'pending';

    /** Claimed by the approval that is running the action; no second approval may start. */
    case EXECUTING = 'executing';

    /** A staff member approved it and the action ran. */
    case APPROVED = 'approved';

    /** A staff member declined it. */
    case REJECTED = 'rejected';

    /** Approval could not complete: the action was refused, or the request no longer holds. */
    case FAILED = 'failed';

    /** The deadline passed with no decision. */
    case EXPIRED = 'expired';

    /**
     * Whether the proposal still waits to be shown or decided: a draft or a pending row.
     */
    public function isOpen(): bool
    {
        return $this === self::DRAFT || $this === self::PENDING;
    }
}
