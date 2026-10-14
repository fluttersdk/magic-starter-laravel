<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\SupportFeedback;

use BackedEnum;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use FlutterSdk\MagicStarter\Filament\Resources\MagicStarterResource;
use FlutterSdk\MagicStarter\Filament\Resources\SupportConversations\SupportConversationResource;
use FlutterSdk\MagicStarter\Filament\Resources\SupportFeedback\Pages\ListSupportFeedback;
use FlutterSdk\MagicStarter\Models\SupportFeedback;
use Illuminate\Database\Eloquent\Builder;

/**
 * The in-app 0-10 feedback answers, read-only: an answer is the customer's and
 * is never edited from the panel. A low score is worked in the conversation it
 * opened, which each row links to.
 *
 * The score bands are the usual ones for a 0-10 question (0 to 6, 7 and 8,
 * 9 and 10); a dismissal is a row with no score.
 */
class SupportFeedbackResource extends MagicStarterResource
{
    /**
     * The scores each band filter admits, both ends inclusive.
     *
     * @var array<string, array{0: int, 1: int}>
     */
    protected const BANDS = [
        'detractor' => [
            0,
            6,
        ],
        'passive' => [
            7,
            8,
        ],
        'promoter' => [
            9,
            10,
        ],
    ];

    protected const DISMISSED = 'dismissed';

    protected const ABSENT = '-';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFaceSmile;

    protected static ?string $slug = 'support-feedback';

    protected static function resolveModel(): string
    {
        return SupportFeedback::class;
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('magic-starter::admin_support.feedback.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return (string) __('magic-starter::admin_support.feedback.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('magic-starter::admin_support.feedback.plural_model_label');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with([
                'user',
                'conversation',
            ]))
            ->columns([
                TextColumn::make('score')
                    ->label(static::columnLabel('score'))
                    ->badge()
                    ->color(static fn (?int $state): string => static::scoreColor($state))
                    ->placeholder((string) __('magic-starter::admin_support.feedback.dismissed'))
                    ->sortable(),
                TextColumn::make('comment')
                    ->label(static::columnLabel('comment'))
                    ->limit(80)
                    ->wrap()
                    ->placeholder(static::ABSENT),
                TextColumn::make('user.email')
                    ->label(static::columnLabel('user'))
                    ->placeholder(static::ABSENT)
                    ->searchable(),
                TextColumn::make('conversation.number')
                    ->label(static::columnLabel('conversation'))
                    ->prefix('#')
                    ->url(static fn (SupportFeedback $record): ?string => static::conversationUrl($record))
                    ->placeholder(static::ABSENT),
                TextColumn::make('created_at')
                    ->label(static::columnLabel('created_at'))
                    ->dateTime()
                    ->sinceTooltip()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('score')
                    ->label(static::columnLabel('score'))
                    ->options(static fn (): array => collect([...array_keys(static::BANDS), static::DISMISSED])
                        ->mapWithKeys(static fn (string $band): array => [
                            $band => (string) __("magic-starter::admin_support.feedback.bands.{$band}"),
                        ])
                        ->all())
                    ->query(static fn (Builder $query, array $data): Builder => static::whereBand(
                        $query,
                        $data['value'] ?? null,
                    )),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupportFeedback::route('/'),
        ];
    }

    /**
     * @param  Builder<SupportFeedback>  $query
     * @return Builder<SupportFeedback>
     */
    protected static function whereBand(Builder $query, ?string $band): Builder
    {
        if ($band === static::DISMISSED) {
            return $query->whereNull('score');
        }

        if (! isset(static::BANDS[$band])) {
            return $query;
        }

        return $query->whereBetween('score', static::BANDS[$band]);
    }

    /**
     * The thread a low score opened, when the inbox is mounted on this panel.
     */
    protected static function conversationUrl(SupportFeedback $record): ?string
    {
        $mounted = in_array(
            SupportConversationResource::class,
            Filament::getCurrentOrDefaultPanel()?->getResources() ?? [],
            true,
        );

        if ($record->conversation === null || ! $mounted) {
            return null;
        }

        return SupportConversationResource::getUrl('view', [
            'record' => $record->conversation,
        ]);
    }

    protected static function scoreColor(?int $score): string
    {
        return match (true) {
            $score === null => 'gray',
            $score <= static::BANDS['detractor'][1] => 'danger',
            $score <= static::BANDS['passive'][1] => 'warning',
            default => 'success',
        };
    }

    protected static function columnLabel(string $column): string
    {
        return (string) __("magic-starter::admin_support.feedback.columns.{$column}");
    }
}
