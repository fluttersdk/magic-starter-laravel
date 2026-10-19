<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\AiRuns\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Facades\FilamentTimezone;
use FlutterSdk\MagicStarter\Enums\AiCostSource;
use FlutterSdk\MagicStarter\Enums\AiPromptSource;
use FlutterSdk\MagicStarter\Enums\AiRunStatus;
use FlutterSdk\MagicStarter\Filament\Infolists\CodeBlockEntry;
use FlutterSdk\MagicStarter\Filament\Resources\AiRuns\AiRunResource;
use FlutterSdk\MagicStarter\Models\AiRun;
use FlutterSdk\MagicStarter\Models\AiRunAttempt;

/**
 * The run page: its metadata, the bodies that went in and came out, and one row
 * per provider call.
 *
 * Every body is null once the prune has blanked it, so each entry shows only
 * while it holds something and the section says when the bodies were pruned.
 * Every string reaches the page escaped.
 */
final class AiRunInfolist
{
    /**
     * The bodies, one tab each, in the order the tabs list them: what came
     * back first, then what was sent, then what it was sent with.
     */
    private const BODIES = ['output', 'structured', 'error_message', 'input', 'instructions', 'context', 'tools'];

    /**
     * The attempt table's columns, each with the translation key of its header and cell label.
     */
    private const ATTEMPT_LABELS = [
        'attempt' => 'attempts.attempt',
        'provider' => 'columns.provider',
        'model' => 'columns.model',
        'status' => 'columns.status',
        'http_status' => 'attempts.http_status',
        'input_tokens' => 'columns.input_tokens',
        'output_tokens' => 'columns.output_tokens',
        'cost_usd' => 'columns.cost_usd',
        'duration_ms' => 'columns.duration_ms',
        'tool_calls' => 'attempts.tool_calls',
        'error' => 'attempts.error',
    ];

    /**
     * Columns whose value must not wrap or clip: eleven columns share the row,
     * and the status badge was cut to "Succeede" and the provider and model
     * names split mid-word. Every header wraps, so the figures' headers give
     * these the room.
     */
    private const ATTEMPT_WIDTHS = [
        'provider' => '7rem',
        'model' => '12rem',
        'status' => '7.5rem',
        'cost_usd' => '7.5rem',
        'tool_calls' => '11rem',
    ];

    /**
     * Every section spans the page, so the bodies and the attempts get the full width.
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            self::metadata(),
            self::bodies(),
            self::attempts(),
            self::toolCalls(),
        ]);
    }

    /**
     * Each attempt's tool calls in full, below the attempts table: a JSON block in a table cell is unreadable at
     * a column's width, so the table names the tools and this section shows their arguments and results.
     */
    private static function toolCalls(): Section
    {
        return Section::make(self::attemptLabel('tool_calls'))
            ->columnSpanFull()
            ->collapsible()
            ->visible(static fn (AiRun $record): bool => $record->attempts
                ->contains(static fn (AiRunAttempt $attempt): bool => filled($attempt->tool_calls)))
            ->schema([
                RepeatableEntry::make('attempts')
                    ->hiddenLabel()
                    ->contained(false)
                    ->schema([
                        CodeBlockEntry::make('tool_calls')
                            ->label(static fn (AiRunAttempt $record): string => "#{$record->attempt} {$record->provider}")
                            ->state(static fn (AiRunAttempt $record): mixed => $record->tool_calls)
                            ->visible(static fn (AiRunAttempt $record): bool => filled($record->tool_calls)),
                    ]),
            ]);
    }

    /**
     * The tools an attempt called, in call order and each once, or null when it called none.
     */
    private static function toolNames(AiRunAttempt $attempt): ?string
    {
        $names = array_values(array_unique(array_filter(array_map(
            static fn (mixed $call): ?string => is_array($call) && is_string($call['name'] ?? null)
                ? $call['name']
                : null,
            (array) $attempt->tool_calls,
        ))));

        return $names === [] ? null : implode(', ', $names);
    }

