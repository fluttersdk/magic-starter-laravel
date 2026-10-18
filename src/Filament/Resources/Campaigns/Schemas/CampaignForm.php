<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\Campaigns\Schemas;

use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use FlutterSdk\MagicStarter\Enums\ProductType;
use FlutterSdk\MagicStarter\Filament\Resources\Campaigns\CampaignResource;
use FlutterSdk\MagicStarter\Models\Campaign;
use FlutterSdk\MagicStarter\Support\BillingCatalogue;
use Illuminate\Database\Eloquent\Model;

/**
 * The campaign form: the window, the products it discounts, the copy per locale and the
 * Stripe promotion code.
 *
 * Only the shape is checked here. The contract owns every rule that needs the database or
 * Stripe (an overlapping window, a code Checkout would refuse), and the discount is read
 * back from the last check rather than entered.
 */
class CampaignForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('magic-starter::admin_misc.campaigns.fields.name'))
                    ->required()
                    ->maxLength(255),
                Toggle::make('enabled')
                    ->label(__('magic-starter::admin_misc.campaigns.fields.enabled'))
                    ->default(false),
                DateTimePicker::make('starts_at')
                    ->label(__('magic-starter::admin_misc.campaigns.fields.starts_at'))
                    ->seconds(false)
                    ->required(),
                DateTimePicker::make('ends_at')
                    ->label(__('magic-starter::admin_misc.campaigns.fields.ends_at'))
                    ->seconds(false)
                    ->afterOrEqual('starts_at')
                    ->required(),
                CheckboxList::make('products')
                    ->label(__('magic-starter::admin_misc.campaigns.fields.products'))
                    ->options(static fn (): array => static::productOptions())
                    ->required()
                    ->minItems(1)
                    ->columns(2),
                ...static::localisedInputs(
                    'headline',
                    static fn (string $statePath): TextInput => TextInput::make($statePath)->maxLength(120),
                ),
                ...static::localisedInputs(
                    'body',
                    static fn (string $statePath): Textarea => Textarea::make($statePath)->rows(3)->maxLength(500),
                ),
                TextInput::make('stripe_promotion_code_id')
                    ->label(__('magic-starter::admin_misc.campaigns.fields.stripe_promotion_code_id'))
                    ->placeholder('promo_...')
                    ->required()
                    ->maxLength(255),
                TextEntry::make('discount')
                    ->label(__('magic-starter::admin_misc.campaigns.fields.discount'))
                    ->state(static fn (?Model $record): ?string => static::discountOf($record))
                    ->visible(static fn (?Model $record): bool => static::discountOf($record) !== null),
            ]);
    }

    /**
     * The products a campaign may discount, keyed by catalogue key: sellable subscriptions that
     * have a Stripe price, which is the set the contract accepts.
     *
     * @return array<string, string>
     */
    protected static function productOptions(): array
    {
        $options = [];

        foreach (BillingCatalogue::products() as $product) {
            if ($product['type'] !== ProductType::SUBSCRIPTION
                || ! $product['sellable']
                || $product['refs']['stripe_price'] === null
            ) {
                continue;
            }

            $options[$product['key']] = sprintf(
                '%s (%s, %s)',
                $product['key'],
                $product['tier'],
                $product['cycle']?->value,
            );
        }

        return $options;
    }

    /**
     * One input per supported locale, the default locale required.
     *
     * @param  Closure(string): (TextInput|Textarea)  $input  Builds the bare input for a state path.
     * @return list<TextInput|Textarea>
     */
    protected static function localisedInputs(string $field, Closure $input): array
    {
        $defaultLocale = (string) config('magic-starter.defaults.locale');

        return collect((array) config('magic-starter.supported_locales'))
            ->map(static fn (string $locale): TextInput|Textarea => $input($field . '.' . $locale)
                ->label(__('magic-starter::admin_misc.campaigns.fields.' . $field, [
                    'locale' => mb_strtoupper($locale),
                ]))
                ->required($locale === $defaultLocale))
            ->values()
            ->all();
    }

    protected static function discountOf(?Model $record): ?string
    {
        return $record instanceof Campaign ? CampaignResource::discountLabel($record) : null;
    }
}
