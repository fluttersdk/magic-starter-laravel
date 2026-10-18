<?php

namespace FlutterSdk\MagicStarter\Support;

use Carbon\CarbonInterface;
use FlutterSdk\MagicStarter\Enums\CampaignDiscountType;
use Illuminate\Validation\ValidationException;
use Stripe\Coupon;
use Stripe\Exception\ApiErrorException;
use Stripe\PromotionCode;
use Stripe\StripeClient;

/**
 * Checks that a campaign's Stripe promotion code is one Checkout will redeem for
 * every campaign product and every catalogue currency until the campaign ends,
 * and reads the coupon's discount for the campaign to snapshot.
 *
 * Each refusal is a code Stripe rejects at session creation (the restrictions
 * below, an inactive code, a currency the coupon has no amount for) or one that
 * stops working before the campaign does, so refusing it on save keeps a
 * campaign from advertising a discount its own checkout cannot give.
 *
 * Reads only: one promotion code read with the coupon expanded, then one price
 * read per campaign product, because the catalogue stores the Stripe price and
 * the coupon's `applies_to` names Stripe products. Resolved through the
 * container with Cashier's Stripe client, so the key, the pinned API version and
 * the base URL are the ones the rest of the Stripe rail uses.
 *
 * @phpstan-import-type Product from BillingCatalogue
 *
 * @phpstan-type Snapshot array{
 *     discount_type: CampaignDiscountType,
 *     discount_value: float,
 *     discount_currency: ?string,
 *     currency_options: ?array<string, int>,
 * }
 */
class CampaignCodeValidator
{
    private const CODE_FIELD = 'stripe_promotion_code_id';

    private const PRODUCTS_FIELD = 'products';

    public function __construct(
        protected StripeClient $stripe,
    ) {}

    /**
     * Refuse a code checkout would reject for these products before `$endsAt`, or answer its discount.
     *
     * @param  list<Product>  $products  The campaign's catalogue products, each with a Stripe price.
     * @return Snapshot Currencies uppercase, as the catalogue writes them; amounts in minor units.
     *
     * @throws ValidationException Naming `stripe_promotion_code_id` or `products`.
     */
    public function validate(string $promotionCodeId, array $products, CarbonInterface $endsAt): array
    {
        // 1. The code with its coupon, applies_to and currency options, which Stripe omits unless expanded.
        $code = $this->retrieveCode($promotionCodeId);
        $coupon = $code->promotion->coupon ?? null;

        if (! $coupon instanceof Coupon) {
            $this->refuse(self::CODE_FIELD, 'code_without_coupon');
        }

        // 2. What Stripe checks on the code itself, all knowable without a price.
        $this->refuseUnredeemableCode($code, $endsAt);

        // 2b. What the coupon behind it would do to a buyer, also knowable without a price.
        $this->refuseUnfitCoupon($coupon, $products);

        // 3. An amount off needs a figure in every currency the campaign products sell in.
        if ($coupon->amount_off !== null) {
            $this->refuseMissingCurrencies($coupon, $products);
        }

        // 4. A coupon limited to some Stripe products has to cover every campaign product.
        $this->refuseUncoveredProducts($coupon, $products);

        return $this->snapshot($coupon);
    }

    /**
     * @throws ValidationException
     */
    protected function retrieveCode(string $promotionCodeId): PromotionCode
    {
        try {
            return $this->stripe->promotionCodes->retrieve($promotionCodeId, [
                'expand' => [
                    'promotion.coupon',
                    'promotion.coupon.applies_to',
                    'promotion.coupon.currency_options',
                ],
            ]);
        } catch (ApiErrorException $error) {
            $this->refuseStripeError(self::CODE_FIELD, $error, 'code');
        }
    }

    /**
     * @throws ValidationException
     */
    protected function refuseUnredeemableCode(PromotionCode $code, CarbonInterface $endsAt): void
    {
        if (! $code->active) {
            $this->refuse(self::CODE_FIELD, 'code_inactive');
        }

        if ($code->expires_at === null) {
            $this->refuse(self::CODE_FIELD, 'code_without_expiry');
        }

        if ($code->expires_at < $endsAt->getTimestamp()) {
            $this->refuse(self::CODE_FIELD, 'code_expires_before_end');
        }

        $restrictions = $code->restrictions;

        // A team that trialled has prior transactions, so Stripe refuses it (probe G1).
        if ($restrictions->first_time_transaction) {
            $this->refuse(self::CODE_FIELD, 'code_first_time_only');
        }

        // A trial checkout charges nothing up front, so no minimum is ever met (probe G2).
        if ($restrictions->minimum_amount !== null
            || $this->hasCurrencyMinimum($restrictions->currency_options ?? null)
        ) {
            $this->refuse(self::CODE_FIELD, 'code_minimum_amount');
        }

        if ($code->customer !== null || $code->customer_account !== null) {
            $this->refuse(self::CODE_FIELD, 'code_customer_restricted');
        }
    }

