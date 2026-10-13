<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\SupportConversations;

use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use FlutterSdk\MagicStarter\Contracts\AssistsSupportStaff;
use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Enums\SupportConversationStatus;
use FlutterSdk\MagicStarter\Enums\SupportHandoffReason;
use FlutterSdk\MagicStarter\Enums\SupportMessageKind;
use FlutterSdk\MagicStarter\Enums\SupportPriority;
use FlutterSdk\MagicStarter\Enums\SupportSenderType;
use FlutterSdk\MagicStarter\Filament\Resources\MagicStarterResource;
use FlutterSdk\MagicStarter\Filament\Resources\SupportConversations\Pages\ListSupportConversations;
use FlutterSdk\MagicStarter\Filament\Resources\SupportConversations\Pages\ViewSupportConversation;
use FlutterSdk\MagicStarter\Http\Resources\SupportMessageResource;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Models\SupportMessage;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportStaffAssistCache;
use FlutterSdk\MagicStarter\Support\SupportRules;
use FlutterSdk\MagicStarter\Support\SupportStaffRecipients;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use WeakMap;

/**
 * The staff inbox: every recorded conversation, answered from the panel.
 *
 * Writes never leave through this class. The actions on the view page call the
 * `RepliesToSupport` and `ChangesSupportConversations` contracts, and every
 * piece of text, the customer's and the staff's, is rendered escaped. The thread
 * is drawn by `magic-starter::filament.support.thread`; the AI summary on it is
 * read from {@see SupportStaffAssistCache} and never asked of a model here.
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
     * The lang group each enum's labels live under.
     *
     * @var array<class-string<BackedEnum>, string>
     */
    protected const ENUM_GROUPS = [
        SupportConversationSource::class => 'source',
        SupportConversationStatus::class => 'status',
        SupportPriority::class => 'priority',
        SupportHandoffReason::class => 'handoff_reason',
    ];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $recordTitleAttribute = 'subject';

    /**
     * {@see self::aiTurns()} per record instance.
     *
     * @var WeakMap<SupportConversation, int>|null
     */
    protected static ?WeakMap $aiTurns = null;

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
                TextColumn::make('topic')
                    ->label(static::fieldLabel('topic'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(static fn (string $state): string => static::topicLabel($state))
                    ->placeholder(static::ABSENT),
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
                SelectFilter::make('topic')
                    ->label(static::fieldLabel('topic'))
                    ->options(static fn (): array => collect(SupportRules::topics())
                        ->mapWithKeys(static fn (string $topic): array => [
                            $topic => static::topicLabel($topic),
                        ])
                        ->all()),
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
     * The thread on the left and the details beside it, stacked on a narrow screen.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make([
                'default' => 1,
                'lg' => 3,
            ])
                ->columnSpanFull()
                ->schema([
                    Section::make((string) __('magic-starter::admin_support.thread'))
                        ->columnSpan([
                            'lg' => 2,
                        ])
                        ->schema([
                            ViewEntry::make('thread')
                                ->hiddenLabel()
                                ->view(static::threadView())
                                ->viewData(static fn (SupportConversation $record): array => [
                                    'items' => static::threadItems($record),
                                    'summary' => static::summaryCard($record),
                                ])
                                ->poll('10s'),
                        ]),
                    Section::make((string) __('magic-starter::admin_support.details'))
                        ->columnSpan(1)
                        ->schema(static::detailEntries()),
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
     * The statuses staff may set: `pending` is the AI desk's holding state, so it is never offered.
     *
     * @return array<string, string>
     */
    public static function changeableStatusOptions(): array
    {
        return static::enumOptions(array_values(array_filter(
            SupportConversationStatus::cases(),
            static fn (SupportConversationStatus $status): bool => $status !== SupportConversationStatus::PENDING,
        )));
    }

    /**
     * The view that draws the thread, registered by the service provider as `magic-starter`.
     *
     * Larastan boots no package provider, so a literal here is rejected as a `view-string`
     * it cannot resolve; the namespace is real at runtime.
     */
    protected static function threadView(): string
    {
        return 'magic-starter::filament.support.thread';
    }

    /**
     * The thread as the Blade draws it: one plain array per bubble or event line, notes included.
     *
     * @return list<array{variant: string, out: bool, body: string, avatar: string, chip: ?string, meta: list<string>}>
     */
    public static function threadItems(SupportConversation $conversation): array
    {
        return static::thread($conversation)
            ->map(static fn (SupportMessage $message): array => static::threadItem($message, $conversation))
            ->all();
    }

    /**
     * The cached AI summary, or null while the contract is unbound or nothing is stored for this turn.
     *
     * A cache read only: opening or polling the page never reaches a model.
     *
     * @return array{text: string, age: string}|null
     */
    public static function summaryCard(SupportConversation $conversation): ?array
    {
        if (! app()->bound(AssistsSupportStaff::class)) {
            return null;
        }

        $stored = app(SupportStaffAssistCache::class)->get($conversation);

        return $stored === null ? null : [
            'text' => $stored['summary'],
            'age' => $stored['at']->diffForHumans(),
        ];
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
     * The details beside the thread, labelled inline.
     *
     * @return list<TextEntry>
     */
    protected static function detailEntries(): array
    {
        return [
            TextEntry::make('status')
                ->label(static::fieldLabel('status'))
                ->badge()
                ->formatStateUsing(
                    static fn (SupportConversationStatus $state, SupportConversation $record): string => (
                        static::statusLabel($state, $record)
                    ),
                )
                ->color(static fn (SupportConversationStatus $state): string => static::statusColor($state)),
            TextEntry::make('priority')
                ->label(static::fieldLabel('priority'))
                ->badge()
                ->formatStateUsing(static fn (SupportPriority $state): string => static::enumLabel($state))
                ->color(static fn (SupportPriority $state): string => static::priorityColor($state)),
            TextEntry::make('topic')
                ->label(static::fieldLabel('topic'))
                ->formatStateUsing(static fn (string $state): string => static::topicLabel($state))
                ->placeholder(static::ABSENT),
            TextEntry::make('source')
                ->label(static::fieldLabel('source'))
                ->formatStateUsing(
                    static fn (SupportConversationSource $state): string => static::enumLabel($state),
                ),
            TextEntry::make('opener')
                ->label(static::fieldLabel('opener'))
                ->state(static fn (SupportConversation $record): string => static::openerLabel($record)),
            TextEntry::make('team.name')
                ->label(static::fieldLabel('team'))
                ->placeholder(static::ABSENT),
            TextEntry::make('assignee.name')
                ->label(static::fieldLabel('assignee'))
                ->placeholder(static::ABSENT),
            TextEntry::make('ai_turns')
                ->label(static::fieldLabel('ai_turns'))
                ->state(static fn (SupportConversation $record): int => static::aiTurns($record))
                ->visible(static fn (SupportConversation $record): bool => static::aiTurns($record) > 0),
            TextEntry::make('ai_failures')
                ->label(static::fieldLabel('ai_failures'))
                ->visible(static fn (SupportConversation $record): bool => $record->ai_failures > 0),
            TextEntry::make('handoff_reason')
                ->label(static::fieldLabel('handoff_reason'))
                ->formatStateUsing(static fn (SupportHandoffReason $state): string => static::enumLabel($state))
                ->visible(static fn (SupportConversation $record): bool => $record->handoff_reason !== null),
            TextEntry::make('created_at')
                ->label(static::fieldLabel('opened'))
                ->dateTime(),
        ];
    }

    /**
     * The status, and how long an open conversation has waited on staff.
     */
    protected static function statusLabel(SupportConversationStatus $status, SupportConversation $record): string
    {
        $label = static::enumLabel($status);

        if ($status !== SupportConversationStatus::OPEN || $record->waiting_since === null) {
            return $label;
        }

        $waiting = __('magic-starter::admin_support.waiting', [
            'time' => $record->waiting_since->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE, short: true),
        ]);

        return "{$label} · {$waiting}";
    }

    /**
     * The shipped label of a topic; a topic the application added falls back to its own key.
     */
    protected static function topicLabel(string $topic): string
    {
        $key = "magic-starter::support.topics.{$topic}";

        return trans()->has($key) ? (string) __($key) : $topic;
    }

    /**
     * How many AI messages the thread carries, counted once per record instance.
     *
     * The details entry reads it for its visibility and its state, so a render
     * would otherwise count twice. Keyed weakly by the record, so a poll, which
     * hydrates a fresh record, counts again and nothing outlives the request.
     */
    protected static function aiTurns(SupportConversation $conversation): int
    {
        static::$aiTurns ??= new WeakMap;

        return static::$aiTurns[$conversation] ??= $conversation->messages()
            ->where('sender_type', SupportSenderType::AI->value)
            ->where('kind', SupportMessageKind::MESSAGE->value)
            ->count();
    }

    /**
     * One message as the Blade needs it: which bubble, which side, and the line under it.
     *
     * @return array{variant: string, out: bool, body: string, avatar: string, chip: ?string, meta: list<string>}
     */
    protected static function threadItem(SupportMessage $message, SupportConversation $conversation): array
    {
        $variant = match (true) {
            $message->kind === SupportMessageKind::ACTIVITY,
            $message->sender_type === SupportSenderType::SYSTEM => 'event',
            $message->kind === SupportMessageKind::NOTE => 'note',
            $message->sender_type === SupportSenderType::AI => 'ai',
            $message->sender_type === SupportSenderType::STAFF => 'staff',
            default => 'customer',
        };
        $name = static::senderName($message, $conversation, $variant);
        $time = static::messageTime($message);

        return [
            'variant' => $variant,
            'out' => in_array($variant, ['ai', 'staff', 'note'], true),
            'body' => $message->body,
            'avatar' => $variant === 'ai' ? "\u{2726}" : static::initials($name),
            'chip' => $variant === 'ai' ? static::authorRole('ai') : null,
            'meta' => match ($variant) {
                'ai' => array_values(array_filter([static::confidenceLabel($message), $time])),
                'note' => [
                    static::authorRole('internal_note'),
                    $name,
                    $time,
                    (string) __('magic-starter::admin_support.note_hidden'),
                ],
                'event' => [$time],
                default => [$name, $time],
            },
        ];
    }

    /**
     * Who wrote a message: the account name, else what the conversation knows of the opener, else the role.
     */
    protected static function senderName(
        SupportMessage $message,
        SupportConversation $conversation,
        string $variant,
    ): string {
        if ($variant === 'ai') {
            return SupportMessageResource::aiName();
        }

        $name = $message->sender?->getAttribute('name');

        if (filled($name)) {
            return (string) $name;
        }

        if ($variant !== 'customer') {
            return static::authorRole($variant === 'note' ? 'internal_note' : 'staff');
        }

        $opener = $conversation->name ?: Str::before((string) $conversation->email, '@');

        return filled($opener) ? (string) $opener : static::authorRole('customer');
    }

    protected static function initials(string $name): string
    {
        return Str::of($name)
            ->squish()
            ->explode(' ')
            ->take(2)
            ->map(static fn (string $word): string => Str::upper(Str::substr($word, 0, 1)))
            ->implode('');
    }

    /**
     * The clock time of today's messages, and the date too for an older one.
     */
    protected static function messageTime(SupportMessage $message): string
    {
        return $message->created_at?->isToday() === true
            ? $message->created_at->format('H:i')
            : (string) $message->created_at?->format('j M H:i');
    }

    /**
     * What the AI wrote about its own certainty, when it recorded any.
     */
    protected static function confidenceLabel(SupportMessage $message): ?string
    {
        $confidence = $message->meta['confidence'] ?? null;

        return is_numeric($confidence)
            ? (string) __('magic-starter::admin_support.ai.confidence', ['value' => $confidence])
            : null;
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
