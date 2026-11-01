<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\SupportArticles;

use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FlutterSdk\MagicStarter\Contracts\TranslatesSupportArticles;
use FlutterSdk\MagicStarter\Contracts\ValidatesSupportArticleMarkdown;
use FlutterSdk\MagicStarter\Enums\SupportArticleVisibility;
use FlutterSdk\MagicStarter\Filament\AdminNavigationGroup;
use FlutterSdk\MagicStarter\Filament\Resources\MagicStarterResource;
use FlutterSdk\MagicStarter\Filament\Resources\SupportArticles\Pages\CreateSupportArticle;
use FlutterSdk\MagicStarter\Filament\Resources\SupportArticles\Pages\EditSupportArticle;
use FlutterSdk\MagicStarter\Filament\Resources\SupportArticles\Pages\ListSupportArticles;
use FlutterSdk\MagicStarter\Filament\Support\ContractAction;
use FlutterSdk\MagicStarter\Listeners\RemoveSupportArticleImages;
use FlutterSdk\MagicStarter\Models\SupportArticle;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use UnitEnum;

/**
 * The knowledge base: the articles the AI agent, the public FAQ page and in-app suggestions read.
 *
 * - One tab per supported locale. The default locale is required; another locale left empty is
 *   queued for a draft translation when the host bound {@see TranslatesSupportArticles}.
 * - A locale staff write themselves is approved on the spot. A translated draft is never approved
 *   by a save, only by that locale's "Approve" action, which also keeps a machine translation
 *   away from readers until a person has read it.
 * - Only a public article may carry an image: the editor offers the attachment toolbar for no
 *   other visibility, and the body is refused when it holds image syntax anyway.
 * - There is no contract for an article, so the writes live here and are still audited through
 *   {@see ContractAction}.
 */
class SupportArticleResource extends MagicStarterResource
{
    /**
     * A locale's text in `locale_state` while it waits for a person to approve it.
     */
    protected const DRAFT = 'draft';

    /**
     * Markdown image syntax, inline or by reference, and a raw HTML image.
     */
    protected const IMAGE_SYNTAX = '/!\[[^\]]*\]\(|!\[[^\]]*\]\[|<img\b/i';

    protected const ABSENT = '-';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Support;

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'support-articles';

