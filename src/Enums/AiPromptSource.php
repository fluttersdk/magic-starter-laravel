<?php

namespace FlutterSdk\MagicStarter\Enums;

/**
 * Where the prompt of an AI run came from, as `ai_runs.source` stores it.
 *
 * The values are stored and read back, so a value never changes once shipped.
 */
enum AiPromptSource: string
{
    /** The prompt the host ships in its own code. */
    case CODE = 'code';

    /** A prompt someone replaced at runtime, so the run is not the code's own behaviour. */
    case OVERRIDE = 'override';
}