    private static function metadata(): Section
    {
        return Section::make(__('magic-starter::admin_ai.view.metadata'))
            ->columnSpanFull()
            ->columns([
                'default' => 1,
                'md' => 2,
                'xl' => 3,
            ])
            ->schema([
                TextEntry::make('feature')
                    ->label(__('magic-starter::admin_ai.columns.feature')),
                TextEntry::make('status')
                    ->label(__('magic-starter::admin_ai.columns.status'))
                    ->badge()
                    ->formatStateUsing(static fn (AiRunStatus $state): string => AiRunResource::statusLabel($state))
                    ->color(static fn (AiRunStatus $state): string => AiRunResource::statusColor($state)),
                TextEntry::make('source')
                    ->label(__('magic-starter::admin_ai.columns.source'))
                    ->formatStateUsing(static fn (AiPromptSource $state): string => $state->value),
                TextEntry::make('team')
                    ->label(__('magic-starter::admin_ai.columns.team'))
                    ->state(static fn (AiRun $record): ?string => AiRunResource::teamLabel($record))
                    ->tooltip(static fn (AiRun $record): ?string => $record->team_id)
                    ->placeholder(AiRunResource::ABSENT),
                TextEntry::make('subject')
                    ->label(__('magic-starter::admin_ai.columns.subject'))
                    ->state(static fn (AiRun $record): ?string => self::subjectLabel($record))
                    ->tooltip(static fn (AiRun $record): ?string => $record->subject_type)
                    ->placeholder(AiRunResource::ABSENT),
                TextEntry::make('provider')
                    ->label(__('magic-starter::admin_ai.columns.provider'))
                    ->placeholder(AiRunResource::ABSENT),
                TextEntry::make('model')
                    ->label(__('magic-starter::admin_ai.columns.model'))
                    ->placeholder(AiRunResource::ABSENT),
                TextEntry::make('attempts_count')
                    ->label(__('magic-starter::admin_ai.columns.attempts_count'))
                    ->numeric(),
                TextEntry::make('started_at')
                    ->label(__('magic-starter::admin_ai.columns.started_at'))
                    ->dateTime()
                    ->sinceTooltip()
                    ->placeholder(AiRunResource::ABSENT),
                TextEntry::make('finished_at')
                    ->label(__('magic-starter::admin_ai.columns.finished_at'))
                    ->dateTime()
                    ->placeholder(AiRunResource::ABSENT),
                TextEntry::make('duration_ms')
                    ->label(__('magic-starter::admin_ai.columns.duration_ms'))
                    ->numeric()
                    ->placeholder(AiRunResource::ABSENT),
                TextEntry::make('input_tokens')
                    ->label(__('magic-starter::admin_ai.columns.input_tokens'))
                    ->numeric()
                    ->placeholder(AiRunResource::ABSENT),
                TextEntry::make('output_tokens')
                    ->label(__('magic-starter::admin_ai.columns.output_tokens'))
                    ->numeric()
                    ->placeholder(AiRunResource::ABSENT),
                TextEntry::make('cache_read_tokens')
                    ->label(__('magic-starter::admin_ai.columns.cache_read_tokens'))
                    ->numeric()
                    ->placeholder(AiRunResource::ABSENT),
                TextEntry::make('cache_write_tokens')
                    ->label(__('magic-starter::admin_ai.columns.cache_write_tokens'))
                    ->numeric()
                    ->placeholder(AiRunResource::ABSENT),
                TextEntry::make('reasoning_tokens')
                    ->label(__('magic-starter::admin_ai.columns.reasoning_tokens'))
                    ->numeric()
                    ->placeholder(AiRunResource::ABSENT),
                TextEntry::make('cost_usd')
                    ->label(__('magic-starter::admin_ai.columns.cost_usd'))
                    // Filament shows the placeholder for a null state and never formats it.
                    ->formatStateUsing(static fn (string $state): string => AiRunResource::usd($state, 6))
                    ->placeholder(AiRunResource::ABSENT),
                TextEntry::make('cost_source')
                    ->label(__('magic-starter::admin_ai.columns.cost_source'))
                    ->formatStateUsing(static fn (AiCostSource $state): string => $state->value)
                    ->placeholder(AiRunResource::ABSENT),
                TextEntry::make('error_class')
                    ->label(__('magic-starter::admin_ai.columns.error_class'))
                    ->fontFamily(FontFamily::Mono)
                    ->extraAttributes(AiRunResource::WRAP_ANYWHERE)
                    ->placeholder(AiRunResource::ABSENT),
                TextEntry::make('prompt_hash')
                    ->label(__('magic-starter::admin_ai.columns.prompt_hash'))
                    ->fontFamily(FontFamily::Mono)
                    ->copyable()
                    ->extraAttributes(AiRunResource::WRAP_ANYWHERE)
                    ->placeholder(AiRunResource::ABSENT),
                TextEntry::make('source_run_id')
                    ->label(__('magic-starter::admin_ai.columns.source_run_id'))
                    ->fontFamily(FontFamily::Mono)
                    ->copyable()
                    ->extraAttributes(AiRunResource::WRAP_ANYWHERE)
                    ->placeholder(AiRunResource::ABSENT),
            ]);
    }

    private static function bodies(): Section
    {
        return Section::make(__('magic-starter::admin_ai.view.bodies'))
            ->description(static fn (AiRun $record): ?string => self::prunedNote($record))
            ->columnSpanFull()
            ->collapsible()
            ->visible(static fn (AiRun $record): bool => $record->bodies_pruned_at !== null
                || self::hasBodies($record))
            ->schema([
                Tabs::make('bodies')
                    ->hiddenLabel()
                    ->contained(false)
                    ->visible(static fn (AiRun $record): bool => self::hasBodies($record))
                    ->tabs(array_map(self::bodyTab(...), self::BODIES)),
            ]);
    }

