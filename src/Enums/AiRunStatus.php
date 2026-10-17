<?php

namespace FlutterSdk\MagicStarter\Enums;

/**
 * Where an AI run stands, as `ai_runs.status` stores it.
 *
 * The values are stored and read back, so a value never changes once shipped.
 */
enum AiRunStatus: string
{
    /** Opened and not yet closed; the hourly prune turns a stale one into ABANDONED. */
    case RUNNING = 'running';

    case SUCCEEDED = 'succeeded';

    case FAILED = 'failed';

    /** Answered from a stored result, so no model was called. */
    case CACHE_HIT = 'cache_hit';

    /** Never closed by its caller, so the prune closed it. */
    case ABANDONED = 'abandoned';
}
