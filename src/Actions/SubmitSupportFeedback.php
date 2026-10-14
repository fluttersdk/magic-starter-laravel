<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Audit\Auditor;
use FlutterSdk\MagicStarter\Contracts\PostsCustomerSupportMessages;
use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Enums\SupportConversationStatus;
use FlutterSdk\MagicStarter\Enums\SupportPriority;
use FlutterSdk\MagicStarter\Events\SupportConversationNeedsStaff;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Models\SupportFeedback;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportAiQuota;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Lang;

/**
 * Records the answer, or the dismissal, of the in-app 0-10 feedback card.
 *
 * Only a verified, non-guest account takes part, the same bar as the AI desk.
 * {@see self::eligible()} adds the pacing: the account is at least
 * `support.feedback.min_account_days` old and has not answered or dismissed
 * the card within `interval_days`. A write is not paced, so a stale card
 * still lands.
 *
 * A score up to `low_score_max` continues the account's open feedback
 * conversation or opens one at high priority; the AI desk never answers it,
 * since its source is not one the desk takes. At most `low_score_daily` new
 * ones are opened per account per day, after which a low score is kept with
 * no conversation unless one is still open to continue. Each such score alerts
 * staff with the reason `feedback`.
 */
class SubmitSupportFeedback
{
    public function __construct(
        private readonly OpenSupportConversation $opener,
        private readonly PostsCustomerSupportMessages $messages,
        private readonly SupportAiQuota $gate,
    ) {}

    /**
     * Whether the card may be shown to this account now.
     */
    public function eligible(Model&Authenticatable $user): bool
    {
        if (! $this->gate->eligible($user)) {
            return false;
        }

        $createdAt = $user->getAttribute('created_at');
        $oldEnough = $createdAt instanceof Carbon
            && $createdAt->lte(now()->subDays((int) config('magic-starter.support.feedback.min_account_days', 14)));

        return $oldEnough && ! SupportFeedback::query()
            ->where('user_id', $user->getKey())
            ->where('created_at', '>', now()->subDays((int) config('magic-starter.support.feedback.interval_days', 90)))
            ->exists();
    }

    /**
     * Record the answer and, for a low score, open or continue the feedback conversation.
     *
     * @param  int|null  $score  0 to 10, or null for a dismissal
     * @return SupportConversation|null the conversation a low score opened or continued, null when there is none
     *
     * @throws AuthorizationException for a guest or an unverified email
     */
    public function submit(Model&Authenticatable $user, ?int $score, ?string $comment): ?SupportConversation
    {
        // 1. The same bar as the AI desk: a guest or an unverified address has no standing to answer.
        if (! $this->gate->eligible($user)) {
            throw new AuthorizationException;
        }

        // 2. A dismissal and a high score keep their row too, so the card is paced by every answer.
        $teamId = $user->getAttribute('current_team_id');
        $conversation = $score !== null && $score <= (int) config('magic-starter.support.feedback.low_score_max', 6)
            ? $this->fileLowScore($user, $teamId, $score, $comment)
            : null;

        SupportFeedback::query()->create([
            'user_id' => $user->getKey(),
            'team_id' => $teamId,
            'score' => $score,
            'comment' => $comment,
            'conversation_id' => $conversation?->getKey(),
        ]);

        return $conversation;
    }

    /**
     * Continue the open feedback conversation, or open one while the day's allowance lasts.
     */
    private function fileLowScore(
        Model&Authenticatable $user,
        mixed $teamId,
        int $score,
        ?string $comment,
    ): ?SupportConversation {
        $source = SupportConversationSource::FEEDBACK;
        $body = $comment ?? Lang::get('magic-starter::support.feedback.no_comment', ['score' => $score]);

        // 1. Over the day's allowance, a low score may still continue a thread but never opens another.
        if ($this->openedToday($user) >= (int) config('magic-starter.support.feedback.low_score_daily', 1)
            && $this->opener->continuable($user, $teamId, $source) === null) {
            return null;
        }

        // 2. Continue or create under the opener's row lock; the owner fields come from the account.
        [$conversation, $continued] = DB::transaction(fn (): array => $this->opener->continueOrCreate(
            $user,
            $teamId,
            [
                'user_id' => $user->getKey(),
                'team_id' => $teamId,
                'email' => $user->getAttribute('email'),
                'name' => $user->getAttribute('name'),
                'topic' => null,
                'subject' => Lang::get('magic-starter::support.feedback.subject', ['score' => $score]),
                'source' => $source,
                'status' => SupportConversationStatus::OPEN,
                'priority' => SupportPriority::HIGH,
            ],
            $body,
            [],
            $source,
        ));

        // 3. A continued thread takes the score as one more customer message,
        //    and that post alerts staff once per wait on its own; a second
        //    `feedback` alert would page them twice for one score.
        if ($continued) {
            DB::transaction(fn () => $this->messages->post($conversation, $body, $user));

            return $conversation;
        }

        Auditor::record(
            'support.conversation_opened',
            $conversation,
            [
                'number' => $conversation->number,
                'source' => $source->value,
            ],
            $user,
        );

        // 4. A new feedback conversation alerts staff once.
        Event::dispatch(new SupportConversationNeedsStaff($conversation, 'feedback'));

        return $conversation;
    }

    /**
     * How many feedback conversations the account has had opened since midnight.
     */
    private function openedToday(Model $user): int
    {
        return SupportConversation::query()
            ->where('user_id', $user->getKey())
            ->where('source', SupportConversationSource::FEEDBACK->value)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();
    }
}
