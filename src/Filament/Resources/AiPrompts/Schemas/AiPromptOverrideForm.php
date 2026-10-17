<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\AiPrompts\Schemas;

use Closure;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use FlutterSdk\MagicStarter\Filament\Resources\AiPrompts\AiPromptOverrideResource;
use FlutterSdk\MagicStarter\Filament\Resources\AiRuns\AiRunResource;
use FlutterSdk\MagicStarter\Models\AiPromptOverride;
use FlutterSdk\MagicStarter\Support\AiPrompts\PromptDefinition;
use FlutterSdk\MagicStarter\Support\AiPrompts\PromptRegistry;
use FlutterSdk\MagicStarter\Support\AiPrompts\RequiredPhrases;
use Illuminate\Database\Eloquent\Model;
use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;

/**
 * The prompt workbench form: pick a registered prompt, edit its body, and read what the code says.
 *
 * Only the body is a field. The boundary block and the code default are read-only entries, and
 * `loaded_default_hash` is hidden state that tells the contract which code default the editor
 * opened on; the contract compares it with the live one and stores its own hash, never this one.
 * On edit the diff of the code default against the stored body is shown, and a stale or an
 * incomplete row asks for a tick that the diff was reviewed before it can be saved.
 */
class AiPromptOverrideForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('key')
                    ->label(__('magic-starter::admin_ai.prompts.fields.key'))
                    ->options(static fn (): array => static::unoverriddenOptions())
                    ->required()
                    ->live()
                    ->afterStateUpdated(static function (?string $state, Set $set): void {
                        $definition = $state === null ? null : AiPromptOverrideResource::definition($state);

                        $set('body', $definition?->body());
                        $set('loaded_default_hash', $definition?->defaultHash());
                    })
                    ->visible(static fn (string $operation): bool => $operation === 'create'),
                Hidden::make('loaded_default_hash'),
                Textarea::make('body')
                    ->label(__('magic-starter::admin_ai.prompts.fields.body'))
                    ->required()
                    ->rows(24)
                    ->extraInputAttributes(['style' => 'font-family: ui-monospace, monospace'])
                    ->rules([
                        static fn (Get $get, ?Model $record): Closure => static::keepsRequiredPhrases(
                            static::definitionFor($record, $get('key')),
                        ),
                    ])
                    ->columnSpanFull(),
                ViewEntry::make('diff')
                    ->hiddenLabel()
                    ->view(static::diffView())
                    ->viewData(static fn (?Model $record): array => [
                        'diff' => static::diff($record),
                        'heading' => __('magic-starter::admin_ai.prompts.fields.diff'),
                        'identical' => __('magic-starter::admin_ai.prompts.diff.identical'),
                    ])
                    ->visible(static fn (?Model $record): bool => static::definitionFor($record) !== null)
                    ->columnSpanFull(),
                Checkbox::make('reviewed')
                    ->label(__('magic-starter::admin_ai.prompts.fields.reviewed'))
                    ->accepted()
                    ->dehydrated(false)
                    ->visible(static fn (?Model $record): bool => $record instanceof AiPromptOverride
                        && AiPromptOverrideResource::status($record)->needsReview()),
                static::codeBlock('default_body', static fn (PromptDefinition $prompt): string => $prompt->body())
                    ->copyable(),
                static::codeBlock('boundary', static fn (PromptDefinition $prompt): string => $prompt->boundary()),
            ]);
    }

    /**
     * The registered prompts that have no override yet, labelled for the select.
     *
     * @return array<string, string>
     */
    protected static function unoverriddenOptions(): array
    {
        $overridden = AiPromptOverride::query()->pluck('key')->all();

        return collect(app(PromptRegistry::class)->all())
            ->reject(static fn (PromptDefinition $definition, string $key): bool => in_array($key, $overridden, true))
            ->map(static fn (PromptDefinition $definition): string => $definition->label)
            ->all();
    }

    /**
     * The view that draws the diff, registered by the service provider as `magic-starter`.
     *
     * Larastan boots no package provider, so a literal at the call site is rejected as a `view-string`
     * it cannot resolve; the namespace is real at runtime.
     */
    protected static function diffView(): string
    {
        return 'magic-starter::filament.ai-prompt-diff';
    }

    /**
     * A read-only block of code text, shown once a prompt is known.
     *
     * @param  Closure(PromptDefinition): string  $text
     */
    protected static function codeBlock(string $name, Closure $text): TextEntry
    {
        return TextEntry::make($name)
            ->label(__("magic-starter::admin_ai.prompts.fields.{$name}"))
            ->state(static function (Get $get, ?Model $record) use ($text): ?string {
                $definition = static::definitionFor($record, $get('key'));

                return $definition === null ? null : $text($definition);
            })
            ->visible(static fn (Get $get, ?Model $record): bool => static::definitionFor(
                $record,
                $get('key'),
            ) !== null)
            ->fontFamily(FontFamily::Mono)
            ->extraAttributes(AiRunResource::WRAP_ANYWHERE)
            ->columnSpanFull();
    }

    /**
     * The prompt being edited: the record's own, or on create the one chosen in the select.
     */
    protected static function definitionFor(?Model $record, mixed $chosenKey = null): ?PromptDefinition
    {
        $key = $record instanceof AiPromptOverride ? $record->key : $chosenKey;

        return is_string($key) ? AiPromptOverrideResource::definition($key) : null;
    }

    /**
     * A rule that fails, on the body field, naming every phrase the body dropped, so the operator
     * sees it before the save runs. The contract refuses the same body again as a backstop.
     */
    protected static function keepsRequiredPhrases(?PromptDefinition $definition): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($definition): void {
            $missing = $definition === null
                ? []
                : RequiredPhrases::missing((string) $value, $definition->requiredPhrases);

            if ($missing === []) {
                return;
            }

            $fail((string) __('magic-starter::admin_ai.refusals.missing_phrases', [
                'phrases' => RequiredPhrases::describe($missing),
            ]));
        };
    }

    /**
     * The unified diff of the code default against the stored body, empty when they are the same text.
     */
    protected static function diff(?Model $record): string
    {
        if (! $record instanceof AiPromptOverride) {
            return '';
        }

        $default = AiPromptOverrideResource::definition($record->key)?->body();

        if ($default === null || $default === $record->body) {
            return '';
        }

        $header = '--- ' . __('magic-starter::admin_ai.prompts.diff.from')
            . "\n+++ " . __('magic-starter::admin_ai.prompts.diff.to') . "\n";

        return (new Differ(new UnifiedDiffOutputBuilder($header, true)))->diff($default, $record->body);
    }
}