    /**
     * Refuse a coupon Stripe no longer redeems, and a `repeating` one whose
     * months a product's trial would use up.
     *
     * Stripe counts a repeating coupon's months from the subscription's start,
     * which is the trial's start, so a repeat no longer than the trial ends
     * before the first paid invoice and the buyer is never discounted. Months
     * are taken at 28 days, the shortest, so a borderline repeat is refused
     * rather than trusted. `once` and `forever` survive a trial.
     *
     * @param  list<Product>  $products
     *
     * @throws ValidationException
     */
    protected function refuseUnfitCoupon(Coupon $coupon, array $products): void
    {
        if ($coupon->valid === false) {
            $this->refuse(self::CODE_FIELD, 'code_coupon_invalid');
        }

        if ($coupon->duration !== 'repeating') {
            return;
        }

        $months = (int) $coupon->duration_in_months;
        $trialDays = max([
            0,
            ...array_map(static fn (array $product): int => $product['trial_days'], $products),
        ]);

        if ($months * 28 <= $trialDays) {
            $this->refuse(self::CODE_FIELD, 'code_repeating_within_trial', [
                'months' => (string) $months,
                'days' => (string) $trialDays,
            ]);
        }
    }

    /**
     * @param  list<Product>  $products
     *
     * @throws ValidationException
     */
    protected function refuseMissingCurrencies(Coupon $coupon, array $products): void
    {
        $covered = array_map('strtoupper', [
            (string) $coupon->currency,
            ...array_keys($coupon->currency_options?->toArray() ?? []),
        ]);

        $missing = [];

        foreach ($products as $product) {
            $web = $product['prices']['web'] ?? [];

            foreach (is_array($web) ? array_keys($web) : [] as $currency) {
                $currency = strtoupper((string) $currency);

                if (! in_array($currency, $covered, true)) {
                    $missing[$currency] = $currency;
                }
            }
        }

        if ($missing !== []) {
            $this->refuse(self::CODE_FIELD, 'code_currency_missing', ['currencies' => implode(', ', $missing)]);
        }
    }

    /**
     * @param  list<Product>  $products
     *
     * @throws ValidationException
     */
    protected function refuseUncoveredProducts(Coupon $coupon, array $products): void
    {
        // Read every price either way: a catalogue price this key cannot see is
        // one the campaign's own checkout would fail on.
        $stripeProducts = [];

        foreach ($products as $product) {
            $stripeProducts[$product['key']] = $this->stripeProductOf($product);
        }

        $appliesTo = $coupon->applies_to->products ?? [];

        if ($appliesTo === []) {
            return;
        }

        $uncovered = array_keys(array_diff($stripeProducts, $appliesTo));

        if ($uncovered !== []) {
            $this->refuse(self::CODE_FIELD, 'code_products_not_covered', ['products' => implode(', ', $uncovered)]);
        }
    }

    /**
     * @param  Product  $product
     *
     * @throws ValidationException
     */
    protected function stripeProductOf(array $product): string
    {
        try {
            $price = $this->stripe->prices->retrieve((string) $product['refs']['stripe_price']);
        } catch (ApiErrorException $error) {
            $this->refuseStripeError(self::PRODUCTS_FIELD, $error, 'price', ['product' => $product['key']]);
        }

        return is_string($price->product) ? $price->product : $price->product->id;
    }

    /**
     * @return Snapshot
     */
    protected function snapshot(Coupon $coupon): array
    {
        if ($coupon->amount_off === null) {
            return [
                'discount_type' => CampaignDiscountType::PERCENT,
                'discount_value' => (float) $coupon->percent_off,
                'discount_currency' => null,
                'currency_options' => null,
            ];
        }

        $options = [];

        foreach ($coupon->currency_options?->toArray() ?? [] as $currency => $option) {
            $options[strtoupper((string) $currency)] = (int) ($option['amount_off'] ?? 0);
        }

        return [
            'discount_type' => CampaignDiscountType::AMOUNT,
            'discount_value' => (float) $coupon->amount_off,
            'discount_currency' => strtoupper((string) $coupon->currency),
            'currency_options' => $options === [] ? null : $options,
        ];
    }

    protected function hasCurrencyMinimum(mixed $currencyOptions): bool
    {
        foreach (is_object($currencyOptions) ? $currencyOptions->toArray() : [] as $option) {
            if (($option['minimum_amount'] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * A missing object usually means the id was copied from the other Stripe
     * mode, which Stripe says in its message; anything else is passed on as
     * Stripe worded it, since staff are the only readers.
     *
     * @param  'code'|'price'  $subject
     * @param  array<string, string>  $replace
     *
     * @throws ValidationException
     */
    protected function refuseStripeError(
        string $field,
        ApiErrorException $error,
        string $subject,
        array $replace = [],
    ): never {
        $message = $error->getMessage();

        if ($error->getStripeCode() !== 'resource_missing') {
            $this->refuse($field, 'stripe_error', ['message' => $message]);
        }

        $reason = str_contains($message, 'similar object exists') ? 'mode_mismatch' : 'not_found';

        $this->refuse($field, $subject . '_' . $reason, $replace);
    }

    /**
     * @param  array<string, string>  $replace
     *
     * @throws ValidationException
     */
    protected function refuse(string $field, string $reason, array $replace = []): never
    {
        throw ValidationException::withMessages([
            $field => __('magic-starter::campaigns.refusals.' . $reason, $replace),
        ]);
    }
}
