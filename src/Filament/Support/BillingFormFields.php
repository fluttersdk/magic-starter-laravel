<?php

namespace FlutterSdk\MagicStarter\Filament\Support;

use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use FlutterSdk\MagicStarter\Support\BillingCatalogue;

/**
 * The form fields a billing write asks for, shared by the Billing tab and the
 * "Grant a plan" action on the billing lists, so a plan is picked, a reason is
 * bounded and an expiry is read the same way wherever a grant is made.
 */
class BillingFormFields
{
    /**
     * The plan picker: the catalogue's tiers when it publishes a ranking, a free
     * text id otherwise, which is what the contract accepts in each case.
     */
    public static function plan(): Select|TextInput
    {
        $label = __('magic-starter::admin_billing.fields.plan');
        $tiers = BillingCatalogue::tiers();

        if ($tiers === []) {
            return TextInput::make('plan')
                ->label($label)
                ->required()
                ->maxLength(255);
        }

        return Select::make('plan')
            ->label($label)
            ->options(array_map(
                static fn (array $tier): string => is_string($tier['name'] ?? null)
                    ? $tier['name']
                    : (string) $tier['id'],
                $tiers,
            ))
            ->required();
    }

    public static function reason(): Textarea
    {
        return Textarea::make('reason')
            ->label(__('magic-starter::admin_billing.fields.reason'))
            ->required()
            ->maxLength(500);
    }

    /**
     * The optional last day of a grant; empty keeps it until a revoke.
     */
    public static function expiresAt(): DatePicker
    {
        return DatePicker::make('expires_at')
            ->label(__('magic-starter::admin_billing.fields.expires_at'))
            ->helperText(__('magic-starter::admin_billing.fields.expires_at_help'))
            ->after('today');
    }

    /**
     * A date picked in the form as the end of that day, or null when empty: a
     * grant that "expires after" a day lasts through it.
     */
    public static function endOfDay(mixed $date): ?CarbonImmutable
    {
        return is_string($date) && $date !== '' ? CarbonImmutable::parse($date)->endOfDay() : null;
    }
}
