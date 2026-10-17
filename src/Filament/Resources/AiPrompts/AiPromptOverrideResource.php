<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\AiPrompts;

use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FlutterSdk\MagicStarter\Contracts\SavesAiPromptOverrides;
use FlutterSdk\MagicStarter\Filament\Resources\AiPrompts\Pages\CreateAiPromptOverride;
use FlutterSdk\MagicStarter\Filament\Resources\AiPrompts\Pages\EditAiPromptOverride;
use FlutterSdk\MagicStarter\Filament\Resources\AiPrompts\Pages\ListAiPromptOverrides;
use FlutterSdk\MagicStarter\Filament\Resources\AiPrompts\Schemas\AiPromptOverrideForm;
use FlutterSdk\MagicStarter\Filament\Resources\MagicStarterResource;
use FlutterSdk\MagicStarter\Filament\Support\ContractAction;
use FlutterSdk\MagicStarter\Models\AiPromptOverride;
use FlutterSdk\MagicStarter\Support\AiPrompts\PromptDefinition;
use FlutterSdk\MagicStarter\Support\AiPrompts\PromptRegistry;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * The prompt workbench: staff replace the body of a prompt the host registered in code.
 *
 * Writes leave through {@see SavesAiPromptOverrides} and the delete through the edit page's
 * `promoted` header action, never through Filament's own save. The boundary block is code
 * and is not editable here. There is no bulk action on the table: a bulk delete would skip
 * the contract.
 *
 * A row is judged against the code default as the registry answers it right now: `stale`
 * once the default changed after the edit (the resolver ignores such an override until it
 * is re-saved), `orphaned` once the host no longer registers the key.
 */
class AiPromptOverrideResource extends MagicStarterResource
{
    public const STATUS_CURRENT = 'current';

    public const STATUS_STALE = 'stale';

    public const STATUS_ORPHANED = 'orphaned';

    /**
     * Shown for a column a row cannot answer (the label of a key nobody registers any more).
     */
    public const ABSENT = '-';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static ?string $recordTitleAttribute = 'key';

    protected static function resolveModel(): string
    {
        return AiPromptOverride::class;
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('magic-starter::admin_ai.prompts.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return (string) __('magic-starter::admin_ai.prompts.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('magic-starter::admin_ai.prompts.plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return AiPromptOverrideForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')
                    ->label(__('magic-starter::admin_ai.prompts.columns.key'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('label')
                    ->label(__('magic-starter::admin_ai.prompts.columns.label'))
                    ->state(static fn (AiPromptOverride $record): ?string => static::definition($record->key)?->label)
                    ->placeholder(static::ABSENT),
                TextColumn::make('status')
                    ->label(__('magic-starter::admin_ai.prompts.columns.status'))
                    ->state(static fn (AiPromptOverride $record): string => static::status($record))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => (string) __(
                        "magic-starter::admin_ai.prompts.statuses.{$state}",
                    ))
                    ->color(static fn (string $state): string => match ($state) {
                        static::STATUS_CURRENT => 'success',
                        static::STATUS_STALE => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('updated_at')
                    ->label(__('magic-starter::admin_ai.prompts.columns.updated_at'))
                    ->dateTime()
                    ->sinceTooltip()
                    ->sortable(),
            ])
            ->defaultSort('key');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiPromptOverrides::route('/'),
            'create' => CreateAiPromptOverride::route('/create'),
            'edit' => EditAiPromptOverride::route('/{record}/edit'),
        ];
    }

    /**
     * The registered prompt under a key, or null once the host stopped registering it.
     */
    public static function definition(string $key): ?PromptDefinition
    {
        return app(PromptRegistry::class)->all()[$key] ?? null;
    }

    /**
     * Whether the override still matches the code default it was written against.
     *
     * @return self::STATUS_*
     */
    public static function status(AiPromptOverride $override): string
    {
        $definition = static::definition($override->key);

        return match (true) {
            $definition === null => static::STATUS_ORPHANED,
            $definition->defaultHash() !== $override->default_hash => static::STATUS_STALE,
            default => static::STATUS_CURRENT,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function createRecordUsing(array $data, Authenticatable $actor): Model
    {
        return static::saveThroughContract($data, $actor, null);
    }

    /**
     * The edit form has no key field, so the key is the record's own and never form state.
     *
     * @param  array<string, mixed>  $data
     */
    public static function updateRecordUsing(Model $record, array $data, Authenticatable $actor): Model
    {
        /** @var AiPromptOverride $record */
        return static::saveThroughContract([...$data, 'key' => $record->key], $actor, $record);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected static function saveThroughContract(array $input, Authenticatable $actor, ?Model $subject): Model
    {
        /** @var AiPromptOverride $override */
        $override = ContractAction::run(
            null,
            static fn (): AiPromptOverride => app(SavesAiPromptOverrides::class)->save($actor, $input),
            'ai_prompt.saved',
            $subject,
        );

        return $override;
    }
}
