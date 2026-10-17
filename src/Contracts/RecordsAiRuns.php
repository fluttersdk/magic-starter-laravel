<?php

namespace FlutterSdk\MagicStarter\Contracts;

use FlutterSdk\MagicStarter\Support\AiRuns\AiRunAttemptData;
use FlutterSdk\MagicStarter\Support\AiRuns\AiRunOutcome;
use FlutterSdk\MagicStarter\Support\AiRuns\AiRunStart;

/**
 * Records a host application's model calls: one run per logical call, one
 * attempt per provider leg, then the outcome.
 *
 * The host hands over plain values only, so the package never depends on an AI
 * SDK. Recording never breaks the call it records: with the ai-runs feature off
 * or the table missing, `start()` answers null, and a null run key makes
 * `attempt()` and `finish()` no-ops, so the host never branches on the result.
 * Data a column cannot hold (invalid UTF-8, a NUL byte, NAN, an over-long name)
 * is scrubbed or cut, and a database failure, a value JSON cannot encode or a
 * deadlock costs that one row and a log line, never an exception. The one
 * failure that does throw, an extra mask pattern that does not compile, is
 * raised when the application boots rather than on a call.
 */
interface RecordsAiRuns
{
    /**
     * Open a `running` run.
     *
     * @return int|string|null The run's key, or null when nothing was written.
     */
    public function start(AiRunStart $start): int|string|null;

    /**
     * Record a run a host answered from a stored result instead of calling a model.
     *
     * The row is already closed as `cache_hit`: no attempt, every token column 0, no cost, and it links
     * the run that produced the stored answer through `source_run_id`. Like `start()`, it answers null
     * when nothing was written.
     *
     * @param  int|string  $sourceRunId  The key of the run whose answer was served.
     * @return int|string|null The hit's key, or null when nothing was written.
     */
    public function hit(AiRunStart $start, int|string $sourceRunId): int|string|null;

    /**
     * Record one finished provider call inside the run.
     */
    public function attempt(int|string|null $run, AiRunAttemptData $attempt): void;

    /**
     * Close the run, folding provider, model, usage and cost from its attempts.
     */
    public function finish(int|string|null $run, AiRunOutcome $outcome): void;
}
