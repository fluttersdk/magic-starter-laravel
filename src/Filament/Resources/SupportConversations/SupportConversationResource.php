<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\SupportConversations;

use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Enums\SupportConversationStatus;
use FlutterSdk\MagicStarter\Enums\SupportMessageKind;
use FlutterSdk\MagicStarter\Enums\SupportPriority;
use FlutterSdk\MagicStarter\Enums\SupportSenderType;
use FlutterSdk\MagicStarter\Filament\Resources\MagicStarterResource;
use FlutterSdk\MagicStarter\Filament\Resources\SupportConversations\Pages\ListSupportConversations;
use FlutterSdk\MagicStarter\Filament\Resources\SupportConversations\Pages\ViewSupportConversation;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Models\SupportMessage;
use FlutterSdk\MagicStarter\Support\SupportStaffRecipients;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * The staff inbox: every recorded conversation, answered from the panel.
 *
 * Writes never leave through this class. The four actions on the view page call
 * the `RepliesToSupport` and `ChangesSupportConversations` contracts, and every
 * piece of text, the customer's and the staff's, is rendered escaped.
 *
 * Every string is a namespaced `magic-starter::admin_support` entry, never a bare
 * literal key: a dot-less key without a JSON line is read as a group name, so an
 * application with its own `lang/en/status.php` would hand a label an array.
 */
class SupportConversationResource extends MagicStarterResource
{
    /**
     * Shown for a column a conversation does not carry.
     */
    protected const ABSENT = '-';

    /**
     * A body keeps its line breaks and breaks anywhere rather than stretching the page.
     */
    protected const BODY_STYLE = 'overflow-wrap: anywhere; white-space: pre-wrap;';

    /**
     * A staff note sits behind an amber rule, so it cannot be read as a reply.
     */
    protected const NOTE_STYLE = 'border-left: 3px solid #f59e0b; padding-left: 0.75rem;';

    /**
     * The lang group each enum's labels live under.
     *
     * @var array<class-string<BackedEnum>, string>
     */
    protected const ENUM_GROUPS = [
        SupportConversationSource::class => 'source',
        SupportConversationStatus::class => 'status',
        SupportPriority::class => 'priority',
    ];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $recordTitleAttribute = 'subject';

