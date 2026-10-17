<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\AiRuns\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Facades\FilamentTimezone;
use FlutterSdk\MagicStarter\Enums\AiRunStatus;
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
     * The text bodies, then the JSON ones, in the order the section lists them.
     */
    private const TEXT_BODIES = ['instructions', 'input', 'output', 'error_message'];

    private const JSON_BODIES = ['context', 'tools', 'structured'];

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
     * Every section spans the page, so the bodies and the attempts get the full width.
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            self::metadata(),
            self::bodies(),
            self::attempts(),
        ]);
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
                    ->formatStateUsing(static fn (AiRunStatus $state): string => $state->value)
                    ->color(static fn (AiRunStatus $state): string => AiRunResource::statusColor($state)),
                TextEntry::make('source')
                    ->label(__('magic-starter::admin_ai.columns.source')),
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
                    ->formatStateUsing(static fn (?string $state): ?string => $state === null
                        ? null
                        : AiRunResource::usd($state, 6))
                    ->placeholder(AiRunResource::ABSENT),
                TextEntry::make('cost_source')
                    ->label(__('magic-starter::admin_ai.columns.cost_source'))
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
                ...array_map(
                    static fn (string $field): TextEntry => self::bodyEntry($field)
                        ->state(static fn (AiRun $record): mixed => $record->getAttribute($field)),
                    self::TEXT_BODIES,
                ),
                ...array_map(
                    static fn (string $field): TextEntry => self::bodyEntry($field)
                        ->state(static fn (AiRun $record): ?string => self::json($record->getAttribute($field))),
                    self::JSON_BODIES,
                ),
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
                        static fn (string $column): TableColumn => TableColumn::make(self::attemptLabel($column)),
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
                            ->color(static fn (string $state): string => AiRunResource::statusColor(
                                AiRunStatus::tryFrom($state),
                            )),
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
                            ->formatStateUsing(static fn (?string $state): ?string => $state === null
                                ? null
                                : AiRunResource::usd($state, 6))
                            ->placeholder(AiRunResource::ABSENT),
                        TextEntry::make('duration_ms')
                            ->label(self::attemptLabel('duration_ms'))
                            ->numeric()
                            ->placeholder(AiRunResource::ABSENT),
                        TextEntry::make('tool_calls')
                            ->label(self::attemptLabel('tool_calls'))
                            ->state(static fn (AiRunAttempt $record): ?string => self::json($record->tool_calls))
                            ->fontFamily(FontFamily::Mono)
                            ->extraAttributes(AiRunResource::WRAP_ANYWHERE)
                            ->placeholder(AiRunResource::ABSENT),
                        TextEntry::make('error')
                            ->label(self::attemptLabel('error'))
                            ->state(static fn (AiRunAttempt $record): ?string => $record->error_message
                                ?? $record->error_class)
                            ->extraAttributes(AiRunResource::WRAP_ANYWHERE)
                            ->placeholder(AiRunResource::ABSENT),
                    ]),
            ]);
    }

    /**
     * The entry shared by every body: labelled, monospaced, wrapped, and shown
     * only while the prune has not blanked it. The caller sets the state.
     */
    private static function bodyEntry(string $field): TextEntry
    {
        return TextEntry::make($field)
            ->label(__("magic-starter::admin_ai.view.{$field}"))
            ->fontFamily(FontFamily::Mono)
            ->extraAttributes(AiRunResource::WRAP_ANYWHERE)
            ->columnSpanFull()
            ->visible(static fn (AiRun $record): bool => filled($record->getAttribute($field)));
    }

    private static function attemptLabel(string $column): string
    {
        return (string) __('magic-starter::admin_ai.' . self::ATTEMPT_LABELS[$column]);
    }

    private static function hasBodies(AiRun $run): bool
    {
        foreach ([...self::TEXT_BODIES, ...self::JSON_BODIES] as $field) {
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

    private static function json(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
