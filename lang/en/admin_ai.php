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

    'statuses' => [
        'running' => 'Running',
        'succeeded' => 'Succeeded',
        'failed' => 'Failed',
        'cache_hit' => 'Cache hit',
        'abandoned' => 'Abandoned',
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

    /*
     * The four refusals saving a prompt override raises, each on the body or
     * key field. `missing_phrases` names every phrase the body dropped, because
     * the host's parser or its injection fence depends on them.
     */
    'refusals' => [
        'unknown_key' => 'No prompt is registered as [:key].',
        'blank_body' => 'The prompt body cannot be blank.',
        'default_changed' => 'The code default of this prompt changed while you were editing. '
            . 'Reload it and review the new default before saving.',
        'missing_phrases' => 'The body must keep these phrases: :phrases.',
    ],

    /*
     * The prompt workbench: staff replace the body of a prompt the host registered in code.
     * `stale` means the code default changed after the edit, `incomplete` that the body lacks
     * a required phrase, `orphaned` that the host no longer registers the key.
     */
    'prompts' => [
        'navigation_label' => 'AI prompts',
        'model_label' => 'AI prompt',
        'plural_model_label' => 'AI prompts',

        'columns' => [
            'key' => 'Key',
            'label' => 'Prompt',
            'status' => 'Status',
            'updated_at' => 'Updated',
        ],

        'statuses' => [
            'current' => 'Current',
            'stale' => 'Stale',
            'incomplete' => 'Incomplete',
            'orphaned' => 'Orphaned',
        ],

        'fields' => [
            'key' => 'Prompt',
            'body' => 'Body',
            'diff' => 'Changes against the code default',
            'reviewed' => 'I reviewed the diff against the new code default',
            'default_body' => 'Code default',
            'boundary' => 'Boundary (always appended, not editable)',
        ],

        'diff' => [
            'from' => 'code default',
            'to' => 'override',
            'identical' => 'The body is the same as the code default.',
        ],

        'actions' => [
            'create' => 'Override a prompt',
            'download' => 'Download body',
            'promoted' => [
                'label' => 'Promoted to code, delete',
                'heading' => 'Delete this override?',
                'description' => 'Use this once the body lives in the code default. The prompt answers its code '
                    . 'default again from the next call.',
                'success' => 'Override deleted.',
            ],
        ],
    ],

];
