<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Enums\SupportConversationStatus;
use FlutterSdk\MagicStarter\Enums\SupportCsatRating;
use FlutterSdk\MagicStarter\Events\SupportConversationNeedsStaff;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;

/**
 * Stores the opener's thumbs rating of a resolved conversation.
 *
 * The caller has already proved the conversation is the opener's. Only a
 * resolved conversation is rated, and it can be rated again until it is
 * reopened and resolved anew: a new rating overwrites the old one, and
 * `csat_assignee_id` keeps who held the conversation at that moment.
 *
 * A thumbs-down tells staff once per resolution: the row is locked, so two
 * ratings arriving together cannot both find `csat_alerted_at` unset, and a
 * rating that follows a newer resolution than the last alert alerts again.
 * Changing the vote within one resolution never alerts a second time.
 */
class RateSupportConversation
{
    /**
     * Rate the conversation and return it as stored.
     *
     * @throws ValidationException when the conversation is not resolved
     */
    public function rate(
        SupportConversation $conversation,
        SupportCsatRating $rating,
        ?string $comment,
    ): SupportConversation {
        // 1. Decide from the locked row, so the status and the alert stamp are read where they are written.
        [$rated, $alert] = DB::transaction(function () use ($conversation, $rating, $comment): array {
            $locked = SupportConversation::query()->lockForUpdate()->findOrFail($conversation->getKey());

            if ($locked->status !== SupportConversationStatus::RESOLVED) {
                throw ValidationException::withMessages([
                    'rating' => Lang::get('magic-starter::support.rating.not_resolved'),
                ]);
            }

            $alert = $rating === SupportCsatRating::DOWN
                && ($locked->csat_alerted_at === null || $locked->csat_alerted_at->lt($locked->resolved_at ?? now()));

            $locked->forceFill([
                'csat_rating' => $rating,
                'csat_comment' => $comment,
                'csat_rated_at' => now(),
                'csat_assignee_id' => $locked->assignee_id,
                ...($alert ? ['csat_alerted_at' => now()] : []),
            ])->save();

            return [
                $locked,
                $alert,
            ];
        });

        // 2. Tell staff after the commit, so nobody is alerted about a rating that rolled back.
        if ($alert) {
            Event::dispatch(new SupportConversationNeedsStaff($rated, 'negative_rating'));
        }

        return $rated;
    }
}
