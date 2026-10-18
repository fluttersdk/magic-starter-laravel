<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\CreatesCampaigns;
use FlutterSdk\MagicStarter\Enums\ProductType;
use FlutterSdk\MagicStarter\Models\Campaign;
use FlutterSdk\MagicStarter\Support\BillingCatalogue;
use FlutterSdk\MagicStarter\Support\CampaignCodeValidator;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Default campaign creation action.
 *
 * Also owns the rules and the save {@see UpdateCampaign} shares.
 *
 * @phpstan-import-type Product from BillingCatalogue
 */
class CreateCampaign implements CreatesCampaigns
{
    public function __construct(
        protected CampaignCodeValidator $codes,
    ) {}

    /**
     * Validate and create a campaign.
     *
     * @param  Authenticatable  $user  The staff user creating the campaign.
     * @param  array<string, mixed>  $input  The campaign data.
     * @return Model The created campaign.
     *
     * @throws ValidationException
     */
    public function create(Authenticatable $user, array $input): Model
    {
        return $this->save(new Campaign, $input);
    }

    /**
     * Validate the input, check the promotion code when the save can change its
     * fitness, and persist the campaign.
     *
     * The code is checked when the campaign ends up enabled and either was not
     * enabled before (a new row, or one switched on) or its code, end or
     * products changed. Copy, name and start edits never reach Stripe, and
     * neither does disabling: a switched-off campaign sells nothing.
     *
     * @param  array<string, mixed>  $input  The campaign data.
     *
     * @throws ValidationException
     */
    public function save(Model $campaign, array $input): Model
    {
        // 1. Shape and the default-locale copy, before anything costs a query.
        $validated = $this->validate($input);
        $wasEnabled = $campaign->exists && (bool) $campaign->getOriginal('enabled');

        $campaign->fill([
            'name' => $validated['name'],
            'starts_at' => Carbon::parse($validated['starts_at']),
            'ends_at' => Carbon::parse($validated['ends_at']),
            'enabled' => (bool) ($validated['enabled'] ?? false),
            'products' => array_values($validated['products']),
            'headline' => $this->filledLocales($validated['headline']),
            'body' => $this->filledLocales($validated['body']),
            'stripe_promotion_code_id' => $validated['stripe_promotion_code_id'],
        ]);

        if (! $campaign->getAttribute('enabled')) {
            $campaign->save();

            return $campaign;
        }

        try {
            // 2. One enabled campaign at a time, so checkout never has two codes to choose from.
            $this->refuseOverlap($campaign);

            // 3. Stripe last: it is the slow, rate-limited step, and only worth it when the answer can differ.
            if (! $wasEnabled || $campaign->isDirty(['stripe_promotion_code_id', 'ends_at', 'products'])) {
                $campaign->fill($this->codes->validate(
                    (string) $campaign->getAttribute('stripe_promotion_code_id'),
                    $this->catalogueProducts($validated['products']),
                    Carbon::parse($validated['ends_at']),
                ));
            }
        } catch (ValidationException $refusal) {
            // The caller's instance goes back to what is stored, not to the input that was refused.
            $campaign->discardChanges();

            throw $refusal;
        }

        $campaign->save();

        return $campaign;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    protected function validate(array $input): array
    {
        $defaultLocale = (string) config('magic-starter.defaults.locale');
        $locales = array_values(array_unique([
            $defaultLocale,
            ...(array) config('magic-starter.supported_locales'),
        ]));

        $copyRules = [];

        foreach ($locales as $locale) {
            $presence = $locale === $defaultLocale ? 'required' : 'nullable';
            $copyRules['headline.' . $locale] = [$presence, 'string', 'max:120'];
            $copyRules['body.' . $locale] = [$presence, 'string', 'max:500'];
        }

        return Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'enabled' => ['sometimes', 'boolean'],
            'products' => ['required', 'array', 'min:1'],
            'products.*' => ['required', 'string', 'distinct', Rule::in($this->eligibleProductKeys())],
            'headline' => ['required', 'array:' . implode(',', $locales)],
            'body' => ['required', 'array:' . implode(',', $locales)],
            ...$copyRules,
            'stripe_promotion_code_id' => ['required', 'string', 'max:255'],
        ], [
            'products.*.in' => __('magic-starter::campaigns.refusals.product_not_eligible'),
        ])->validate();
    }

    /**
     * The products a campaign may discount: sellable subscriptions Stripe Checkout can sell.
     *
     * @return list<string>
     */
    protected function eligibleProductKeys(): array
    {
        $keys = [];

        foreach (BillingCatalogue::products() as $product) {
            if ($product['type'] === ProductType::SUBSCRIPTION
                && $product['sellable']
                && $product['refs']['stripe_price'] !== null
            ) {
                $keys[] = $product['key'];
            }
        }

        return $keys;
    }

    /**
     * @param  array<int, string>  $keys  Validated eligible product keys.
     * @return list<Product>
     */
    protected function catalogueProducts(array $keys): array
    {
        $products = BillingCatalogue::products();

        return array_values(array_map(fn (string $key): array => $products[$key], $keys));
    }

    /**
     * @throws ValidationException When another enabled campaign shares part of the window.
     */
    protected function refuseOverlap(Model $campaign): void
    {
        $overlapping = Campaign::query()
            ->where('enabled', true)
            ->where('starts_at', '<', $campaign->getAttribute('ends_at'))
            ->where('ends_at', '>', $campaign->getAttribute('starts_at'))
            ->when($campaign->exists, fn ($query) => $query->whereKeyNot($campaign->getKey()))
            ->first();

        if ($overlapping === null) {
            return;
        }

        throw ValidationException::withMessages([
            'starts_at' => __('magic-starter::campaigns.refusals.overlap', ['name' => $overlapping->name]),
        ]);
    }

    /**
     * Drop the locales left blank, so a reader falls back to the default locale.
     *
     * @param  array<string, string|null>  $text
     * @return array<string, string>
     */
    protected function filledLocales(array $text): array
    {
        return array_filter($text, fn (?string $value): bool => filled($value));
    }
}
