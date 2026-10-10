<?php

namespace FlutterSdk\MagicStarter\Console;

use FlutterSdk\MagicStarter\Actions\AdministerBilling;
use FlutterSdk\MagicStarter\Contracts\AdministersBilling;
use FlutterSdk\MagicStarter\Enums\GrantEndReason;
use FlutterSdk\MagicStarter\Models\BillingGrant;
use Illuminate\Console\Command;
use Throwable;

/**
 * Ends the manual plan grants whose time has come: an expired grant the
 * billable is still on is revoked and closed as `expired`, and an open grant
 * the billable has moved off (a paid rail or a newer grant took the record) is
 * closed as `superseded` without any write.
 *
 * Every open grant is visited, not only the expired ones, because a grant a
 * paid rail took over would otherwise stay open until its expiry and then be
 * mistaken for the one on record. Each grant is settled by
 * {@see AdministerBilling::settleGrant()}, which ends it whatever the write
 * path answers, so a dropped revocation is not retried every hour.
 *
 * The package action is injected rather than the {@see AdministersBilling}
 * contract: settling a grant is this sweep's step, not an operator's act, so it
 * is not part of the contract a consumer overrides.
 *
 * Each grant is settled on its own: one that fails (a billable whose model
 * is gone, a database fault) is reported, counted and passed over, so it
 * cannot hold back every grant after it. The run then exits non-zero.
 *
 * Scheduled hourly by the provider while billing is on. A table the adopter
 * has not migrated yet is skipped with a line rather than failing the run.
 */
class ExpireBillingGrantsCommand extends Command
{
    public const NAME = 'magic-starter:billing:expire-grants';

    /**
     * Open grants held in memory at once.
     */
    public const CHUNK_SIZE = 100;

    /**
     * @var string
     */
    protected $signature = self::NAME;

    /**
     * @var string
     */
    protected $description = 'End the manual plan grants that expired or that a paid rail replaced';

    /**
     * Settle every open grant and print how many ended.
     *
     * @return int non-zero when any grant failed to settle
     */
    public function handle(AdministerBilling $administer): int
    {
        if (! (new BillingGrant)->getConnection()->getSchemaBuilder()->hasTable((new BillingGrant)->getTable())) {
            $this->components->info('Skipped grant expiry: billing_grants does not exist.');

            return self::SUCCESS;
        }

        $expired = 0;
        $superseded = 0;
        $failed = 0;

        // Keyset pagination, so closing a grant inside the walk does not shift
        // the page under it.
        BillingGrant::query()
            ->open()
            ->lazyById(self::CHUNK_SIZE)
            ->each(function (BillingGrant $grant) use ($administer, &$expired, &$superseded, &$failed): void {
                try {
                    $outcome = $administer->settleGrant($grant);
                } catch (Throwable $failure) {
                    report($failure);
                    $failed++;

                    return;
                }

                if ($outcome === GrantEndReason::EXPIRED) {
                    $expired++;
                } elseif ($outcome === GrantEndReason::SUPERSEDED) {
                    $superseded++;
                }
            });

        $this->components->info(sprintf('Expired %d grant(s), superseded %d.', $expired, $superseded));

        if ($failed === 0) {
            return self::SUCCESS;
        }

        $this->components->error(sprintf('%d grant(s) could not be settled; see the reported exceptions.', $failed));

        return self::FAILURE;
    }
}
