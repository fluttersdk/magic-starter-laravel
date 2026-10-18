<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\Campaigns;

use BackedEnum;
use Closure;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use FlutterSdk\MagicStarter\Contracts\CreatesCampaigns;
use FlutterSdk\MagicStarter\Contracts\UpdatesCampaigns;
use FlutterSdk\MagicStarter\Enums\BillingEventType;
use FlutterSdk\MagicStarter\Events\AdminActionPerformed;
use FlutterSdk\MagicStarter\Filament\Resources\Campaigns\Pages\CreateCampaign;
use FlutterSdk\MagicStarter\Filament\Resources\Campaigns\Pages\EditCampaign;
use FlutterSdk\MagicStarter\Filament\Resources\Campaigns\Pages\ListCampaigns;
use FlutterSdk\MagicStarter\Filament\Resources\Campaigns\Schemas\CampaignForm;
use FlutterSdk\MagicStarter\Filament\Resources\MagicStarterResource;
use FlutterSdk\MagicStarter\Models\BillingEvent;
use FlutterSdk\MagicStarter\Models\Campaign;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Laravel\Cashier\Cashier;

/**
 * The time-boxed Stripe promotion-code campaigns staff run.
 *
 * Writes leave through {@see CreatesCampaigns} and {@see UpdatesCampaigns}. There is no delete
 * and no bulk action: a campaign that should stop is switched off, which keeps the history of
 * what ran.
 */
class CampaignResource extends MagicStarterResource
{
    /**
     * The state path Filament's create and edit pages mount their form at.
     */
    protected const FORM_STATE_PATH = 'data';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $recordTitleAttribute = 'name';

    protected static function resolveModel(): string
    {
        return Campaign::class;
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('magic-starter::admin_misc.campaigns.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return (string) __('magic-starter::admin_misc.campaigns.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('magic-starter::admin_misc.campaigns.plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return CampaignForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('magic-starter::admin_misc.campaigns.columns.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('starts_at')
                    ->label(__('magic-starter::admin_misc.campaigns.columns.starts_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('ends_at')
                    ->label(__('magic-starter::admin_misc.campaigns.columns.ends_at'))
                    ->dateTime()
                    ->sortable(),
                IconColumn::make('enabled')
                    ->label(__('magic-starter::admin_misc.campaigns.columns.enabled'))
                    ->boolean()
                    ->sortable(),
                TextColumn::make('discount')
                    ->label(__('magic-starter::admin_misc.campaigns.columns.discount'))
                    ->state(static fn (Campaign $record): ?string => static::discountLabel($record))
                    ->placeholder('-'),
                TextColumn::make('campaign_checkouts')
                    ->label(__('magic-starter::admin_misc.campaigns.columns.campaign_checkouts'))
                    ->state(static fn (Campaign $record): int => static::redemptionCount($record))
                    ->numeric(),
            ])
            ->filters([
                Filter::make('active_now')
                    ->label(__('magic-starter::admin_misc.campaigns.filters.active_now'))
                    ->toggle()
                    ->query(static fn (Builder $query): Builder => static::runningNow($query)),
            ])
            ->defaultSort('starts_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCampaigns::route('/'),
            'create' => CreateCampaign::route('/create'),
            'edit' => EditCampaign::route('/{record}/edit'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function createRecordUsing(array $data, Authenticatable $actor): Model
    {
        return static::writeThroughContract(
            $actor,
            'campaign.created',
            null,
            static fn (): Model => app(CreatesCampaigns::class)->create($actor, $data),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function updateRecordUsing(Model $record, array $data, Authenticatable $actor): Model
    {
        static::writeThroughContract(
            $actor,
            'campaign.updated',
            $record,
            static fn () => app(UpdatesCampaigns::class)->update($actor, $record, $data),
        );

        return $record->refresh();
    }

    /**
     * The coupon as the last Stripe check described it: a percentage, or the amount off in each
     * currency it was given in. Null before the code has been checked.
     */
    public static function discountLabel(Campaign $campaign): ?string
    {
        if ($campaign->discount_type === 'percent') {
            return rtrim(rtrim(number_format((float) $campaign->discount_value, 2), '0'), '.') . '%';
        }

        if ($campaign->discount_type !== 'amount') {
            return null;
        }

        $amounts = [
            (string) $campaign->discount_currency => (int) $campaign->discount_value,
            ...($campaign->currency_options ?? []),
        ];

        return collect($amounts)
            ->map(static fn (int $amount, string $currency): string => Cashier::formatAmount($amount, $currency))
            ->implode(' / ');
    }

    /**
     * @param  Builder<Campaign>  $query
     * @return Builder<Campaign>
     */
    protected static function runningNow(Builder $query): Builder
    {
        return $query->activeAt(now());
    }

    /**
     * How many checkouts redeemed the campaign's code. Counted per row rather than joined: the
     * campaign id sits inside a JSON column, whose comparison against a key column is not
     * portable across the databases the package runs on, and a page holds a few dozen rows.
     */
    protected static function redemptionCount(Campaign $campaign): int
    {
        // The webhook records the id as a string, so an integer key is cast to match it.
        return BillingEvent::query()
            ->where('type', BillingEventType::CAMPAIGN_REDEEMED->value)
            ->where('properties->campaign_id', (string) $campaign->getKey())
            ->count();
    }

    /**
     * Run a contract write, announce it, and turn a refusal into errors on the form's fields.
     *
     * Not {@see \FlutterSdk\MagicStarter\Filament\Support\ContractAction}: it shows a refusal as
     * a notification and halts, while a campaign refusal names the field to fix (the window, the
     * promotion code), so it is re-thrown keyed to the field's state path and Livewire shows it
     * there.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $call
     * @return TResult
     *
     * @throws ValidationException when the contract refused
     */
    protected static function writeThroughContract(
        Authenticatable $actor,
        string $event,
        ?Model $subject,
        Closure $call,
    ): mixed {
        try {
            $result = $call();
        } catch (ValidationException $refusal) {
            throw ValidationException::withMessages(collect($refusal->errors())
                ->mapWithKeys(static fn (array $messages, string $field): array => [
                    static::FORM_STATE_PATH . '.' . $field => $messages,
                ])
                ->all());
        }

        $subject ??= $result instanceof Model ? $result : null;

        Event::dispatch(new AdminActionPerformed($actor, $event, $subject));

        return $result;
    }
}