    protected static function resolveModel(): string
    {
        return SupportConversation::class;
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('magic-starter::admin_support.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return (string) __('magic-starter::admin_support.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('magic-starter::admin_support.plural_model_label');
    }

    /**
     * The number staff and customers quote, then the subject.
     */
    public static function getRecordTitle(?Model $record): string
    {
        if (! $record instanceof SupportConversation) {
            return static::getModelLabel();
        }

        return "#{$record->number} {$record->subject}";
    }

    /**
     * The conversations waiting on staff, or nothing when the queue is empty.
     */
    public static function getNavigationBadge(): ?string
    {
        $open = SupportConversation::query()
            ->where('status', SupportConversationStatus::OPEN->value)
            ->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with([
                'user',
                'assignee',
            ]))
            ->columns([
                TextColumn::make('number')
                    ->label(static::fieldLabel('number'))
                    ->prefix('#')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('subject')
                    ->label(static::fieldLabel('subject'))
                    ->limit(60)
                    ->tooltip(static fn (SupportConversation $record): string => $record->subject)
                    ->searchable(),
                TextColumn::make('source')
                    ->label(static::fieldLabel('source'))
                    ->formatStateUsing(
                        static fn (SupportConversationSource $state): string => static::enumLabel($state),
                    ),
                TextColumn::make('status')
                    ->label(static::fieldLabel('status'))
                    ->badge()
                    ->formatStateUsing(
                        static fn (SupportConversationStatus $state): string => static::enumLabel($state),
                    )
                    ->color(static fn (SupportConversationStatus $state): string => static::statusColor($state)),
                TextColumn::make('priority')
                    ->label(static::fieldLabel('priority'))
                    ->badge()
                    ->formatStateUsing(static fn (SupportPriority $state): string => static::enumLabel($state))
                    ->color(static fn (SupportPriority $state): string => static::priorityColor($state)),
                TextColumn::make('assignee.name')
                    ->label(static::fieldLabel('assignee'))
                    ->placeholder(static::ABSENT),
                TextColumn::make('email')
                    ->label(static::fieldLabel('opener'))
                    ->state(static fn (SupportConversation $record): string => static::openerLabel($record))
                    ->searchable(),
                TextColumn::make('last_activity_at')
                    ->label(static::fieldLabel('last_activity'))
                    ->dateTime()
                    ->sinceTooltip()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(static::fieldLabel('status'))
                    ->multiple()
                    ->options(static::enumOptions(SupportConversationStatus::cases()))
                    ->default(static::unresolvedStatuses()),
                SelectFilter::make('source')
                    ->label(static::fieldLabel('source'))
                    ->options(static::enumOptions(SupportConversationSource::cases())),
                SelectFilter::make('assignee_id')
                    ->label(static::fieldLabel('assignee'))
                    ->options(static fn (): array => static::staffOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('last_activity_at', 'desc');
    }

    /**
     * Every section spans the page, so the thread gets the full width.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columnSpanFull()
                ->columns([
                    'default' => 1,
                    'md' => 2,
                    'xl' => 4,
                ])
                ->schema([
                    TextEntry::make('opener')
                        ->label(static::fieldLabel('opener'))
                        ->state(static fn (SupportConversation $record): string => static::openerLabel($record)),
                    TextEntry::make('team.name')
                        ->label(static::fieldLabel('team'))
                        ->placeholder(static::ABSENT),
                    TextEntry::make('source')
                        ->label(static::fieldLabel('source'))
                        ->formatStateUsing(
                            static fn (SupportConversationSource $state): string => static::enumLabel($state),
                        ),
                    TextEntry::make('status')
                        ->label(static::fieldLabel('status'))
                        ->badge()
                        ->formatStateUsing(
                            static fn (SupportConversationStatus $state): string => static::enumLabel($state),
                        )
                        ->color(static fn (SupportConversationStatus $state): string => static::statusColor($state)),
                    TextEntry::make('priority')
                        ->label(static::fieldLabel('priority'))
                        ->badge()
                        ->formatStateUsing(static fn (SupportPriority $state): string => static::enumLabel($state))
                        ->color(static fn (SupportPriority $state): string => static::priorityColor($state)),
                    TextEntry::make('assignee.name')
                        ->label(static::fieldLabel('assignee'))
                        ->placeholder(static::ABSENT),
                    TextEntry::make('created_at')
                        ->label(static::fieldLabel('opened'))
                        ->dateTime()
                        ->sinceTooltip(),
                    TextEntry::make('last_activity_at')
                        ->label(static::fieldLabel('last_activity'))
                        ->dateTime()
                        ->sinceTooltip()
                        ->placeholder(static::ABSENT),
                ]),
            Section::make((string) __('magic-starter::admin_support.thread'))
                ->columnSpanFull()
                ->schema([
                    RepeatableEntry::make('thread')
                        ->hiddenLabel()
                        ->state(static fn (SupportConversation $record): Collection => static::thread($record))
                        ->schema([
                            TextEntry::make('author')
                                ->hiddenLabel()
                                ->state(static fn (SupportMessage $record): string => static::authorLabel($record))
                                ->badge()
                                ->color(static fn (SupportMessage $record): string => static::messageColor($record)),
                            TextEntry::make('created_at')
                                ->hiddenLabel()
                                ->dateTime()
                                ->sinceTooltip(),
                            TextEntry::make('body')
                                ->hiddenLabel()
                                ->extraAttributes(static fn (SupportMessage $record): array => [
                                    'style' => $record->kind === SupportMessageKind::NOTE
                                        ? static::BODY_STYLE . ' ' . static::NOTE_STYLE
                                        : static::BODY_STYLE,
                                ]),
                        ]),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupportConversations::route('/'),
            'view' => ViewSupportConversation::route('/{record}'),
        ];
    }

    /**
     * The staff who may be assigned a conversation, keyed by user key.
     *
     * @return array<string, string>
     */
    public static function staffOptions(): array
    {
        return app(SupportStaffRecipients::class)->all()
            ->mapWithKeys(static fn (Model $user): array => [
                (string) $user->getKey() => (string) ($user->getAttribute('name') ?: $user->getAttribute('email')),
            ])
            ->all();
    }

    /**
     * Enum values as select options, labelled for reading.
     *
     * @param  list<BackedEnum>  $cases
     * @return array<string, string>
     */
    public static function enumOptions(array $cases): array
    {
        $options = [];

        foreach ($cases as $case) {
            $options[(string) $case->value] = static::enumLabel($case);
        }

        return $options;
    }

    /**
     * Every status but the resolved one: what the inbox shows until it is told otherwise.
     *
     * @return list<string>
     */
    protected static function unresolvedStatuses(): array
    {
        return array_values(array_map(
            static fn (SupportConversationStatus $status): string => $status->value,
            array_filter(
                SupportConversationStatus::cases(),
                static fn (SupportConversationStatus $status): bool => $status !== SupportConversationStatus::RESOLVED,
            ),
        ));
    }

    /**
     * The whole thread in the order it was written, staff notes included.
     *
     * @return Collection<int, SupportMessage>
     */
    protected static function thread(SupportConversation $conversation): Collection
    {
        return $conversation->messages()
            ->with('sender')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Who wrote in, such as `Ada (ada@example.com)`; a visitor says so.
     */
    protected static function openerLabel(SupportConversation $record): string
    {
        $address = $record->user?->getAttribute('email') ?? $record->email;
        $name = $record->user?->getAttribute('name') ?? $record->name;
        $label = filled($name) && filled($address) ? "{$name} ({$address})" : (string) ($name ?: $address);

        if ($label === '') {
            return static::ABSENT;
        }

        return $record->user_id === null ? $label . ' (' . __('magic-starter::admin_support.visitor') . ')' : $label;
    }

    /**
     * What a thread entry is and who wrote it; a note says so first.
     */
    protected static function authorLabel(SupportMessage $message): string
    {
        $role = match (true) {
            $message->kind === SupportMessageKind::NOTE => static::authorRole('internal_note'),
            $message->kind === SupportMessageKind::ACTIVITY => static::authorRole('activity'),
            default => match ($message->sender_type) {
                SupportSenderType::CUSTOMER => static::authorRole('customer'),
                SupportSenderType::STAFF => static::authorRole('staff'),
                SupportSenderType::AI => static::authorRole('ai'),
                SupportSenderType::SYSTEM => static::authorRole('system'),
            },
        };
        $name = $message->sender?->getAttribute('name');

        return filled($name) ? "{$role}: {$name}" : $role;
    }

    protected static function messageColor(SupportMessage $message): string
    {
        return match (true) {
            $message->kind === SupportMessageKind::NOTE => 'warning',
            $message->sender_type === SupportSenderType::STAFF => 'primary',
            default => 'gray',
        };
    }

    protected static function statusColor(SupportConversationStatus $status): string
    {
        return match ($status) {
            SupportConversationStatus::PENDING, SupportConversationStatus::SNOOZED => 'gray',
            SupportConversationStatus::OPEN => 'warning',
            SupportConversationStatus::RESOLVED => 'success',
        };
    }

    protected static function priorityColor(SupportPriority $priority): string
    {
        return match ($priority) {
            SupportPriority::LOW, SupportPriority::NORMAL => 'gray',
            SupportPriority::HIGH => 'warning',
            SupportPriority::URGENT => 'danger',
        };
    }

    /**
     * The shipped label of a status, priority or source case.
     */
    protected static function enumLabel(BackedEnum $case): string
    {
        $group = static::ENUM_GROUPS[$case::class];

        return (string) __("magic-starter::admin_support.enums.{$group}.{$case->value}");
    }

    /**
     * A field name as the list, the filters, the thread header and the action forms spell it.
     */
    public static function fieldLabel(string $field): string
    {
        return (string) __("magic-starter::admin_support.fields.{$field}");
    }

    protected static function authorRole(string $role): string
    {
        return (string) __("magic-starter::admin_support.authors.{$role}");
    }
}
