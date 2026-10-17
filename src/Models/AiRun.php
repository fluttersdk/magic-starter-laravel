<?php

namespace FlutterSdk\MagicStarter\Models;

use FlutterSdk\MagicStarter\Enums\AiRunStatus;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One model call a host application recorded: which feature made it, how it
 * ended ({@see AiRunStatus}), what it cost, and, until the prune blanks them,
 * the bodies that went in and came out.
 *
 * Only the recorder writes this table, with values the host already holds, so
 * the model validates nothing. `team_id` and `subject_*` are plain strings with
 * no foreign key, so a run outlives the team and the subject it names and the
 * relations then read null. `cost_usd` reads back as a decimal string, never a
 * float, to keep eight places exact.
 *
 * @property string|int $id
 * @property string|null $team_id
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string $feature
 * @property string $source
 * @property string|null $prompt_hash
 * @property AiRunStatus $status
 * @property string|int|null $source_run_id
 * @property string|null $provider
 * @property string|null $model
 * @property int $attempts_count
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property int|null $cache_read_tokens
 * @property int|null $cache_write_tokens
 * @property int|null $reasoning_tokens
 * @property string|null $cost_usd
 * @property string|null $cost_source
 * @property int|null $duration_ms
 * @property string|null $error_class
 * @property string|null $instructions
 * @property string|null $input
 * @property array<string, mixed>|null $context
 * @property array<int, mixed>|null $tools
 * @property string|null $output
 * @property array<string, mixed>|null $structured
 * @property string|null $error_message
 * @property Carbon|null $bodies_pruned_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model|null $team
 * @property-read Model|null $subject
 * @property-read AiRun|null $sourceRun
 * @property-read Collection<int, AiRunAttempt> $attempts
 */
class AiRun extends Model
{
    use ConditionallyUsesUuids;

    protected $table = 'ai_runs';

    /** @var list<string> */
    protected $fillable = [
        'team_id',
        'subject_type',
        'subject_id',
        'feature',
        'source',
        'prompt_hash',
        'status',
        'source_run_id',
        'provider',
        'model',
        'attempts_count',
        'input_tokens',
        'output_tokens',
        'cache_read_tokens',
        'cache_write_tokens',
        'reasoning_tokens',
        'cost_usd',
        'cost_source',
        'duration_ms',
        'error_class',
        'instructions',
        'input',
        'context',
        'tools',
        'output',
        'structured',
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
            'context' => 'array',
            'tools' => 'array',
            'structured' => 'array',
            'cost_usd' => 'decimal:8',
            'bodies_pruned_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * The provider calls inside this run, first attempt first.
     *
     * @return HasMany<AiRunAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(AiRunAttempt::class, 'ai_run_id')->orderBy('attempt');
    }

    /**
     * The team the call was made for, or null when it had none or that team was deleted.
     *
     * @return BelongsTo<Model, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(MagicStarter::teamModel(), 'team_id');
    }

    /**
     * The record the call was about, resolved from `subject_type` as it was
     * written, or null when the run had none or that record was deleted.
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The run this override replaced, or null for a code-sourced run or once that run was deleted.
     *
     * @return BelongsTo<AiRun, $this>
     */
    public function sourceRun(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_run_id');
    }
}
