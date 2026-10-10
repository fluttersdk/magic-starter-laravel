<?php

namespace FlutterSdk\MagicStarter\Tests\Filament;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use FlutterSdk\MagicStarter\Contracts\AdministersBilling;
use FlutterSdk\MagicStarter\Enums\BillingEventType;
use FlutterSdk\MagicStarter\Enums\BillingProvider;
use FlutterSdk\MagicStarter\Enums\BillingSource;
use FlutterSdk\MagicStarter\Enums\PlanStatus;
use FlutterSdk\MagicStarter\Events\AdminActionPerformed;
use FlutterSdk\MagicStarter\Filament\Support\ContractAction;
use FlutterSdk\MagicStarter\Models\BillingEvent;
use FlutterSdk\MagicStarter\Support\BillingEventRecorder;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteAdminUser;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Livewire;
use LogicException;

/**
 * The contract-action runner inside a panel that wraps every action in a
 * database transaction.
 *
 * A billing refusal writes its `request_refused` row before it throws, and that
 * row is the only record the operator was refused, so the halt must commit the
 * panel's transaction rather than roll it back. The validation case is the
 * control: it proves the panel transaction is really there, because without it
 * the surviving row above would prove nothing.
 *
 * The component is an anonymous class built inside a method: the no-Filament CI
 * job loads this file, and a top-level class extending a Filament type would
 * fatal before the suite could skip.
 */
class ContractActionTest extends FilamentTestCase
{
    /**
     * The contract call the fixture component's action runs.
     *
     * @var (Closure(): mixed)|null
     */
    public static ?Closure $call = null;

    protected function setUp(): void
    {
        parent::setUp();

        $panel = Filament::getPanel('admin')->databaseTransactions();
        Filament::bootCurrentPanel();

        $this->assertTrue($panel->hasDatabaseTransactions());
    }

    protected function tearDown(): void
    {
        self::$call = null;

        parent::tearDown();
    }

    public function test_a_billing_refusal_halts_without_rolling_back_its_refusal_row(): void
    {
        Event::fake([AdminActionPerformed::class]);
        $admin = $this->admin();
        $billable = $this->admin('payer@example.test');
        $billable->forceFill([
            'plan' => 'pro',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::STRIPE->value,
        ])->save();

        self::$call = fn () => app(AdministersBilling::class)->grant($admin, $billable, 'pro', 'Comp', null);

        Livewire::actingAs($admin)
            ->test($this->refusingComponent())
            ->callAction('run')
            ->assertNotified(__('magic-starter::admin_billing.refusals.paid_rail_active'));

        $refused = BillingEvent::query()->where('type', BillingEventType::REQUEST_REFUSED->value)->get();
        $this->assertCount(1, $refused);
        $this->assertSame('paid_rail_active', $refused[0]->reason);
        $this->assertSame(BillingSource::ADMIN, $refused[0]->source);
        Event::assertNotDispatched(AdminActionPerformed::class);
    }

    public function test_a_validation_refusal_still_rolls_the_panel_transaction_back(): void
    {
        Event::fake([AdminActionPerformed::class]);

        self::$call = function (): never {
            app(BillingEventRecorder::class)->record(
                BillingEventType::REQUEST_REFUSED,
                BillingSource::ADMIN,
                null,
                reason: 'probe',
            );

            throw ValidationException::withMessages(['plan' => ['Refused by validation.']]);
        };

        Livewire::actingAs($this->admin())
            ->test($this->refusingComponent())
            ->callAction('run')
            ->assertNotified('Refused by validation.');

        $this->assertSame(0, BillingEvent::query()->count());
    }

    /**
     * A bare Livewire component with one action that runs {@see self::$call}
     * through {@see ContractAction}.
     */
    private function refusingComponent(): Component
    {
        return new class extends Component implements HasActions, HasSchemas
        {
            use InteractsWithActions;
            use InteractsWithSchemas;

            public function runAction(): Action
            {
                return Action::make('run')->action(function (Action $action): void {
                    ContractAction::run(
                        $action,
                        ContractActionTest::$call ?? throw new LogicException('No call configured.'),
                        'billing.grant_added',
                        null,
                    );
                });
            }

            public function render(): string
            {
                return '<div></div>';
            }
        };
    }

    private function admin(string $email = 'ops@example.test'): ConcreteAdminUser
    {
        $user = new ConcreteAdminUser;
        $user->forceFill([
            'name' => 'Ops',
            'email' => $email,
            'email_verified_at' => now(),
            'password' => 'secret',
        ])->save();

        return $user;
    }
}
