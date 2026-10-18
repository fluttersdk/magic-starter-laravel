<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\AiRuns;

use BackedEnum;
use Closure;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use FlutterSdk\MagicStarter\Enums\AiRunStatus;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Filament\Resources\AiRuns\Pages\ListAiRuns;
use FlutterSdk\MagicStarter\Filament\Resources\AiRuns\Pages\ViewAiRun;
use FlutterSdk\MagicStarter\Filament\Resources\AiRuns\Schemas\AiRunInfolist;
use FlutterSdk\MagicStarter\Filament\Resources\MagicStarterResource;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\AiRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * The AI run log, read-only: a run is a reading of what a model call did and is
 * never edited or deleted from the panel. Retention is the prune command's job.
 *
 * A run outlives its team, and `team_id` is a plain string with no foreign key:
 * without the teams feature the `teams` table may not exist, so the team is
 * looked up only when that feature is on and the raw id is shown otherwise.
 */
class AiRunResource extends MagicStarterResource
{
    /**
     * Shown for a column a run did not report (no cost, no team, no provider).
     */
    public const ABSENT = '-';

    /**
     * Ids, prompts and JSON break anywhere rather than stretching their cell or the page.
     *
     * Not `white-space: pre-wrap`: the entry that carries these attributes also holds the template's
     * own indentation and newlines, which would then render as blank lines and a deep indent between
     * the label and the value. A value that must keep its line breaks goes through
     * {@see keepingLineBreaks()}.
     */
    public const WRAP_ANYWHERE = ['style' => 'overflow-wrap: anywhere;'];

    /**
     * How many teams or team ids a team search offers.
     */
    protected const TEAM_SEARCH_LIMIT = 50;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $recordTitleAttribute = 'feature';

