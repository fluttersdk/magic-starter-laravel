<?php

namespace FlutterSdk\MagicStarter\Models;

use FlutterSdk\MagicStarter\Enums\AiCostSource;
use FlutterSdk\MagicStarter\Enums\AiRunStatus;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One provider call inside an {@see AiRun}: a retry or a fallback keeps its own
 * status, usage and cost here instead of being folded into the run's totals.
 *
 * `attempt` counts from 1 and is unique within the run. The row goes with its
 * run. `cost_usd` reads back as a decimal string.
 *
 * @property string|int $id
 * @property string|int $ai_run_id
 * @property int $attempt
 * @property string $provider
 * @property string|null $model
 * @property AiRunStatus $status
 * @property int|null $http_status
 * @property string|null $finish_reason
 * @property string|null $error_class
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property int|null $cache_read_tokens
 * @property int|null $cache_write_tokens
 * @property int|null $reasoning_tokens
 * @property string|null $cost_usd
 * @property AiCostSource|null $cost_source
 * @property int|null $duration_ms
 * @property array<int, mixed>|null $tool_calls
 * @property string|null $error_message
 * @property Carbon|null $bodies_pruned_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AiRunAttempt extends Model
{
    use ConditionallyUsesUuids;

    protected $table = 'ai_run_attempts';

    /** @var list<string> */
    protected $fillable = [
        'ai_run_id',
        'attempt',
        'provider',
        'model',
        'status',
        'http_status',
        'finish_reason',
        'error_class',
        'input_tokens',
        'output_tokens',
        'cache_read_tokens',
        'cache_write_tokens',
        'reasoning_tokens',
        'cost_usd',
        'cost_source',
        'duration_ms',
        'tool_calls',
        'error_message',
        'bodies_pruned_at',
        'started_at',
        'finished_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AiRunStatus::class,
            'cost_source' => AiCostSource::class,
            'tool_calls' => 'array',
            'cost_usd' => 'decimal:8',
            'bodies_pruned_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
