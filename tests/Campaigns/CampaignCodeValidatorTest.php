<?php

namespace FlutterSdk\MagicStarter\Tests\Campaigns;

use FlutterSdk\MagicStarter\Contracts\CreatesCampaigns;
use FlutterSdk\MagicStarter\Contracts\UpdatesCampaigns;
use FlutterSdk\MagicStarter\Enums\CampaignDiscountType;
use FlutterSdk\MagicStarter\Models\Campaign;
use FlutterSdk\MagicStarter\Support\CampaignCodeValidator;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\Support\StripeHttpStub;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Drives the promotion code check through the write contracts and Cashier's
 * real Stripe client, with only the transport faked ({@see StripeHttpStub}).
 *
 * Every refusal here is a code Checkout would reject at session creation (the
 * Wave 1 probe, cases G1 to G4) or one that would quietly fail a customer the
 * campaign promised a discount to; refusing it on save is what keeps a
 * campaign from shipping a code its own checkout cannot redeem. The request
 * counts pin the cost: one promotion code read plus one price read per
 * campaign product, and nothing at all when the save cannot change the code's
 * fitness.
 */
class CampaignCodeValidatorTest extends TestCase
{
    private const ENDS_AT = '2026-11-08 00:00:00';

    private StripeHttpStub $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--path' => __DIR__ . '/../../database/migrations/create_campaigns_table.php',
            '--realpath' => true,
        ]);

        config([
            'cashier.secret' => 'sk_test_campaign_code',
            'magic-starter.defaults.locale' => 'en',
            'magic-starter.supported_locales' => [
                'en',
                'tr',
            ],
            'magic-starter.billing.tier_order' => [
                'free',
                'pro',
                'business',
            ],
            'magic-starter.billing.products' => [
                'pro_monthly' => [
                    'type' => 'subscription',
                    'tier' => 'pro',
                    'cycle' => 'monthly',
                    'prices' => [
                        'web' => [
                            'USD' => 2900,
                            'TRY' => 49900,
                        ],
                    ],
                    'refs' => [
                        'stripe_price' => 'price_pro_monthly',
                    ],
                ],
                'business_monthly' => [
                    'type' => 'subscription',
                    'tier' => 'business',
                    'cycle' => 'monthly',
                    'prices' => [
                        'web' => [
                            'USD' => 9900,
                        ],
                    ],
                    'refs' => [
                        'stripe_price' => 'price_business_monthly',
                    ],
                ],
            ],
        ]);

        $this->stripe = StripeHttpStub::install();
    }

    protected function tearDown(): void
    {
        StripeHttpStub::uninstall();

        parent::tearDown();
    }

    public function test_the_validator_is_resolved_with_cashiers_stripe_client(): void
    {
        $this->assertInstanceOf(CampaignCodeValidator::class, app(CampaignCodeValidator::class));
    }

    public function test_a_valid_percent_code_is_snapshotted_at_one_read_per_object(): void
    {
        $this->stripe
            ->answer($this->promotionCode())
            ->answer($this->price('price_pro_monthly', 'prod_pro'))
            ->answer($this->price('price_business_monthly', 'prod_business'));

        $campaign = $this->createEnabled(['products' => ['pro_monthly', 'business_monthly']]);

        $this->assertSame(CampaignDiscountType::PERCENT, $campaign->discount_type);
        $this->assertSame(25.0, $campaign->discount_value);
        $this->assertNull($campaign->discount_currency);
        $this->assertNull($campaign->currency_options);

        $this->assertCount(3, $this->stripe->requests);
        $codeReads = $this->stripe->requestsTo('get', '/v1/promotion_codes/promo_campaign');
        $this->assertCount(1, $codeReads);
        $this->assertSame(
            [
                'promotion.coupon',
                'promotion.coupon.applies_to',
                'promotion.coupon.currency_options',
            ],
            $codeReads[0]['params']['expand'],
        );
        $this->assertCount(1, $this->stripe->requestsTo('get', '/v1/prices/price_pro_monthly'));
        $this->assertCount(1, $this->stripe->requestsTo('get', '/v1/prices/price_business_monthly'));
    }

    public function test_a_valid_amount_code_snapshots_its_currency_and_options(): void
    {
        $this->stripe
            ->answer($this->promotionCode(coupon: $this->amountCoupon(['try' => ['amount_off' => 15000]])))
            ->answer($this->price('price_pro_monthly', 'prod_pro'));

        $campaign = $this->createEnabled();

        $this->assertSame(CampaignDiscountType::AMOUNT, $campaign->discount_type);
        $this->assertSame(500.0, $campaign->discount_value);
        $this->assertSame('USD', $campaign->discount_currency);
        $this->assertSame(['TRY' => 15000], $campaign->currency_options);
        $this->assertCount(2, $this->stripe->requests);
    }

    public function test_a_code_restricted_to_listed_products_that_covers_them_all_passes(): void
    {
        $this->stripe
            ->answer($this->promotionCode(coupon: $this->percentCoupon(appliesTo: ['prod_pro', 'prod_other'])))
            ->answer($this->price('price_pro_monthly', 'prod_pro'));

        $this->assertSame(CampaignDiscountType::PERCENT, $this->createEnabled()->discount_type);
    }

    public function test_an_inactive_code_is_refused(): void
    {
        $this->assertCodeRefused('code_inactive', $this->promotionCode(['active' => false]));
    }

    public function test_a_code_without_an_expiry_is_refused(): void
    {
        $this->assertCodeRefused('code_without_expiry', $this->promotionCode(['expires_at' => null]));
    }

    public function test_a_code_expiring_before_the_campaign_ends_is_refused(): void
    {
        $this->assertCodeRefused('code_expires_before_end', $this->promotionCode([
            'expires_at' => Carbon::parse(self::ENDS_AT)->subSecond()->getTimestamp(),
        ]));
    }

    public function test_a_first_time_transaction_code_is_refused(): void
    {
        $this->assertCodeRefused('code_first_time_only', $this->promotionCode([
            'restrictions' => $this->restrictions(['first_time_transaction' => true]),
        ]));
    }

    public function test_a_minimum_amount_code_is_refused(): void
    {
        $this->assertCodeRefused('code_minimum_amount', $this->promotionCode([
            'restrictions' => $this->restrictions([
                'minimum_amount' => 1000,
                'minimum_amount_currency' => 'usd',
            ]),
        ]));
    }

    public function test_a_per_currency_minimum_amount_code_is_refused(): void
    {
        $this->assertCodeRefused('code_minimum_amount', $this->promotionCode([
            'restrictions' => $this->restrictions([
                'currency_options' => [
                    'try' => ['minimum_amount' => 30000],
                ],
            ]),
        ]));
    }

    public function test_a_customer_restricted_code_is_refused(): void
    {
        $this->assertCodeRefused('code_customer_restricted', $this->promotionCode(['customer' => 'cus_someone']));
    }

    public function test_a_coupon_stripe_marks_invalid_is_refused(): void
    {
        $coupon = [
            ...$this->percentCoupon(),
            'valid' => false,
        ];

        $this->assertCodeRefused('code_coupon_invalid', $this->promotionCode(coupon: $coupon));
    }

    /**
     * Stripe counts a `repeating` coupon's months from the subscription's
     * start, which is the trial's start (evidence 8-trial-coupon-probe), so a
     * repeat no longer than the longest trial ends before anything is billed.
     */
    public function test_a_repeating_coupon_used_up_within_a_products_trial_is_refused(): void
    {
        $this->giveProTrialDays(30);
        $this->stripe->answer($this->promotionCode(coupon: $this->repeatingCoupon(months: 1)));

        $this->assertRefused(
            'stripe_promotion_code_id',
            (string) __('magic-starter::campaigns.refusals.code_repeating_within_trial', [
                'months' => '1',
                'days' => '30',
            ]),
        );
        $this->assertCount(1, $this->stripe->requests);
    }

    public function test_a_repeating_coupon_outlasting_every_trial_passes(): void
    {
        $this->giveProTrialDays(30);
        $this->stripe
            ->answer($this->promotionCode(coupon: $this->repeatingCoupon(months: 2)))
            ->answer($this->price('price_pro_monthly', 'prod_pro'));

        $this->assertSame(CampaignDiscountType::PERCENT, $this->createEnabled()->discount_type);
    }

    public function test_a_repeating_coupon_on_products_without_a_trial_passes(): void
    {
        $this->stripe
            ->answer($this->promotionCode(coupon: $this->repeatingCoupon(months: 1)))
            ->answer($this->price('price_pro_monthly', 'prod_pro'));

        $this->assertSame(CampaignDiscountType::PERCENT, $this->createEnabled()->discount_type);
    }

    public function test_a_code_whose_coupon_skips_a_campaign_product_is_refused(): void
    {
        $this->stripe
            ->answer($this->promotionCode(coupon: $this->percentCoupon(appliesTo: ['prod_pro'])))
            ->answer($this->price('price_pro_monthly', 'prod_pro'))
            ->answer($this->price('price_business_monthly', 'prod_business'));

        $this->assertRefused(
            'stripe_promotion_code_id',
            (string) __('magic-starter::campaigns.refusals.code_products_not_covered', ['products' => 'business_monthly']),
            ['products' => ['pro_monthly', 'business_monthly']],
        );
        $this->assertCount(3, $this->stripe->requests);
    }

    public function test_an_amount_code_missing_a_catalogue_currency_is_refused(): void
    {
        $this->stripe->answer($this->promotionCode(coupon: $this->amountCoupon()));

        $this->assertRefused(
            'stripe_promotion_code_id',
            (string) __('magic-starter::campaigns.refusals.code_currency_missing', ['currencies' => 'TRY']),
        );
    }

    public function test_an_unknown_code_names_the_code_field(): void
    {
        $this->stripe->answer($this->stripeError("No such promotion code: 'promo_campaign'"), 404);

        $this->assertRefused(
            'stripe_promotion_code_id',
            (string) __('magic-starter::campaigns.refusals.code_not_found'),
        );
    }

    public function test_a_code_from_the_other_stripe_mode_says_so(): void
    {
        $this->stripe->answer($this->stripeError(
            "No such promotion code: 'promo_campaign'; a similar object exists in live mode, "
            . 'but a test mode key was used to make this request.',
        ), 404);

        $this->assertRefused(
            'stripe_promotion_code_id',
            (string) __('magic-starter::campaigns.refusals.code_mode_mismatch'),
        );
    }

    public function test_an_unreadable_catalogue_price_names_the_products_field(): void
    {
        $this->stripe
            ->answer($this->promotionCode())
            ->answer($this->stripeError("No such price: 'price_pro_monthly'"), 404);

        $this->assertRefused(
            'products',
            (string) __('magic-starter::campaigns.refusals.price_not_found', ['product' => 'pro_monthly']),
        );
    }

    public function test_any_other_stripe_refusal_carries_stripes_message(): void
    {
        $this->stripe->answer([
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'Invalid API Key provided.',
            ],
        ], 401);

        $this->assertRefused(
            'stripe_promotion_code_id',
            (string) __('magic-starter::campaigns.refusals.stripe_error', ['message' => 'Invalid API Key provided.']),
        );
    }

    public function test_disabling_an_enabled_campaign_never_calls_stripe(): void
    {
        $campaign = $this->existingEnabledCampaign();

        app(UpdatesCampaigns::class)->update(new ConcreteUser, $campaign, $this->input([
            'enabled' => false,
            'stripe_promotion_code_id' => 'promo_other',
            'ends_at' => '2026-11-20 00:00:00',
        ]));

        $this->assertFalse($campaign->fresh()->enabled);
        $this->assertSame([], $this->stripe->requests);
    }

    public function test_editing_only_the_copy_of_an_enabled_campaign_never_calls_stripe(): void
    {
        $campaign = $this->existingEnabledCampaign();

        app(UpdatesCampaigns::class)->update(new ConcreteUser, $campaign, $this->input([
            'enabled' => true,
            'name' => 'Autumn sale, extended copy',
            'starts_at' => '2026-10-30 00:00:00',
            'headline' => [
                'en' => 'Last week of autumn',
                'tr' => 'Sonbaharın son haftası',
            ],
        ]));

        $campaign->refresh();

        $this->assertSame('Last week of autumn', $campaign->headline['en']);
        $this->assertSame(CampaignDiscountType::PERCENT, $campaign->discount_type);
        $this->assertSame([], $this->stripe->requests);
    }

    public function test_moving_the_end_of_an_enabled_campaign_checks_the_code_again(): void
    {
        $campaign = $this->existingEnabledCampaign();

        $this->stripe
            ->answer($this->promotionCode(['expires_at' => Carbon::parse('2026-11-20 00:00:00')->getTimestamp()]))
            ->answer($this->price('price_pro_monthly', 'prod_pro'));

        app(UpdatesCampaigns::class)->update(new ConcreteUser, $campaign, $this->input([
            'enabled' => true,
            'ends_at' => '2026-11-15 00:00:00',
        ]));

        $this->assertCount(2, $this->stripe->requests);
    }

    public function test_enabling_a_disabled_campaign_checks_the_code_it_never_checked(): void
    {
        $campaign = app(CreatesCampaigns::class)->create(new ConcreteUser, $this->input(['enabled' => false]));

        $this->assertSame([], $this->stripe->requests);

        $this->stripe
            ->answer($this->promotionCode())
            ->answer($this->price('price_pro_monthly', 'prod_pro'));

        app(UpdatesCampaigns::class)->update(new ConcreteUser, $campaign, $this->input(['enabled' => true]));

        $this->assertCount(2, $this->stripe->requests);
        $this->assertSame(CampaignDiscountType::PERCENT, Campaign::query()->findOrFail($campaign->getKey())->discount_type);
    }

    public function test_a_refused_code_leaves_the_stored_campaign_untouched(): void
    {
        $campaign = $this->existingEnabledCampaign();

        $this->stripe->answer($this->promotionCode([
            'id' => 'promo_other',
            'active' => false,
        ]));

        try {
            app(UpdatesCampaigns::class)->update(new ConcreteUser, $campaign, $this->input([
                'enabled' => true,
                'stripe_promotion_code_id' => 'promo_other',
            ]));
            $this->fail('Expected the inactive code to be refused.');
        } catch (ValidationException) {
            $this->assertSame('promo_campaign', $campaign->fresh()->stripe_promotion_code_id);
            // The caller's instance is not left holding the refused input either.
            $this->assertSame('promo_campaign', $campaign->stripe_promotion_code_id);
            $this->assertFalse($campaign->isDirty());
        }
    }

    public function test_back_to_back_enabled_campaigns_do_not_overlap(): void
    {
        $this->existingEnabledCampaign();

        $this->stripe
            ->answer($this->promotionCode(['expires_at' => Carbon::parse('2026-11-30 00:00:00')->getTimestamp()]))
            ->answer($this->price('price_pro_monthly', 'prod_pro'));

        $this->createEnabled([
            'starts_at' => self::ENDS_AT,
            'ends_at' => '2026-11-15 00:00:00',
        ]);

        $this->assertSame(2, Campaign::query()->where('enabled', true)->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createEnabled(array $overrides = []): Campaign
    {
        $campaign = app(CreatesCampaigns::class)->create(new ConcreteUser, $this->input([
            'enabled' => true,
            ...$overrides,
        ]));

        return Campaign::query()->findOrFail($campaign->getKey());
    }

    private function existingEnabledCampaign(): Campaign
    {
        return Campaign::query()->create([
            'name' => 'Autumn sale',
            'starts_at' => '2026-11-01 00:00:00',
            'ends_at' => self::ENDS_AT,
            'enabled' => true,
            'products' => ['pro_monthly'],
            'headline' => ['en' => 'Autumn sale'],
            'body' => ['en' => '25% off Pro'],
            'stripe_promotion_code_id' => 'promo_campaign',
            'discount_type' => 'percent',
            'discount_value' => 25,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
    {
        return [
            'name' => 'Autumn sale',
            'starts_at' => '2026-11-01 00:00:00',
            'ends_at' => self::ENDS_AT,
            'enabled' => true,
            'products' => ['pro_monthly'],
            'headline' => [
                'en' => 'Autumn sale',
                'tr' => 'Sonbahar indirimi',
            ],
            'body' => [
                'en' => '25% off Pro',
                'tr' => 'Pro için yüzde 25 indirim',
            ],
            'stripe_promotion_code_id' => 'promo_campaign',
            ...$overrides,
        ];
    }

    /**
     * A promotion code as Stripe answers it with the coupon expanded.
     *
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>|null  $coupon
     * @return array<string, mixed>
     */
    private function promotionCode(array $overrides = [], ?array $coupon = null): array
    {
        return [
            'id' => 'promo_campaign',
            'object' => 'promotion_code',
            'active' => true,
            'code' => 'AUTUMN25',
            'customer' => null,
            'customer_account' => null,
            'expires_at' => Carbon::parse(self::ENDS_AT)->getTimestamp(),
            'livemode' => false,
            'max_redemptions' => null,
            'promotion' => [
                'type' => 'coupon',
                'coupon' => $coupon ?? $this->percentCoupon(),
            ],
            'restrictions' => $this->restrictions(),
            'times_redeemed' => 0,
            ...$overrides,
        ];
    }

    /**
     * @param  list<string>|null  $appliesTo
     * @return array<string, mixed>
     */
    private function percentCoupon(?array $appliesTo = null): array
    {
        return [
            'id' => 'coupon_percent',
            'object' => 'coupon',
            'amount_off' => null,
            'currency' => null,
            'currency_options' => null,
            'percent_off' => 25,
            'applies_to' => $appliesTo === null ? null : ['products' => $appliesTo],
            'duration' => 'once',
            'valid' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function repeatingCoupon(int $months): array
    {
        return [
            ...$this->percentCoupon(),
            'duration' => 'repeating',
            'duration_in_months' => $months,
        ];
    }

    private function giveProTrialDays(int $days): void
    {
        config(['magic-starter.billing.products.pro_monthly.trial_days' => $days]);
    }

    /**
     * @param  array<string, array{amount_off: int}>  $currencyOptions
     * @return array<string, mixed>
     */
    private function amountCoupon(array $currencyOptions = []): array
    {
        return [
            'id' => 'coupon_amount',
            'object' => 'coupon',
            'amount_off' => 500,
            'currency' => 'usd',
            'currency_options' => $currencyOptions === [] ? null : $currencyOptions,
            'percent_off' => null,
            'applies_to' => null,
            'duration' => 'once',
            'valid' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function restrictions(array $overrides = []): array
    {
        return [
            'first_time_transaction' => false,
            'minimum_amount' => null,
            'minimum_amount_currency' => null,
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function price(string $id, string $product): array
    {
        return [
            'id' => $id,
            'object' => 'price',
            'active' => true,
            'currency' => 'usd',
            'product' => $product,
            'unit_amount' => 2900,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stripeError(string $message): array
    {
        return [
            'error' => [
                'type' => 'invalid_request_error',
                'code' => 'resource_missing',
                'message' => $message,
            ],
        ];
    }

    /**
     * Answer one promotion code read and expect the code refused with the given reason.
     *
     * @param  array<string, mixed>  $code
     */
    private function assertCodeRefused(string $reason, array $code): void
    {
        $this->stripe->answer($code);

        $this->assertRefused(
            'stripe_promotion_code_id',
            (string) __('magic-starter::campaigns.refusals.' . $reason),
        );

        // Refused on the code alone: no price was worth reading.
        $this->assertCount(1, $this->stripe->requests);
        $this->assertSame(0, Campaign::query()->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function assertRefused(string $field, string $message, array $overrides = []): void
    {
        try {
            $this->createEnabled($overrides);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();

            $this->assertArrayHasKey($field, $errors, json_encode($errors) ?: '');
            $this->assertSame($message, $errors[$field][0]);

            return;
        }

        $this->fail(sprintf('Expected [%s] to be refused.', $field));
    }
}
