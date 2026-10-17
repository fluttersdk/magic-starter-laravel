<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\AppReleases\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use FlutterSdk\MagicStarter\Enums\ReleaseItemType;
use FlutterSdk\MagicStarter\Enums\ReleaseStatus;

/**
 * The release form: the version, its status and dates, and the notes as a list of typed entries.
 *
 * The enum selects take plain arrays of translated options, since the enums carry no Filament
 * interface (Filament is optional). The Repeater keeps its default dehydration, which hands the
 * contract a list; the contract strips the locales left blank.
 */
class AppReleaseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('version')
                    ->label(__('magic-starter::admin_misc.releases.fields.version'))
                    ->placeholder('1.2.0')
                    ->required()
                    ->maxLength(32),
                Select::make('status')
                    ->label(__('magic-starter::admin_misc.releases.fields.status'))
                    ->options(static::options(ReleaseStatus::cases()))
                    ->default(ReleaseStatus::DRAFT->value)
                    ->required(),
                DateTimePicker::make('published_at')
                    ->label(__('magic-starter::admin_misc.releases.fields.published_at')),
                TextInput::make('ios_build')
                    ->label(__('magic-starter::admin_misc.releases.fields.ios_build'))
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(2147483647),
                TextInput::make('android_build')
                    ->label(__('magic-starter::admin_misc.releases.fields.android_build'))
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(2147483647),
                Repeater::make('items')
                    ->label(__('magic-starter::admin_misc.releases.fields.items'))
                    ->schema([
                        Select::make('type')
                            ->label(__('magic-starter::admin_misc.releases.fields.type'))
                            ->options(static::options(ReleaseItemType::cases()))
                            ->required(),
                        ...static::textInputs(),
                    ])
                    ->minItems(1)
                    ->maxItems(50)
                    ->defaultItems(1)
                    ->reorderable()
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Translated select options keyed by the stored value.
     *
     * @param  list<ReleaseStatus|ReleaseItemType>  $cases
     * @return array<string, string>
     */
    protected static function options(array $cases): array
    {
        return collect($cases)
            ->mapWithKeys(static fn (ReleaseStatus|ReleaseItemType $case): array => [$case->value => $case->label()])
            ->all();
    }

    /**
     * One input per supported locale, the default locale required.
     *
     * @return list<TextInput>
     */
    protected static function textInputs(): array
    {
        $defaultLocale = (string) config('magic-starter.defaults.locale');

        return collect((array) config('magic-starter.supported_locales'))
            ->map(static fn (string $locale): TextInput => TextInput::make('text.' . $locale)
                ->label(__('magic-starter::admin_misc.releases.fields.text', [
                    'locale' => mb_strtoupper($locale),
                ]))
                ->required($locale === $defaultLocale)
                ->maxLength(500))
            ->values()
            ->all();
    }
}
