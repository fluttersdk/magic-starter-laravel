<?php

namespace FlutterSdk\MagicStarter\Support\SupportAi;

use FlutterSdk\MagicStarter\Jobs\PrepareSupportStaffAssist;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;

/**
 * Where a staff assist waits for the panel: the summary and the draft
 * {@see PrepareSupportStaffAssist} stored for one turn of one conversation.
 *
 * A turn is the conversation's newest customer message
 * (`last_customer_message_id`), so a customer writing again makes the stored
 * assist a miss without anything deleting it. The panel only ever reads here:
 * a read never calls the model, and a miss simply shows no summary and an empty
 * draft.
 */
final class SupportStaffAssistCache
{
    /**
     * Days a stored assist stays readable; a stale one is rarely opened again.
     */
    private const TTL_DAYS = 3;

    public function __construct(
        private readonly Repository $cache,
    ) {}

    /**
     * The assist stored for the conversation's current turn, or null on a miss.
     *
     * @return array{summary: string, draft: string, at: Carbon}|null
     */
    public function get(SupportConversation $conversation): ?array
    {
        $stored = $this->cache->get($this->key($conversation));

        if (! is_array($stored)) {
            return null;
        }

        return [
            'summary' => (string) $stored['summary'],
            'draft' => (string) $stored['draft'],
            'at' => Carbon::createFromTimestamp((int) $stored['at']),
        ];
    }

    public function put(SupportConversation $conversation, SupportStaffAssist $assist): void
    {
        $this->cache->put($this->key($conversation), [
            'summary' => $assist->summary,
            'draft' => $assist->draft,
            'at' => now()->getTimestamp(),
        ], now()->addDays(self::TTL_DAYS));
    }

    /**
     * Take the right to queue this turn's assist; false while one is already queued or running.
     *
     * Held for the job's timeout at most, so a job that died without releasing
     * it cannot block a retry for longer than the job could have run.
     */
    public function claim(SupportConversation $conversation): bool
    {
        return $this->cache->add(
            $this->key($conversation) . ':queued',
            true,
            now()->addSeconds((int) config('magic-starter.support.ai.job_timeout', 90)),
        );
    }

    public function release(SupportConversation $conversation): void
    {
        $this->cache->forget($this->key($conversation) . ':queued');
    }

    private function key(SupportConversation $conversation): string
    {
        return sprintf(
            'magic-starter:support-assist:%s:%s',
            $conversation->getKey(),
            $conversation->last_customer_message_id ?? 'none',
        );
    }
}
