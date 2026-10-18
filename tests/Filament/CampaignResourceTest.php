<?php

namespace FlutterSdk\MagicStarter\Tests\Filament;

use Carbon\CarbonInterface;
use Filament\Panel;
use FlutterSdk\MagicStarter\Enums\CampaignDiscountType;
use FlutterSdk\MagicStarter\Events\AdminActionPerformed;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Filament\MagicStarterPlugin;
use FlutterSdk\MagicStarter\Filament\Resources\Campaigns\CampaignResource;
use FlutterSdk\MagicStarter\Filament\Resources\Campaigns\Pages\CreateCampaign;
use FlutterSdk\MagicStarter\Filament\Resources\Campaigns\Pages\EditCampaign;
use FlutterSdk\MagicStarter\Filament\Resources\Campaigns\Pages\ListCampaigns;
use FlutterSdk\MagicStarter\Models\Campaign;
use FlutterSdk\MagicStarter\Support\CampaignCodeValidator;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteAdminUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Cashier\Cashier;
use Livewire\Livewire;

/**
 * Staff manage time-boxed Stripe promotion-code campaigns in the panel.
 *
 * The Stripe check is replaced by a validator that answers a fixed snapshot (or
 * refuses on demand): its own behaviour is pinned in CampaignCodeValidatorTest,
 * and what this proves is that the form hands the contract the right input and
 * shows every refusal next to the field it names.
 */