    private static function attempts(): Section
    {
        return Section::make(__('magic-starter::admin_ai.view.attempts'))
            ->columnSpanFull()
            ->visible(static fn (AiRun $record): bool => $record->attempts->isNotEmpty())
            ->schema([
                RepeatableEntry::make('attempts')
                    ->hiddenLabel()
                    ->table(array_map(
                        static fn (string $column): TableColumn => TableColumn::make(self::attemptLabel($column))
                            ->wrapHeader()
                            ->width(self::ATTEMPT_WIDTHS[$column] ?? null),
                        array_keys(self::ATTEMPT_LABELS),
                    ))
                    ->schema([
                        TextEntry::make('attempt')
                            ->label(self::attemptLabel('attempt')),
                        TextEntry::make('provider')
                            ->label(self::attemptLabel('provider')),
                        TextEntry::make('model')
                            ->label(self::attemptLabel('model'))
                            ->placeholder(AiRunResource::ABSENT),
                        TextEntry::make('status')
                            ->label(self::attemptLabel('status'))
                            ->badge()
                            ->formatStateUsing(
                                static fn (AiRunStatus $state): string => AiRunResource::statusLabel($state),
                            )
                            ->color(static fn (AiRunStatus $state): string => AiRunResource::statusColor($state)),
                        TextEntry::make('http_status')
                            ->label(self::attemptLabel('http_status'))
                            ->placeholder(AiRunResource::ABSENT),
                        TextEntry::make('input_tokens')
                            ->label(self::attemptLabel('input_tokens'))
                            ->numeric()
                            ->placeholder(AiRunResource::ABSENT),
                        TextEntry::make('output_tokens')
                            ->label(self::attemptLabel('output_tokens'))
                            ->numeric()
                            ->placeholder(AiRunResource::ABSENT),
                        TextEntry::make('cost_usd')
                            ->label(self::attemptLabel('cost_usd'))
                            ->formatStateUsing(static fn (string $state): string => AiRunResource::usd($state, 6))
                            ->placeholder(AiRunResource::ABSENT),
                        TextEntry::make('duration_ms')
                            ->label(self::attemptLabel('duration_ms'))
                            ->numeric()
                            ->placeholder(AiRunResource::ABSENT),
                        TextEntry::make('tool_calls')
                            ->label(self::attemptLabel('tool_calls'))
                            ->state(static fn (AiRunAttempt $record): ?string => self::toolNames($record))
                            ->placeholder(AiRunResource::ABSENT),
                        TextEntry::make('error')
                            ->label(self::attemptLabel('error'))
                            ->state(static fn (AiRunAttempt $record): ?string => $record->error_message
                                ?? $record->error_class)
                            ->formatStateUsing(AiRunResource::keepingLineBreaks(...))
                            ->extraAttributes(AiRunResource::WRAP_ANYWHERE)
                            ->placeholder(AiRunResource::ABSENT),
                    ]),
            ]);
    }

    /**
     * One body's tab: its code view, and the tab only while the prune has not
     * blanked the body.
     */
    private static function bodyTab(string $field): Tab
    {
        return Tab::make(__("magic-starter::admin_ai.view.{$field}"))
            ->visible(static fn (AiRun $record): bool => filled($record->getAttribute($field)))
            ->schema([
                CodeBlockEntry::make($field)
                    ->hiddenLabel()
                    ->state(static fn (AiRun $record): mixed => $record->getAttribute($field))
                    ->maxHeight(36)
                    ->columnSpanFull(),
            ]);
    }

    private static function attemptLabel(string $column): string
    {
        return (string) __('magic-starter::admin_ai.' . self::ATTEMPT_LABELS[$column]);
    }

    private static function hasBodies(AiRun $run): bool
    {
        foreach (self::BODIES as $field) {
            if (filled($run->getAttribute($field))) {
                return true;
            }
        }

        return false;
    }

    private static function prunedNote(AiRun $run): ?string
    {
        if ($run->bodies_pruned_at === null) {
            return null;
        }

        return (string) __('magic-starter::admin_ai.view.bodies_pruned', [
            'date' => $run->bodies_pruned_at->copy()->setTimezone(FilamentTimezone::get())->isoFormat('ll LT'),
        ]);
    }

    /**
     * The model name without its namespace and the key, such as `Monitor 42`, or
     * null for a run with no subject.
     */
    private static function subjectLabel(AiRun $run): ?string
    {
        if ($run->subject_type === null) {
            return null;
        }

        return trim(class_basename($run->subject_type) . ' ' . $run->subject_id);
    }
}
