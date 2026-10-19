<?php

namespace FlutterSdk\MagicStarter\Tests\Fixtures;

use Carbon\CarbonInterface;
use FlutterSdk\MagicStarter\Contracts\AdministersBilling;
use FlutterSdk\MagicStarter\Models\BillingGrant;
use FlutterSdk\MagicStarter\Support\BillingAdministrationRefused;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * A stand-in for the billing contract: records every write it is asked for and
 * answers the two reads from properties a test sets.
 */
class RecordingBillingAdministration implements AdministersBilling
{
    /**
     * @var list<array{method: string, actor: Authenticatable, billable: Model, arguments: array<string, mixed>}>
     */
    private array $writes = [];

    public bool $paidRailGrants = false;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $refundablePayment = [
        'invoice_id' => 'in_paid',
        'payment_intent' => 'pi_paid',
        'amount' => 2900,
        'currency' => 'usd',
    ];

    public ?Throwable $refundablePaymentFailure = null;

    public int $refundablePaymentReads = 0;

    public ?BillingAdministrationRefused $refusal = null;

    public function grant(
        Authenticatable $actor,
        Model $billable,
        string $plan,
        string $reason,
        ?CarbonInterface $expiresAt,
    ): BillingGrant {
        $this->write('grant', $actor, $billable, [
            'plan' => $plan,
            'reason' => $reason,
            'expiresAt' => $expiresAt,
        ]);

        return new BillingGrant;
    }

    public function revoke(Authenticatable $actor, Model $billable, string $reason): void
    {
        $this->write('revoke', $actor, $billable, ['reason' => $reason]);
    }

    public function extendTrial(Authenticatable $actor, Model $billable, CarbonInterface $until): void
    {
        $this->write('extendTrial', $actor, $billable, ['until' => $until]);
    }

    public function endTrial(Authenticatable $actor, Model $billable): void
    {
        $this->write('endTrial', $actor, $billable);
    }

    public function cancel(Authenticatable $actor, Model $billable): void
    {
        $this->write('cancel', $actor, $billable);
    }

    public function resume(Authenticatable $actor, Model $billable): void
    {
        $this->write('resume', $actor, $billable);
    }

    public function refundLastInvoice(
        Authenticatable $actor,
        Model $billable,
        string $reason,
        ?string $expectedInvoiceId = null,
    ): string {
        $this->write('refundLastInvoice', $actor, $billable, [
            'reason' => $reason,
            'expectedInvoiceId' => $expectedInvoiceId,
        ]);

        return 're_fake';
    }

    public function sync(Authenticatable $actor, Model $billable): void
    {
        $this->write('sync', $actor, $billable);
    }

    public function paidRailGrants(Model $billable): bool
    {
        return $this->paidRailGrants;
    }

    public function refundablePayment(Model $billable): ?array
    {
        $this->refundablePaymentReads++;

        if ($this->refundablePaymentFailure !== null) {
            throw $this->refundablePaymentFailure;
        }

        return $this->refundablePayment;
    }

    /**
     * @return list<array{method: string, actor: Authenticatable, billable: Model, arguments: array<string, mixed>}>
     */
    public function writes(): array
    {
        return $this->writes;
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @throws BillingAdministrationRefused when a test armed a refusal
     */
    private function write(string $method, Authenticatable $actor, Model $billable, array $arguments = []): void
    {
        if ($this->refusal !== null) {
            throw $this->refusal;
        }

        $this->writes[] = [
            'method' => $method,
            'actor' => $actor,
            'billable' => $billable,
            'arguments' => $arguments,
        ];
    }
}