class CampaignResourceTest extends FilamentTestCase
{
    /**
     * The validator the contracts resolve; `checked` lists the code ids it was asked about.
     *
     * @var object{checked: list<string>, refusal: ValidationException|null}
     */
    private object $validator;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('magic-starter.features', [Features::campaigns()]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
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
                'pro_monthly' => $this->subscription('pro', 'price_pro_monthly'),
                'business_monthly' => $this->subscription('business', 'price_business_monthly'),
                'pro_retired' => [
                    ...$this->subscription('pro', 'price_pro_retired'),
                    'sellable' => false,
                ],
                'pro_unpriced' => $this->subscription('pro', null),
                'free_monthly' => $this->subscription('free', 'price_free_monthly'),
            ],
        ]);

        $this->validator = new class extends CampaignCodeValidator
        {
            /** @var list<string> */
            public array $checked = [];

            public ?ValidationException $refusal = null;

            public function __construct() {}

            public function validate(string $promotionCodeId, array $products, CarbonInterface $endsAt): array
            {
                $this->checked[] = $promotionCodeId;

                if ($this->refusal !== null) {
                    throw $this->refusal;
                }

                return [
                    'discount_type' => CampaignDiscountType::PERCENT,
                    'discount_value' => 25.0,
                    'discount_currency' => null,
                    'currency_options' => null,
                ];
            }
        };

        $this->app->instance(CampaignCodeValidator::class, $this->validator);
    }

    public function test_the_resource_is_mounted_only_with_the_campaigns_feature(): void
    {
        config()->set('magic-starter.features', []);
        $off = Panel::make()->id('campaigns-off')->plugin(MagicStarterPlugin::make());

        config()->set('magic-starter.features', [Features::campaigns()]);
        $on = Panel::make()->id('campaigns-on')->plugin(MagicStarterPlugin::make());

        $this->assertNotContains(CampaignResource::class, $off->getResources());
        $this->assertContains(CampaignResource::class, $on->getResources());
    }

    public function test_the_resource_enumerates_index_create_and_edit(): void
    {
        $this->assertSame(['index', 'create', 'edit'], array_keys(CampaignResource::getPages()));
    }

    public function test_a_user_outside_the_panel_gate_cannot_reach_the_list(): void
    {
        $this->actingAs($this->staff())->get('/admin/campaigns')->assertOk();
        $this->actingAs($this->user('member@example.com'))->get('/admin/campaigns')->assertForbidden();
    }

    public function test_the_navigation_labels_resolve_in_english_and_turkish(): void
    {
        app()->setLocale('en');
        $this->assertSame('Campaigns', CampaignResource::getNavigationLabel());
        $this->assertSame('campaign', CampaignResource::getModelLabel());

        app()->setLocale('tr');
        $this->assertSame('Kampanyalar', CampaignResource::getNavigationLabel());
        $this->assertSame('kampanya', CampaignResource::getModelLabel());
    }

    public function test_the_form_labels_resolve_in_english_and_turkish(): void
    {
        app()->setLocale('en');
        $form = Livewire::actingAs($this->staff())->test(CreateCampaign::class)->assertSee('Promotion code id');
        $form->assertSee('Headline (TR)');

        app()->setLocale('tr');
        Livewire::actingAs($this->staff())->test(CreateCampaign::class)
            ->assertSee('Promosyon kodu kimliği')
            ->assertSee('Başlık (TR)');
    }

    public function test_the_product_choices_are_the_sellable_stripe_priced_subscriptions(): void
    {
        $form = Livewire::actingAs($this->staff())->test(CreateCampaign::class);

        $form->assertSee('pro_monthly')
            ->assertSee('business_monthly')
            ->assertDontSee('pro_retired')
            ->assertDontSee('pro_unpriced')
            ->assertDontSee('free_monthly');
    }

    public function test_creating_stores_the_form_and_the_snapshot_through_the_contract(): void
    {
        Event::fake([AdminActionPerformed::class]);

        Livewire::actingAs($this->staff())->test(CreateCampaign::class)
            ->fillForm($this->formData(['products' => ['pro_monthly', 'business_monthly']]))
            ->call('create')
            ->assertHasNoFormErrors();

        $campaign = Campaign::query()->sole();

        $this->assertSame('Autumn sale', $campaign->name);
        $this->assertTrue($campaign->enabled);
        $this->assertSame('2026-11-01 00:00:00', $campaign->starts_at->toDateTimeString());
        $this->assertSame('2026-11-08 00:00:00', $campaign->ends_at->toDateTimeString());
        $this->assertSame(['pro_monthly', 'business_monthly'], $campaign->products);
        $this->assertSame('Sonbahar indirimi', $campaign->headline['tr']);
        $this->assertSame('promo_autumn', $campaign->stripe_promotion_code_id);
        $this->assertSame(CampaignDiscountType::PERCENT, $campaign->discount_type);
        $this->assertSame(25.0, $campaign->discount_value);
        $this->assertSame(['promo_autumn'], $this->validator->checked);

        Event::assertDispatched(
            AdminActionPerformed::class,
            static fn (AdminActionPerformed $event): bool => $event->action === 'campaign.created'
                && $event->subject?->is($campaign) === true,
        );
    }

    public function test_a_blank_turkish_copy_is_dropped_and_a_blank_default_locale_is_required(): void
    {
        Livewire::actingAs($this->staff())->test(CreateCampaign::class)
            ->fillForm($this->formData([
                'headline' => ['en' => '', 'tr' => 'Sonbahar indirimi'],
                'body' => ['en' => '25% off Pro', 'tr' => ''],
            ]))
            ->call('create')
            ->assertHasFormErrors(['headline.en' => 'required']);

        $this->assertSame(0, Campaign::query()->count());

        Livewire::actingAs($this->staff())->test(CreateCampaign::class)
            ->fillForm($this->formData(['body' => ['en' => '25% off Pro', 'tr' => '']]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(['en' => '25% off Pro'], Campaign::query()->sole()->body);
    }

    public function test_an_end_before_the_start_is_refused_by_the_form(): void
    {
        Livewire::actingAs($this->staff())->test(CreateCampaign::class)
            ->fillForm($this->formData(['ends_at' => '2026-10-31 00:00:00']))
            ->call('create')
            ->assertHasFormErrors(['ends_at']);

        $this->assertSame(0, Campaign::query()->count());
        $this->assertSame([], $this->validator->checked);
    }

    public function test_an_end_equal_to_the_start_is_refused_by_the_contract_on_the_end_field(): void
    {
        Livewire::actingAs($this->staff())->test(CreateCampaign::class)
            ->fillForm($this->formData(['ends_at' => '2026-11-01 00:00:00']))
            ->call('create')
            ->assertHasFormErrors(['ends_at']);

        $this->assertSame(0, Campaign::query()->count());
    }

    public function test_an_overlapping_window_is_a_form_error_on_the_start_field_and_writes_nothing(): void
    {
        $this->campaign(['name' => 'Running sale']);

        $create = Livewire::actingAs($this->staff())->test(CreateCampaign::class)
            ->fillForm($this->formData(['starts_at' => '2026-11-05 00:00:00', 'ends_at' => '2026-11-12 00:00:00']))
            ->call('create')
            ->assertHasFormErrors(['starts_at']);

        $this->assertStringContainsString('Running sale', $create->errors()->first('data.starts_at'));
        $this->assertSame(1, Campaign::query()->count());
        $this->assertSame([], $this->validator->checked);
    }

    public function test_a_refused_promotion_code_is_a_form_error_on_the_code_field(): void
    {
        $this->validator->refusal = ValidationException::withMessages([
            'stripe_promotion_code_id' => 'This promotion code is inactive in Stripe.',
        ]);

        $create = Livewire::actingAs($this->staff())->test(CreateCampaign::class)
            ->fillForm($this->formData())
            ->call('create')
            ->assertHasFormErrors(['stripe_promotion_code_id']);

        $this->assertSame(
            'This promotion code is inactive in Stripe.',
            $create->errors()->first('data.stripe_promotion_code_id'),
        );
        $this->assertSame(0, Campaign::query()->count());
    }

    public function test_the_edit_form_hydrates_the_campaign_and_saves_copy_without_asking_stripe(): void
    {
        Event::fake([AdminActionPerformed::class]);

        $campaign = $this->campaign();

        Livewire::actingAs($this->staff())->test(EditCampaign::class, ['record' => $campaign->getKey()])
            ->assertFormSet([
                'name' => 'Autumn sale',
                'enabled' => true,
                'products' => ['pro_monthly'],
                'headline.en' => 'Autumn sale',
                'stripe_promotion_code_id' => 'promo_autumn',
            ])
            ->fillForm(['headline' => ['en' => 'Last week of autumn', 'tr' => '']])
            ->call('save')
            ->assertHasNoFormErrors();

        $campaign->refresh();

        $this->assertSame(['en' => 'Last week of autumn'], $campaign->headline);
        $this->assertSame([], $this->validator->checked);

        Event::assertDispatched(
            AdminActionPerformed::class,
            static fn (AdminActionPerformed $event): bool => $event->action === 'campaign.updated'
                && $event->subject?->is($campaign) === true,
        );
    }

    public function test_an_edit_that_overlaps_another_campaign_is_a_form_error_and_keeps_the_stored_row(): void
    {
        $this->campaign(['name' => 'Running sale']);
        $later = $this->campaign([
            'name' => 'Winter sale',
            'starts_at' => '2026-12-01 00:00:00',
            'ends_at' => '2026-12-08 00:00:00',
        ]);

        Livewire::actingAs($this->staff())->test(EditCampaign::class, ['record' => $later->getKey()])
            ->fillForm(['starts_at' => '2026-11-03 00:00:00'])
            ->call('save')
            ->assertHasFormErrors(['starts_at']);

        $this->assertSame('2026-12-01 00:00:00', $later->fresh()->starts_at->toDateTimeString());
    }

    public function test_the_table_shows_the_discount_and_counts_only_this_campaigns_checkouts(): void
    {
        $percent = $this->campaign(['name' => 'Percent sale']);
        $amount = $this->campaign([
            'name' => 'Amount sale',
            'enabled' => false,
            'starts_at' => '2026-12-01 00:00:00',
            'ends_at' => '2026-12-08 00:00:00',
            'discount_type' => 'amount',
            'discount_value' => 500,
            'discount_currency' => 'USD',
            'currency_options' => ['TRY' => 15000],
        ]);

        $this->redemption($percent->getKey());
        $this->redemption($percent->getKey());
        $this->redemption($amount->getKey());
        $this->redemption($percent->getKey(), type: 'checkout_started');

        Livewire::actingAs($this->staff())->test(ListCampaigns::class)
            ->assertCanSeeTableRecords([$percent, $amount])
            ->assertTableColumnStateSet('discount', '25%', $percent)
            // Through Cashier's formatter, since its output follows the host's ICU build ("$5.00"
            // locally, "$ 5.00" and "TRY 150.00" on CI): the column's job is every currency, joined.
            ->assertTableColumnStateSet(
                'discount',
                Cashier::formatAmount(500, 'USD') . ' / ' . Cashier::formatAmount(15000, 'TRY'),
                $amount,
            )
            ->assertTableColumnStateSet('campaign_checkouts', 2, $percent)
            ->assertTableColumnStateSet('campaign_checkouts', 1, $amount);
    }

    public function test_the_active_now_filter_lists_only_the_campaign_that_is_running(): void
    {
        $active = $this->campaign([
            'name' => 'Running',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);
        $expired = $this->campaign([
            'name' => 'Expired',
            'starts_at' => now()->subDays(9),
            'ends_at' => now()->subDays(2),
        ]);
        $upcoming = $this->campaign([
            'name' => 'Upcoming',
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(9),
        ]);
        $disabled = $this->campaign([
            'name' => 'Switched off',
            'enabled' => false,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);

        $list = Livewire::actingAs($this->staff())->test(ListCampaigns::class)
            ->assertCanSeeTableRecords([$active, $expired, $upcoming, $disabled]);

        $list->filterTable('active_now')
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$expired, $upcoming, $disabled]);
    }

    /**
     * @return array<string, mixed>
     */
    private function subscription(string $tier, ?string $price): array
    {
        return [
            'type' => 'subscription',
            'tier' => $tier,
            'cycle' => 'monthly',
            'prices' => [
                'web' => [
                    'USD' => 2900,
                ],
            ],
            'refs' => [
                'stripe_price' => $price,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function formData(array $overrides = []): array
    {
        return [
            'name' => 'Autumn sale',
            'enabled' => true,
            'starts_at' => '2026-11-01 00:00:00',
            'ends_at' => '2026-11-08 00:00:00',
            'products' => ['pro_monthly'],
            'headline' => [
                'en' => 'Autumn sale',
                'tr' => 'Sonbahar indirimi',
            ],
            'body' => [
                'en' => '25% off Pro',
                'tr' => 'Pro için yüzde 25 indirim',
            ],
            'stripe_promotion_code_id' => 'promo_autumn',
            ...$overrides,
        ];
    }

    /**
     * A stored enabled campaign whose code has been checked.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function campaign(array $overrides = []): Campaign
    {
        return Campaign::query()->create([
            'name' => 'Autumn sale',
            'starts_at' => '2026-11-01 00:00:00',
            'ends_at' => '2026-11-08 00:00:00',
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
            'stripe_promotion_code_id' => 'promo_autumn',
            'discount_type' => 'percent',
            'discount_value' => 25,
            ...$overrides,
        ]);
    }

    /**
     * A billing event written the way the redemption webhook will, by the type's string value.
     */
    private function redemption(string|int $campaignId, string $type = 'campaign_redeemed'): void
    {
        DB::table('billing_events')->insert([
            'id' => (string) Str::uuid(),
            'type' => $type,
            'source' => 'webhook',
            'properties' => json_encode(['campaign_id' => $campaignId]),
            'created_at' => Carbon::now(),
        ]);
    }

    private function staff(): ConcreteAdminUser
    {
        config()->set('magic-starter.admin.emails', ['staff@example.com']);

        return ConcreteAdminUser::query()->firstOrCreate(['email' => 'staff@example.com'], [
            'name' => 'Staff',
            'password' => 'secret',
            'email_verified_at' => Carbon::now(),
        ]);
    }

    private function user(string $email): ConcreteAdminUser
    {
        return ConcreteAdminUser::query()->create([
            'name' => strstr($email, '@', true),
            'email' => $email,
            'password' => 'secret',
            'email_verified_at' => Carbon::now(),
        ]);
    }
}
