<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\AiPrompts;

use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FlutterSdk\MagicStarter\Contracts\SavesAiPromptOverrides;
use FlutterSdk\MagicStarter\Enums\AiPromptOverrideStatus;
use FlutterSdk\MagicStarter\Filament\AdminNavigationGroup;
use FlutterSdk\MagicStarter\Filament\Resources\AiPrompts\Pages\CreateAiPromptOverride;
use FlutterSdk\MagicStarter\Filament\Resources\AiPrompts\Pages\EditAiPromptOverride;
use FlutterSdk\MagicStarter\Filament\Resources\AiPrompts\Pages\ListAiPromptOverrides;
use FlutterSdk\MagicStarter\Filament\Resources\AiPrompts\Schemas\AiPromptOverrideForm;
use FlutterSdk\MagicStarter\Filament\Resources\MagicStarterResource;
use FlutterSdk\MagicStarter\Filament\Support\ContractAction;
use FlutterSdk\MagicStarter\Models\AiPromptOverride;
use FlutterSdk\MagicStarter\Support\AiPrompts\PromptDefinition;
use FlutterSdk\MagicStarter\Support\AiPrompts\PromptRegistry;
use FlutterSdk\MagicStarter\Support\AiPrompts\RequiredPhrases;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The prompt workbench: staff replace the body of a prompt the host registered in code.
 *
 * Writes leave through {@see SavesAiPromptOverrides} and the delete through the edit page's
 * `promoted` header action, never through Filament's own save. The boundary block is code
 * and is not editable here. There is no bulk action on the table: a bulk delete would skip
 * the contract.
 *
 * A row is judged against the registration as the registry answers it right now: `orphaned`
 * once the host no longer registers the key, `stale` once the default changed after the edit,
 * `incomplete` once the body lacks a required phrase. The resolver ignores a stale or an
 * incomplete override until it is re-saved.
 */
class AiPromptOverrideResource extends MagicStarterResource
{
    /**
     * Shown for a column a row cannot answer (the label of a key nobody registers any more).
     */
    public const ABSENT = '-';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Ai;

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'ai-prompts';

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
                    ->state(static fn (AiPromptOverride $record): AiPromptOverrideStatus => static::status($record))
                    ->badge()
                    ->formatStateUsing(static fn (AiPromptOverrideStatus $state): string => $state->label())
                    ->color(static fn (AiPromptOverrideStatus $state): string => $state->color()),
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
        return app(PromptRegistry::class)->find($key);
    }

    /**
     * Whether the resolver would apply the override: registered, written against the live
     * code default, and still keeping every required phrase.
     */
    public static function status(AiPromptOverride $override): AiPromptOverrideStatus
    {
        $definition = static::definition($override->key);

        if ($definition === null) {
            return AiPromptOverrideStatus::ORPHANED;
        }

        if ($definition->defaultHash() !== $override->default_hash) {
            return AiPromptOverrideStatus::STALE;
        }

        return RequiredPhrases::missing($override->body, $definition->requiredPhrases) === []
            ? AiPromptOverrideStatus::CURRENT
            : AiPromptOverrideStatus::INCOMPLETE;
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
