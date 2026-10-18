<?php

namespace FlutterSdk\MagicStarter\Tests\Campaigns;

use FlutterSdk\MagicStarter\Actions\CreateCampaign;
use FlutterSdk\MagicStarter\Actions\UpdateCampaign;
use FlutterSdk\MagicStarter\Contracts\CreatesCampaigns;
use FlutterSdk\MagicStarter\Contracts\UpdatesCampaigns;
use FlutterSdk\MagicStarter\Enums\CampaignDiscountType;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Models\Campaign;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\Support\StripeHttpStub;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Locks the campaign row (casts, the `activeAt()` window) and the rules the
 * write contracts enforce before Stripe is ever asked: the window, the
 * products, the default-locale copy and one enabled campaign at a time.
 *
 * Every write here leaves the campaign disabled or is refused before the code
 * check, so the stub must see no request at all.
 */
class CampaignModelTest extends TestCase
{
    private StripeHttpStub $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--path' => __DIR__ . '/../../database/migrations/create_campaigns_table.php',
            '--realpath' => true,
        ]);

        config([
            'cashier.secret' => 'sk_test_campaign_model',
            'magic-starter.defaults.locale' => 'en',
            'magic-starter.supported_locales' => [
                'en',
                'tr',
            ],
            'magic-starter.billing.tier_order' => [
                'free',
                'pro',
            ],
            'magic-starter.billing.products' => [
                'pro_monthly' => [
                    'type' => 'subscription',
                    'tier' => 'pro',
                    'cycle' => 'monthly',
                    'prices' => [
                        'web' => [
                            'USD' => 2900,
                        ],
                    ],
                    'refs' => [
                        'stripe_price' => 'price_pro_monthly',
                    ],
                ],
                'pro_legacy' => [
                    'type' => 'subscription',
                    'tier' => 'pro',
                    'cycle' => 'monthly',
                    'sellable' => false,
                    'prices' => [
                        'web' => [
                            'USD' => 1900,
                        ],
                    ],
                    'refs' => [
                        'stripe_price' => 'price_pro_legacy',
                    ],
                ],
                'free_monthly' => [
                    'type' => 'subscription',
                    'tier' => 'free',
                    'cycle' => 'monthly',
                    'prices' => [
                        'web' => [
                            'USD' => 0,
                        ],
                    ],
                    'refs' => [
                        'stripe_price' => 'price_free_monthly',
                    ],
                ],
                'starter_monthly' => [
                    'type' => 'subscription',
                    'tier' => 'starter',
                    'cycle' => 'monthly',
                    'prices' => [
                        'web' => [
                            'USD' => 900,
                        ],
                    ],
                    'refs' => [
                        'stripe_price' => 'price_starter_monthly',
                    ],
                ],
                'credits_pack' => [
                    'type' => 'consumable',
                    'credits' => 100,
                    'prices' => [
                        'web' => [
                            'USD' => 900,
                        ],
                    ],
                    'refs' => [
                        'stripe_price' => 'price_credits_pack',
                    ],
                ],
            ],
        ]);

        $this->stripe = StripeHttpStub::install();
    }

    protected function tearDown(): void
    {
        StripeHttpStub::uninstall();
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_campaigns_feature_has_a_key_and_a_reader(): void
    {
        $this->assertSame('campaigns', Features::campaigns());
        $this->assertFalse(Features::hasCampaignFeatures());

        config(['magic-starter.features' => [Features::campaigns()]]);

        $this->assertTrue(Features::hasCampaignFeatures());
    }

    public function test_the_contracts_resolve_to_the_package_actions(): void
    {
        $this->assertInstanceOf(CreateCampaign::class, app(CreatesCampaigns::class));
        $this->assertInstanceOf(UpdateCampaign::class, app(UpdatesCampaigns::class));
    }

    public function test_it_casts_its_columns(): void
    {
        $campaign = $this->makeCampaign([
            'discount_type' => 'amount',
            'discount_value' => 500,
            'discount_currency' => 'USD',
            'currency_options' => [
                'TRY' => 15000,
            ],
        ])->fresh();

        $this->assertInstanceOf(Carbon::class, $campaign->starts_at);
        $this->assertInstanceOf(Carbon::class, $campaign->ends_at);
        $this->assertTrue($campaign->enabled);
        $this->assertSame(['pro_monthly'], $campaign->products);
        $this->assertSame('Autumn sale', $campaign->headline['en']);
        $this->assertSame(CampaignDiscountType::AMOUNT, $campaign->discount_type);
        $this->assertSame(500.0, $campaign->discount_value);
        $this->assertSame(['TRY' => 15000], $campaign->currency_options);
    }

    public function test_active_at_includes_the_start_and_excludes_the_end(): void
    {
        $campaign = $this->makeCampaign([
            'starts_at' => '2026-11-01 00:00:00',
            'ends_at' => '2026-11-08 00:00:00',
        ]);

        $this->assertFalse(Campaign::query()->activeAt(Carbon::parse('2026-10-31 23:59:59'))->exists());
        $this->assertTrue(Campaign::query()->activeAt(Carbon::parse('2026-11-01 00:00:00'))->whereKey($campaign->id)->exists());
        $this->assertTrue(Campaign::query()->activeAt(Carbon::parse('2026-11-07 23:59:59'))->exists());
        $this->assertFalse(Campaign::query()->activeAt(Carbon::parse('2026-11-08 00:00:00'))->exists());
    }

    public function test_active_at_defaults_to_now_and_skips_a_disabled_campaign(): void
    {
        $this->makeCampaign([
            'starts_at' => '2026-11-01 00:00:00',
            'ends_at' => '2026-11-08 00:00:00',
            'enabled' => false,
        ]);

        Carbon::setTestNow('2026-11-03 12:00:00');

        $this->assertFalse(Campaign::query()->activeAt()->exists());

        Campaign::query()->update(['enabled' => true]);

        $this->assertTrue(Campaign::query()->activeAt()->exists());
    }

    public function test_create_stores_a_disabled_campaign_without_asking_stripe(): void
    {
        $campaign = app(CreatesCampaigns::class)->create(new ConcreteUser, $this->input([
            'headline' => [
                'en' => 'Autumn sale',
                'tr' => '',
            ],
        ]));

        $campaign = Campaign::query()->findOrFail($campaign->getKey());

        $this->assertFalse($campaign->enabled);
        $this->assertSame(['en' => 'Autumn sale'], $campaign->headline);
        $this->assertNull($campaign->discount_type);
        $this->assertSame([], $this->stripe->requests);
    }

    public function test_the_end_must_follow_the_start(): void
    {
        $this->assertRefused('ends_at', $this->input([
            'starts_at' => '2026-11-08 00:00:00',
            'ends_at' => '2026-11-08 00:00:00',
        ]));
    }

    public function test_the_default_locale_copy_is_required(): void
    {
        $this->assertRefused('headline.en', $this->input([
            'headline' => [
                'tr' => 'Sonbahar indirimi',
            ],
        ]));
        $this->assertRefused('body.en', $this->input([
            'body' => [
                'en' => '',
                'tr' => 'Yüzde 25 indirim',
            ],
        ]));
    }

    public function test_only_sellable_subscription_products_can_be_discounted(): void
    {
        foreach (['pro_legacy', 'credits_pack', 'free_monthly', 'starter_monthly', 'unknown'] as $product) {
            $this->assertRefused(
                'products.0',
                $this->input(['products' => [$product]]),
                (string) __('magic-starter::campaigns.refusals.product_not_eligible'),
            );
        }

        $this->assertRefused('products', $this->input(['products' => []]));
    }

    /**
     * The checkout's own rule: a floor-tier product or one whose tier is not
     * ranked is never sold on the card rail, so no campaign may advertise it.
     */
    public function test_the_eligible_products_are_the_ones_checkout_sells(): void
    {
        $this->assertSame(['pro_monthly'], array_keys(Campaign::eligibleProducts()));
    }

    public function test_an_enabled_campaign_overlapping_another_is_refused_before_stripe(): void
    {
        $this->makeCampaign([
            'name' => 'Spring sale',
            'starts_at' => '2026-11-01 00:00:00',
            'ends_at' => '2026-11-08 00:00:00',
        ]);

        $this->assertRefused('starts_at', $this->input([
            'enabled' => true,
            'starts_at' => '2026-11-07 00:00:00',
            'ends_at' => '2026-11-10 00:00:00',
        ]), (string) __('magic-starter::campaigns.refusals.overlap', ['name' => 'Spring sale']));

        $this->assertSame([], $this->stripe->requests);
    }

    public function test_a_disabled_campaign_may_overlap_an_enabled_one(): void
    {
        $this->makeCampaign([
            'starts_at' => '2026-11-01 00:00:00',
            'ends_at' => '2026-11-08 00:00:00',
        ]);

        app(CreatesCampaigns::class)->create(new ConcreteUser, $this->input([
            'starts_at' => '2026-11-02 00:00:00',
            'ends_at' => '2026-11-05 00:00:00',
        ]));

        $this->assertSame(2, Campaign::query()->count());
    }

    public function test_updating_an_enabled_campaign_does_not_overlap_itself(): void
    {
        $campaign = $this->makeCampaign([
            'starts_at' => '2026-11-01 00:00:00',
            'ends_at' => '2026-11-08 00:00:00',
        ]);

        app(UpdatesCampaigns::class)->update(new ConcreteUser, $campaign, $this->input([
            'enabled' => true,
            'name' => 'Renamed',
        ]));

        $this->assertSame('Renamed', $campaign->fresh()->name);
        $this->assertSame([], $this->stripe->requests);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeCampaign(array $overrides = []): Campaign
    {
        return Campaign::query()->create([
            'name' => 'Autumn sale',
            'starts_at' => '2026-11-01 00:00:00',
            'ends_at' => '2026-11-08 00:00:00',
            'enabled' => true,
            'products' => ['pro_monthly'],
            'headline' => ['en' => 'Autumn sale'],
            'body' => ['en' => '25% off Pro'],
            'stripe_promotion_code_id' => 'promo_campaign',
            'discount_type' => 'percent',
            'discount_value' => 25,
            ...$overrides,
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
            'ends_at' => '2026-11-08 00:00:00',
            'enabled' => false,
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
     * @param  array<string, mixed>  $input
     */
    private function assertRefused(string $field, array $input, ?string $message = null): void
    {
        try {
            app(CreatesCampaigns::class)->create(new ConcreteUser, $input);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();

            $this->assertArrayHasKey($field, $errors, json_encode($errors) ?: '');

            if ($message !== null) {
                $this->assertSame($message, $errors[$field][0]);
            }

            return;
        }

        $this->fail(sprintf('Expected [%s] to be refused.', $field));
    }
}