    protected static function resolveModel(): string
    {
        return AiRun::class;
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('magic-starter::admin_ai.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return (string) __('magic-starter::admin_ai.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('magic-starter::admin_ai.plural_model_label');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => Features::hasTeamFeatures()
                ? $query->with('team')
                : $query)
            ->columns([
                TextColumn::make('started_at')
                    ->label(__('magic-starter::admin_ai.columns.started_at'))
                    ->dateTime()
                    ->sinceTooltip()
                    ->sortable(),
                TextColumn::make('feature')
                    ->label(__('magic-starter::admin_ai.columns.feature'))
                    ->searchable(),
                TextColumn::make('status')
                    ->label(__('magic-starter::admin_ai.columns.status'))
                    ->badge()
                    ->formatStateUsing(static fn (AiRunStatus $state): string => static::statusLabel($state))
                    ->color(static fn (AiRunStatus $state): string => static::statusColor($state)),
                TextColumn::make('team')
                    ->label(__('magic-starter::admin_ai.columns.team'))
                    ->state(static fn (AiRun $record): ?string => static::teamLabel($record))
                    ->tooltip(static fn (AiRun $record): ?string => $record->team_id)
                    ->placeholder(static::ABSENT),
                TextColumn::make('provider')
                    ->label(__('magic-starter::admin_ai.columns.provider'))
                    ->placeholder(static::ABSENT),
                TextColumn::make('model')
                    ->label(__('magic-starter::admin_ai.columns.model'))
                    ->placeholder(static::ABSENT),
                TextColumn::make('attempts_count')
                    ->label(__('magic-starter::admin_ai.columns.attempts_count'))
                    ->numeric(),
                TextColumn::make('input_tokens')
                    ->label(__('magic-starter::admin_ai.columns.input_tokens'))
                    ->numeric()
                    ->placeholder(static::ABSENT),
                TextColumn::make('output_tokens')
                    ->label(__('magic-starter::admin_ai.columns.output_tokens'))
                    ->numeric()
                    ->placeholder(static::ABSENT),
                TextColumn::make('cache_read_tokens')
                    ->label(__('magic-starter::admin_ai.columns.cache_read_tokens'))
                    ->numeric()
                    ->placeholder(static::ABSENT),
                TextColumn::make('cost_usd')
                    ->label(__('magic-starter::admin_ai.columns.cost_usd'))
                    // Filament shows the placeholder for a null state and never formats it.
                    ->formatStateUsing(static fn (string $state): string => static::usd($state, 6))
                    ->placeholder(static::ABSENT)
                    ->sortable(query: static::nullsLast('cost_usd')),
                TextColumn::make('duration_ms')
                    ->label(__('magic-starter::admin_ai.columns.duration_ms'))
                    ->numeric()
                    ->placeholder(static::ABSENT)
                    ->sortable(query: static::nullsLast('duration_ms')),
            ])
            ->filters([
                SelectFilter::make('feature')
                    ->label(__('magic-starter::admin_ai.filters.feature'))
                    ->options(static fn (): array => static::distinctFeatures()),
                SelectFilter::make('status')
                    ->label(__('magic-starter::admin_ai.filters.status'))
                    ->options(static::statusOptions()),
                SelectFilter::make('team_id')
                    ->label(__('magic-starter::admin_ai.filters.team'))
                    ->searchable()
                    ->getSearchResultsUsing(static fn (string $search): array => static::searchTeams($search))
                    ->getOptionLabelUsing(static fn (string $value): string => static::teamName($value)),
                Filter::make('started_at')
                    ->schema([
                        DatePicker::make('started_from')
                            ->label(__('magic-starter::admin_ai.filters.started_from')),
                        DatePicker::make('started_until')
                            ->label(__('magic-starter::admin_ai.filters.started_until')),
                    ])
                    ->query(static fn (Builder $query, array $data): Builder => static::startedBetween($query, $data)),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('started_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return AiRunInfolist::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiRuns::route('/'),
            'view' => ViewAiRun::route('/{record}'),
        ];
    }

    /**
     * The team's name, or the raw id once the team is gone or the teams
     * feature is off; null for a run with no team.
     */
    public static function teamLabel(AiRun $run): ?string
    {
        if ($run->team_id === null) {
            return null;
        }

        if (! Features::hasTeamFeatures()) {
            return $run->team_id;
        }

        $name = $run->team?->getAttribute('name');

        return is_string($name) ? $name : $run->team_id;
    }

    /**
     * The value escaped, with its own line breaks and indentation kept and nothing else.
     *
     * For a `formatStateUsing()` on an entry that shows a prompt, a JSON body or an error message.
     */
    public static function keepingLineBreaks(mixed $state): HtmlString
    {
        return new HtmlString('<span style="white-space: pre-wrap;">' . e((string) $state) . '</span>');
    }

    /**
     * A dollar amount with a fixed number of places, such as `$0.012345`.
     */
    public static function usd(string|float|int $amount, int $decimals): string
    {
        return '$' . number_format((float) $amount, $decimals);
    }

    /**
     * Badge colour by status: a failure is the one an operator looks for.
     */
    public static function statusColor(AiRunStatus $status): string
    {
        return match ($status) {
            AiRunStatus::SUCCEEDED => 'success',
            AiRunStatus::FAILED => 'danger',
            AiRunStatus::RUNNING => 'warning',
            AiRunStatus::CACHE_HIT => 'info',
            AiRunStatus::ABANDONED => 'gray',
        };
    }

    /**
     * The status in the staff user's language, for a badge or a filter option.
     */
    public static function statusLabel(AiRunStatus $status): string
    {
        return (string) __("magic-starter::admin_ai.statuses.{$status->value}");
    }

    /**
     * A sort by a figure a run may not have reported, with those runs last in both directions:
     * PostgreSQL lists nulls first descending and SQLite first ascending.
     *
     * @return Closure(Builder<AiRun>, string): Builder<AiRun>
     */
    protected static function nullsLast(string $column): Closure
    {
        return static fn (Builder $query, string $direction): Builder => $query
            ->orderByRaw($query->getQuery()->getGrammar()->wrap($column) . ' is null')
            ->orderBy($column, $direction);
    }

    /**
     * Runs started from the beginning of one day to the end of another, either bound optional.
     *
     * @param  Builder<AiRun>  $query
     * @param  array<string, mixed>  $data  The date range filter state.
     * @return Builder<AiRun>
     */
    protected static function startedBetween(Builder $query, array $data): Builder
    {
        return $query
            ->when($data['started_from'] ?? null, static fn (Builder $runs, string $day): Builder => $runs
                ->where('started_at', '>=', Carbon::parse($day)->startOfDay()))
            ->when($data['started_until'] ?? null, static fn (Builder $runs, string $day): Builder => $runs
                ->where('started_at', '<=', Carbon::parse($day)->endOfDay()));
    }

    /**
     * Filter options keyed by the stored value and labelled like the badge.
     *
     * @return array<string, string>
     */
    protected static function statusOptions(): array
    {
        $options = [];

        foreach (AiRunStatus::cases() as $status) {
            $options[$status->value] = static::statusLabel($status);
        }

        return $options;
    }

    /**
     * The feature names the log holds, which are the host's own and so not a fixed list.
     *
     * @return array<string, string>
     */
    protected static function distinctFeatures(): array
    {
        return AiRun::query()
            ->distinct()
            ->orderBy('feature')
            ->pluck('feature', 'feature')
            ->all();
    }

    /**
     * Teams whose name contains the search, or, without the teams feature, the
     * team ids the log holds that contain it. Capped at {@see TEAM_SEARCH_LIMIT}.
     *
     * @return array<string, string>
     */
    protected static function searchTeams(string $search): array
    {
        $like = '%' . $search . '%';

        if (! Features::hasTeamFeatures()) {
            return AiRun::query()
                ->whereLike('team_id', $like)
                ->distinct()
                ->limit(static::TEAM_SEARCH_LIMIT)
                ->pluck('team_id', 'team_id')
                ->all();
        }

        $teams = MagicStarter::teamModel();

        return $teams::query()
            ->whereLike('name', $like)
            ->limit(static::TEAM_SEARCH_LIMIT)
            ->pluck('name', (new $teams)->getKeyName())
            ->mapWithKeys(static fn (string $name, int|string $key): array => [(string) $key => $name])
            ->all();
    }

    /**
     * The label of a chosen team filter value: the name, else the id it was stored under.
     */
    protected static function teamName(string $id): string
    {
        if (! Features::hasTeamFeatures()) {
            return $id;
        }

        $teams = MagicStarter::teamModel();
        /** @var Model|null $team */
        $team = $teams::query()->find($id);
        $name = $team?->getAttribute('name');

        return is_string($name) ? $name : $id;
    }
}
