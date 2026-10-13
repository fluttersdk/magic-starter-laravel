<?php

namespace FlutterSdk\MagicStarter\Console;

use DateTimeInterface;
use FlutterSdk\MagicStarter\Enums\SupportConversationStatus;
use FlutterSdk\MagicStarter\Enums\SupportHandoffReason;
use FlutterSdk\MagicStarter\Jobs\RespondToSupportConversation;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Models\SupportMessage;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportHandoff;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Schema;

/**
 * Hands every stranded AI turn to staff, so no pending conversation waits forever.
 *
 * A turn is stranded when the conversation is still `pending` and its newest
 * customer message, unanswered, is older than `support.ai.sweep_after_minutes`:
 * the {@see RespondToSupportConversation} job died, timed out past its
 * retries, or never reached a worker. Each is handed off as `unavailable` through
 * {@see SupportHandoff::handoff()} with the turn as its condition, so a job
 * that answers the turn while the sweep runs keeps its answer and the sweep
 * changes nothing.
 *
 * Scheduled every minute by the provider while support is on. A host that has
 * not run the AI columns migration yet is skipped with a line.
 */
class SweepSupportConversationsCommand extends Command
{
    public const NAME = 'magic-starter:support:sweep';

    /**
     * Conversations held in memory at once.
     */
    public const CHUNK_SIZE = 100;

    /**
     * @var string
     */
    protected $signature = self::NAME;

    /**
     * @var string
     */
    protected $description = 'Hand pending support conversations the AI desk left unanswered to staff';

    /**
     * Hand off every stranded turn and print how many.
     */
    public function handle(SupportHandoff $desk): int
    {
        if (! Schema::hasColumn((new SupportConversation)->getTable(), 'last_customer_message_id')) {
            $this->components->info('Skipped the support sweep: the AI desk columns are not migrated.');

            return self::SUCCESS;
        }

        $cutoff = now()->subMinutes((int) config('magic-starter.support.ai.sweep_after_minutes', 5));
        $handedOff = 0;

        $this->stranded($cutoff)
            ->lazyById(self::CHUNK_SIZE)
            ->each(function (SupportConversation $conversation) use ($desk, &$handedOff): void {
                $won = $desk->handoff(
                    $conversation,
                    SupportHandoffReason::UNAVAILABLE,
                    turn: $conversation->last_customer_message_id,
                );

                $handedOff += $won ? 1 : 0;
            });

        $this->components->info(sprintf('Handed %d unanswered conversation(s) to staff.', $handedOff));

        return self::SUCCESS;
    }

    /**
     * Pending conversations whose unanswered newest customer message is older than the cutoff.
     *
     * @return Builder<SupportConversation>
     */
    private function stranded(DateTimeInterface $cutoff): Builder
    {
        $conversations = (new SupportConversation)->getTable();
        $messages = (new SupportMessage)->getTable();

        return SupportConversation::query()
            ->where('status', SupportConversationStatus::PENDING->value)
            ->whereNotNull('last_customer_message_id')
            ->where(function (Builder $unanswered): void {
                $unanswered->whereNull('answered_message_id')
                    ->orWhereColumn('answered_message_id', '!=', 'last_customer_message_id');
            })
            ->whereExists(function (QueryBuilder $trigger) use ($conversations, $messages, $cutoff): void {
                $trigger->from($messages)
                    ->whereColumn("{$messages}.id", "{$conversations}.last_customer_message_id")
                    ->where("{$messages}.created_at", '<=', $cutoff);
            });
    }
}