    protected static function resolveModel(): string
    {
        return SupportArticle::class;
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('magic-starter::admin_support.articles.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return (string) __('magic-starter::admin_support.articles.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('magic-starter::admin_support.articles.plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('slug')
                    ->label(static::text('fields.slug'))
                    ->required()
                    ->alphaDash()
                    ->maxLength(160)
                    ->unique(ignoreRecord: true),
                TextInput::make('section')
                    ->label(static::text('fields.section'))
                    ->required()
                    ->maxLength(64),
                TextInput::make('sort')
                    ->label(static::text('fields.sort'))
                    ->required()
                    ->integer()
                    ->minValue(0)
                    ->default(0),
                Select::make('visibility')
                    ->label(static::text('fields.visibility'))
                    ->options(static::visibilityOptions())
                    ->default(SupportArticleVisibility::PUBLIC->value)
                    ->required()
                    ->live(),
                DateTimePicker::make('published_at')
                    ->label(static::text('fields.published_at'))
                    ->helperText(static::text('fields.published_at_hint')),
                Tabs::make('locales')
                    ->tabs(array_map(static::localeTab(...), static::locales()))
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        $default = static::defaultLocale();

        return $table
            ->columns([
                TextColumn::make("question.{$default}")
                    ->label(static::text('columns.question'))
                    ->limit(80)
                    ->wrap(),
                TextColumn::make('section')
                    ->label(static::text('columns.section'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('visibility')
                    ->label(static::text('columns.visibility'))
                    ->badge()
                    ->formatStateUsing(static fn (SupportArticleVisibility $state): string => static::text(
                        "visibility.{$state->value}",
                    ))
                    ->color(static fn (SupportArticleVisibility $state): string => match ($state) {
                        SupportArticleVisibility::PUBLIC => 'success',
                        SupportArticleVisibility::AI_ONLY => 'info',
                        SupportArticleVisibility::STAFF_ONLY => 'gray',
                    })
                    ->sortable(),
                ...array_map(static::stateColumn(...), static::locales()),
                TextColumn::make('published_at')
                    ->label(static::text('columns.published_at'))
                    ->dateTime()
                    ->placeholder(static::ABSENT)
                    ->sortable(),
            ])
            ->recordActions(array_map(static::approveAction(...), static::locales()))
            ->defaultSort(static fn (Builder $query): Builder => $query->orderBy('section')->orderBy('sort'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupportArticles::route('/'),
            'create' => CreateSupportArticle::route('/create'),
            'edit' => EditSupportArticle::route('/{record}/edit'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function createRecordUsing(array $data, Authenticatable $actor): Model
    {
        /** @var SupportArticle $article */
        $article = ContractAction::run(
            null,
            static fn (): SupportArticle => static::persist(new SupportArticle, $data),
            'support_article.created',
            null,
        );

        return $article;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function updateRecordUsing(Model $record, array $data, Authenticatable $actor): Model
    {
        /** @var SupportArticle $record */
        ContractAction::run(
            null,
            static fn (): SupportArticle => static::persist($record, $data),
            'support_article.updated',
            $record,
        );

        return $record->refresh();
    }

    /**
     * The row and page action that approves one locale's text.
     *
     * Offered only while that locale is a draft with text to read: an empty draft is still
     * waiting for its translator, and approving it would publish nothing.
     */
    public static function approveAction(string $locale): Action
    {
        $replace = ['locale' => mb_strtoupper($locale)];

        return Action::make("approve_{$locale}")
            ->label(static::text('actions.approve.label', $replace))
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(static::text('actions.approve.heading', $replace))
            ->modalDescription(static::text('actions.approve.description', $replace))
            ->successNotificationTitle(static::text('actions.approve.success', $replace))
            ->visible(static fn (?SupportArticle $record): bool => $record !== null
                && static::awaitsApproval($record, $locale))
            ->action(function (Action $action, SupportArticle $record) use ($locale): void {
                ContractAction::run(
                    $action,
                    static fn (): bool => $record->forceFill([
                        'locale_state' => [
                            ...$record->locale_state,
                            $locale => SupportArticle::LOCALE_APPROVED,
                        ],
                    ])->save(),
                    'support_article.approved',
                    $record,
                    ['locale' => $locale],
                );

                $action->success();
            });
    }

    /**
     * Save the form state: the text, then the state of every locale, then the translations to ask for.
     *
     * @param  array<string, mixed>  $data
     */
    protected static function persist(SupportArticle $article, array $data): SupportArticle
    {
        // 1. A locale staff left blank is not stored as an empty string a reader could mistake for text, and
        //    an uploaded image is stored by its disk path, not by the host the panel ran on.
        $question = array_filter((array) ($data['question'] ?? []), filled(...));
        $images = app(RemoveSupportArticleImages::class);
        $body = array_map(
            static fn (mixed $markdown): string => $images->relativeImages((string) $markdown),
            array_filter((array) ($data['body'] ?? []), filled(...)),
        );

        // 2. Decide each locale's state against what was stored, so an edit keeps a draft a draft.
        $previous = (array) $article->locale_state;
        $states = static::localeStates($body, $previous);

        $article->fill([
            ...Arr::only($data, ['slug', 'section', 'sort', 'visibility', 'published_at']),
            'question' => $question,
            'body' => $body,
            'locale_state' => $states,
        ])->save();

        // 3. Ask for a translation only for a locale that just became a draft; a saved row is what the job reads.
        foreach ($states as $locale => $state) {
            if ($state === static::DRAFT && ($previous[$locale] ?? null) !== static::DRAFT) {
                DB::afterCommit(static fn () => app(TranslatesSupportArticles::class)->queueDraft($article, $locale));
            }
        }

        return $article;
    }

    /**
     * The state of every locale that has text, or that waits for a translator to give it some.
     *
     * Text staff wrote is approved unless the locale is already a draft: editing a translated
     * draft is not approving it.
     *
     * @param  array<string, string>  $body  Non-empty text keyed by locale.
     * @param  array<string, string>  $previous  The states stored before this save.
     * @return array<string, string>
     */
    protected static function localeStates(array $body, array $previous): array
    {
        $states = [];

        foreach (static::locales() as $locale) {
            if (isset($body[$locale])) {
                $states[$locale] = $previous[$locale] ?? SupportArticle::LOCALE_APPROVED;
            } elseif (app()->bound(TranslatesSupportArticles::class)) {
                $states[$locale] = static::DRAFT;
            }
        }

        return $states;
    }

    protected static function awaitsApproval(SupportArticle $article, string $locale): bool
    {
        return ($article->locale_state[$locale] ?? null) === static::DRAFT && filled($article->body[$locale] ?? null);
    }

    protected static function localeTab(string $locale): Tab
    {
        $isDefault = $locale === static::defaultLocale();

        return Tab::make($locale)
            ->label(mb_strtoupper($locale))
            ->schema([
                TextInput::make("question.{$locale}")
                    ->label(static::text('fields.question'))
                    ->hint($isDefault ? null : static::text('fields.locale_hint', [
                        'locale' => mb_strtoupper(static::defaultLocale()),
                    ]))
                    ->required(static fn (Get $get): bool => $isDefault || filled($get("body.{$locale}")))
                    ->maxLength(255),
                MarkdownEditor::make("body.{$locale}")
                    ->label(static::text('fields.body'))
                    ->helperText(static::text('fields.body_hint'))
                    ->required($isDefault)
                    ->fileAttachmentsDisk(static fn (): string => static::articlesConfig('disk'))
                    ->fileAttachmentsDirectory(RemoveSupportArticleImages::DIRECTORY)
                    // Filament's own save follows the store with a public ACL call. On an
                    // S3-compatible CDN that is a PutObjectAcl, which replaces the image just
                    // stored with an empty body; the disk alone decides whether it is public.
                    ->saveUploadedFileAttachmentUsing(static fn (TemporaryUploadedFile $file): string|false => $file->store(
                        RemoveSupportArticleImages::DIRECTORY,
                        [
                            'disk' => static::articlesConfig('disk'),
                            'mimetype' => $file->getMimeType(),
                        ],
                    ))
                    ->fileAttachmentsAcceptedFileTypes([
                        'image/png',
                        'image/jpeg',
                        'image/webp',
                    ])
                    ->fileAttachmentsMaxSize(static fn (): int => (int) static::articlesConfig('image_max_kb'))
                    ->fileAttachments(static fn (Get $get): bool => $get('visibility')
                        === SupportArticleVisibility::PUBLIC->value)
                    ->rules([
                        static fn (Get $get): Closure => static function (
                            string $attribute,
                            mixed $value,
                            Closure $fail,
                        ) use ($get, $locale): void {
                            $visibility = SupportArticleVisibility::tryFrom((string) $get('visibility'));

                            if ($visibility === null) {
                                return;
                            }

                            foreach (static::bodyErrors((string) $value, $visibility, $locale) as $error) {
                                $fail($error);
                            }
                        },
                    ]),
            ]);
    }

    /**
     * Why the Markdown may not be saved for this visibility: the package's own image rule, then the host's check.
     *
     * @return list<string>
     */
    protected static function bodyErrors(string $markdown, SupportArticleVisibility $visibility, string $locale): array
    {
        $errors = [];

        if ($visibility !== SupportArticleVisibility::PUBLIC && preg_match(static::IMAGE_SYNTAX, $markdown) === 1) {
            $errors[] = static::text('refusals.image_in_non_public');
        }

        if (app()->bound(ValidatesSupportArticleMarkdown::class)) {
            $errors = [
                ...$errors,
                ...app(ValidatesSupportArticleMarkdown::class)->validate($markdown, $visibility, $locale),
            ];
        }

        return $errors;
    }

    protected static function stateColumn(string $locale): TextColumn
    {
        return TextColumn::make("locale_state.{$locale}")
            ->label(static::text('columns.locale_state', ['locale' => mb_strtoupper($locale)]))
            ->badge()
            ->formatStateUsing(static fn (?string $state): ?string => $state === null
                ? null
                : static::text("states.{$state}"))
            ->color(static fn (?string $state): string => $state === SupportArticle::LOCALE_APPROVED
                ? 'success'
                : 'warning')
            ->placeholder(static::ABSENT);
    }

    /**
     * @return array<string, string>
     */
    protected static function visibilityOptions(): array
    {
        $options = [];

        foreach (SupportArticleVisibility::cases() as $visibility) {
            $options[$visibility->value] = static::text("visibility.{$visibility->value}");
        }

        return $options;
    }

    /**
     * The locales the form has a tab for, and an approval for.
     *
     * @return list<string>
     */
    public static function locales(): array
    {
        return array_values((array) config('magic-starter.supported_locales'));
    }

    protected static function articlesConfig(string $key): string
    {
        return (string) config("magic-starter.support.articles.{$key}");
    }

    protected static function defaultLocale(): string
    {
        return (string) config('magic-starter.defaults.locale');
    }

    /**
     * @param  array<string, string>  $replace
     */
    protected static function text(string $key, array $replace = []): string
    {
        return (string) __("magic-starter::admin_support.articles.{$key}", $replace);
    }
}
