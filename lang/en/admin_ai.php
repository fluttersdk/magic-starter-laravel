<?php

return [

    /*
     * The AI run log: every model call a host application recorded, newest
     * first, read-only. Retention is the prune command's job.
     */
    'navigation_label' => 'AI runs',
    'model_label' => 'AI run',
    'plural_model_label' => 'AI runs',

    'columns' => [
        'started_at' => 'Started',
        'finished_at' => 'Finished',
        'feature' => 'Feature',
        'source' => 'Prompt source',
        'status' => 'Status',
        'team' => 'Team',
        'subject' => 'Subject',
        'provider' => 'Provider',
        'model' => 'Model',
        'attempts_count' => 'Attempts',
        'input_tokens' => 'Input tokens',
        'output_tokens' => 'Output tokens',
        'cache_read_tokens' => 'Cache read tokens',
        'cache_write_tokens' => 'Cache write tokens',
        'reasoning_tokens' => 'Reasoning tokens',
        'cost_usd' => 'Cost (USD)',
        'cost_source' => 'Cost source',
        'duration_ms' => 'Duration (ms)',
        'error_class' => 'Error class',
        'prompt_hash' => 'Prompt hash',
        'source_run_id' => 'Reused run',
    ],

    'filters' => [
        'feature' => 'Feature',
        'status' => 'Status',
        'team' => 'Team',
        'started_from' => 'From',
        'started_until' => 'Until',
    ],

    'view' => [
        'metadata' => 'Metadata',
        'bodies' => 'Bodies',
        'bodies_pruned' => 'Bodies pruned on :date.',
        'instructions' => 'Instructions',
        'input' => 'Input',
        'context' => 'Context',
        'tools' => 'Tools',
        'output' => 'Output',
        'structured' => 'Structured output',
        'error_message' => 'Error',
        'attempts' => 'Attempts',
    ],

    'attempts' => [
        'attempt' => '#',
        'http_status' => 'HTTP status',
        'tool_calls' => 'Tool calls',
        'error' => 'Error',
    ],

    'widget' => [
        'runs' => 'AI runs (last :days days)',
        'failed' => 'Failed share (last :days days)',
        'cost' => 'AI cost (last :days days)',
        'cache_hit_rate' => 'Cache hit rate (last :days days)',
    ],

];
