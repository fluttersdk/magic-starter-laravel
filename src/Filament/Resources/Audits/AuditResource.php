<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\Audits;

use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use FlutterSdk\MagicStarter\Audit\Audit;
use FlutterSdk\MagicStarter\Filament\Resources\Audits\Pages\ListAudits;
use FlutterSdk\MagicStarter\Filament\Resources\Audits\Pages\ViewAudit;
use FlutterSdk\MagicStarter\Filament\Resources\MagicStarterResource;
use FlutterSdk\MagicStarter\MagicStarter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The audit trail, read-only: a row is history and is never edited or deleted
 * from the panel. Retention is the prune command's job.
 */
class AuditResource extends MagicStarterResource
{
    /**
     * Shown for a key one side of a change does not carry, which is not the
     * same as a stored `null`.
     */
    protected const ABSENT = '-';

    /**
     * Long keys and values (ids, urls, JSON) break anywhere rather than
     * stretching their cell or the page.
     */
    protected const WRAP_ANYWHERE = ['style' => 'overflow-wrap: anywhere;'];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $recordTitleAttribute = 'event';

    protected static function resolveModel(): string
    {
        return Audit::class;
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('magic-starter::admin_misc.audits.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return (string) __('magic-starter::admin_misc.audits.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('magic-starter::admin_misc.audits.plural_model_label');
    }

    /**
     * What happened to what, such as `Team updated`, for the page title and the
     * breadcrumb; a bare model event reads the same on half the rows. A dotted
     * event such as `admin.team.updated` already names its subject and stands
     * alone.
     */
    public static function getRecordTitle(?Model $record): string
    {
        if (! $record instanceof Audit) {
            return static::getModelLabel();
        }

        $subject = static::subjectLabel($record->auditable_type);

        return $subject === null || str_contains($record->event, '.')
            ? $record->event
            : "{$subject} {$record->event}";
    }

    public static function table(Table $table): Table
    {
        return $table
            // Only the user-acted rows load their actor: another actor type's key
            // may not even parse as the user key (an integer against a uuid
            // column fails on PostgreSQL). The callback returns nothing, since a
            // returned collection would replace the page's rows.
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->afterQuery(
                static function (Collection $audits): void {
                    $audits
                        ->filter(static fn (Model $audit): bool => $audit instanceof Audit && $audit->actedByUser())
                        ->load('actorUser');
                },
            ))
            ->columns([
                TextColumn::make('event')
                    ->label(__('magic-starter::admin_misc.audits.columns.event'))
                    ->badge()
                    ->color(static fn (string $state): string => static::eventColor($state))
                    ->searchable(),
                TextColumn::make('auditable_type')
                    ->label(__('magic-starter::admin_misc.audits.columns.subject'))
                    ->formatStateUsing(static fn (string $state): string => (string) static::subjectLabel($state))
                    ->tooltip(static fn (Audit $record): ?string => $record->auditable_type)
                    ->description(static fn (Audit $record): ?string => $record->auditable_id)
                    ->placeholder(static::ABSENT)
                    ->searchable(['auditable_type', 'auditable_id']),
                TextColumn::make('actor')
                    ->label(__('magic-starter::admin_misc.audits.columns.actor'))
                    ->state(static fn (Audit $record): string => static::actorLabel($record))
                    ->color(static fn (Audit $record): ?string => $record->actor_type === null ? 'gray' : null)
                    ->searchable(query: static fn (Builder $query, string $search): Builder => static::whereActorMatches(
                        $query,
                        $search,
                    )),
                TextColumn::make('created_at')
                    ->label(__('magic-starter::admin_misc.audits.columns.created_at'))
                    ->dateTime()
                    ->sinceTooltip()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->label(__('magic-starter::admin_misc.audits.filters.event'))
                    ->options(static fn (): array => static::distinctValues('event')),
                SelectFilter::make('auditable_type')
                    ->label(__('magic-starter::admin_misc.audits.filters.subject_type'))
                    ->options(static fn (): array => array_map(
                        static fn (string $type): string => (string) static::subjectLabel($type),
                        static::distinctValues('auditable_type'),
                    )),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('created_from')
                            ->label(__('magic-starter::admin_misc.audits.filters.created_from')),
                        DatePicker::make('created_until')
                            ->label(__('magic-starter::admin_misc.audits.filters.created_until')),
                    ])
                    ->query(static fn (Builder $query, array $data): Builder => $query
                        ->when($data['created_from'] ?? null, static fn (Builder $query, string $date): Builder => $query
                            ->where('created_at', '>=', Carbon::parse($date)->startOfDay()))
                        ->when($data['created_until'] ?? null, static fn (Builder $query, string $date): Builder => $query
                            ->where('created_at', '<=', Carbon::parse($date)->endOfDay()))),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Every section spans the page: the change table needs the width, and the
     * panel's default two-column page grid would squeeze it beside the summary.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columnSpanFull()
                ->columns([
                    'default' => 1,
                    'md' => 2,
                    'xl' => 3,
                ])
                ->schema([
                    TextEntry::make('event')
                        ->label(__('magic-starter::admin_misc.audits.view.event'))
                        ->badge()
                        ->color(static fn (string $state): string => static::eventColor($state)),
                    TextEntry::make('created_at')
                        ->label(__('magic-starter::admin_misc.audits.view.created_at'))
                        ->dateTime()
                        ->sinceTooltip(),
                    TextEntry::make('actor')
                        ->label(__('magic-starter::admin_misc.audits.view.actor'))
                        ->state(static fn (Audit $record): string => static::actorLabel($record))
                        ->url(static fn (Audit $record): ?string => static::actorUrl($record))
                        ->color(static fn (Audit $record): ?string => static::actorUrl($record) === null
                            ? null
                            : 'primary'),
                    TextEntry::make('auditable_type')
                        ->label(__('magic-starter::admin_misc.audits.view.subject_type'))
                        ->formatStateUsing(static fn (string $state): string => (string) static::subjectLabel($state))
                        ->tooltip(static fn (Audit $record): ?string => $record->auditable_type)
                        ->placeholder(static::ABSENT),
                    TextEntry::make('auditable_id')
                        ->label(__('magic-starter::admin_misc.audits.view.subject_id'))
                        ->fontFamily(FontFamily::Mono)
                        ->copyable()
                        ->extraAttributes(static::WRAP_ANYWHERE)
                        ->placeholder(static::ABSENT),
                    TextEntry::make('related_user')
                        ->label(__('magic-starter::admin_misc.audits.view.related_user'))
                        ->state(static fn (Audit $record): ?string => static::userLabel($record->relatedUser)
                            ?? $record->related_user_id)
                        ->url(static fn (Audit $record): ?string => static::userUrl($record->relatedUser))
                        ->color(static fn (Audit $record): ?string => static::userUrl($record->relatedUser) === null
                            ? null
                            : 'primary')
                        ->placeholder(static::ABSENT),
                ]),
            Section::make(__('magic-starter::admin_misc.audits.view.changes'))
                ->columnSpanFull()
                ->visible(static fn (Audit $record): bool => filled($record->old_values) || filled($record->new_values))
                ->schema([
                    RepeatableEntry::make('changes')
                        ->hiddenLabel()
                        ->state(static fn (Audit $record): array => static::changeRows($record))
                        ->table(static fn (Audit $record): array => array_map(
                            static fn (string $column): TableColumn => TableColumn::make(
                                __("magic-starter::admin_misc.audits.view.{$column}"),
                            )->width($column === 'field' ? '20%' : null),
                            static::changeColumns($record),
                        ))
                        ->schema(static fn (Audit $record): array => array_map(
                            // Labelled like the header: below `md` the table stacks
                            // into cards that show each cell's own label.
                            static fn (string $column): TextEntry => TextEntry::make($column)
                                ->label(__("magic-starter::admin_misc.audits.view.{$column}"))
                                ->fontFamily(FontFamily::Mono)
                                ->extraAttributes(static::WRAP_ANYWHERE)
                                ->placeholder(static::ABSENT),
                            static::changeColumns($record),
                        )),
                ]),
            Section::make(__('magic-starter::admin_misc.audits.view.context'))
                ->columnSpanFull()
                ->collapsible()
                ->visible(static fn (Audit $record): bool => filled($record->context))
                ->schema([
                    KeyValueEntry::make('context')
                        ->hiddenLabel()
                        ->keyLabel(__('magic-starter::admin_misc.audits.view.field'))
                        ->valueLabel(__('magic-starter::admin_misc.audits.view.value'))
                        ->extraAttributes(static::WRAP_ANYWHERE)
                        ->state(static fn (Audit $record): array => array_map(
                            static fn (mixed $value): string => static::formatValue($value),
                            $record->context ?? [],
                        )),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAudits::route('/'),
            'view' => ViewAudit::route('/{record}'),
        ];
    }

    /**
     * The table columns of the change section: `field` plus whichever sides the
     * row recorded, so a creation shows no empty "before" column.
     *
     * @return list<string>
     */
    protected static function changeColumns(Audit $record): array
    {
        return array_values(array_filter([
            'field',
            filled($record->old_values) ? 'old_values' : null,
            filled($record->new_values) ? 'new_values' : null,
        ]));
    }

    /**
     * One row per key either side recorded, in the order the row stored them.
     *
     * Each value is rendered as text: a bool, a number or a nested array would
     * otherwise print as `1`, an empty string or an array-to-string notice. The
     * redaction marker is already a plain string, so it passes through.
     *
     * @return list<array<string, string|null>>
     */
    protected static function changeRows(Audit $record): array
    {
        $old = $record->old_values ?? [];
        $new = $record->new_values ?? [];

        return array_map(
            static fn (string|int $key): array => [
                'field' => (string) $key,
                'old_values' => array_key_exists($key, $old) ? static::formatValue($old[$key]) : null,
                'new_values' => array_key_exists($key, $new) ? static::formatValue($new[$key]) : null,
            ],
            array_keys([...$old, ...$new]),
        );
    }

    /**
     * An empty string is quoted: a blank state renders as the absent marker,
     * and a stored `''` is a value, not a missing key.
     */
    protected static function formatValue(mixed $value): string
    {
        return is_string($value) && $value !== ''
            ? $value
            : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Rows whose actor key contains the search, or whose actor is a user with a
     * matching name or address.
     *
     * The user keys are fetched first and compared as strings, which is how
     * `actor_id` is stored: a subquery would compare varchar against the user
     * key's own type, which PostgreSQL refuses.
     *
     * @param  Builder<Audit>  $query
     * @return Builder<Audit>
     */
    protected static function whereActorMatches(Builder $query, string $search): Builder
    {
        $userModel = MagicStarter::userModel();
        $user = new $userModel;
        $like = '%' . $search . '%';

        $keys = $userModel::query()
            ->where(static fn (Builder $users): Builder => $users
                ->where('name', 'like', $like)
                ->orWhere('email', 'like', $like))
            ->pluck($user->getKeyName())
            ->map(static fn (mixed $key): string => (string) $key)
            ->all();

        return $query->where(static fn (Builder $audits): Builder => $audits
            ->where('actor_id', 'like', $like)
            ->orWhere(static fn (Builder $byUser): Builder => $byUser
                ->where('actor_type', $user->getMorphClass())
                ->whereIn('actor_id', $keys)));
    }

    /**
     * The badge colour for an event, read from its verb so dotted events such
     * as `user.deleted` and custom ones get a colour too.
     */
    protected static function eventColor(string $event): string
    {
        return match (true) {
            str_starts_with($event, 'admin.') => 'primary',
            str_ends_with($event, 'created') => 'success',
            str_ends_with($event, 'deleted') => 'danger',
            str_ends_with($event, 'updated') => 'info',
            default => 'gray',
        };
    }

    /**
     * The model name without its namespace, such as `Team` for `App\Models\Team`.
     */
    protected static function subjectLabel(?string $type): ?string
    {
        return $type === null ? null : class_basename($type);
    }

    /**
     * Who acted: the user's name, a placeholder for a user deleted since, the
     * system for a row with no actor (a job, a command, a webhook), and the type
     * and key of any other actor.
     */
    protected static function actorLabel(Audit $record): string
    {
        if ($record->actor_type === null) {
            return (string) __('magic-starter::admin_misc.audits.view.system');
        }

        if ($record->actedByUser()) {
            return static::userLabel($record->actorUser)
                ?? (string) __('magic-starter::admin_misc.audits.view.deleted_user');
        }

        return trim(class_basename($record->actor_type) . ' ' . $record->actor_id);
    }

    /**
     * A user's name, then the address in brackets when it has one; a guest
     * may have neither.
     */
    protected static function userLabel(?Model $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $name = (string) $user->getAttribute('name');
        $email = $user->getAttribute('email');

        return match (true) {
            $email === null => $name !== '' ? $name : (string) $user->getKey(),
            $name === '' => (string) $email,
            default => "{$name} ({$email})",
        };
    }

    protected static function actorUrl(Audit $record): ?string
    {
        return $record->actedByUser() ? static::userUrl($record->actorUser) : null;
    }

    /**
     * The edit page of the user resource this panel mounts, which may be the
     * application's override rather than the package's.
     */
    protected static function userUrl(?Model $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $resource = Filament::getModelResource($user);

        return $resource !== null && $resource::hasPage('edit')
            ? $resource::getUrl('edit', ['record' => $user])
            : null;
    }

    /**
     * The distinct values stored in a column, as filter options.
     *
     * @return array<string, string>
     */
    protected static function distinctValues(string $column): array
    {
        return static::getModel()::query()
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column, $column)
            ->all();
    }
}
