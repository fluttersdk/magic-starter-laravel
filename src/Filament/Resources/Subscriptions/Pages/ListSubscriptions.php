<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\Subscriptions\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use FlutterSdk\MagicStarter\Console\ReconcileBillingEntitlements;
use FlutterSdk\MagicStarter\Filament\Resources\Subscriptions\SubscriptionResource;
use FlutterSdk\MagicStarter\Filament\Support\ContractAction;
use Illuminate\Support\Facades\Artisan;

class ListSubscriptions extends ListRecords
{
    protected static string $resource = SubscriptionResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('reconcile')
                ->label(__('magic-starter::admin_misc.subscriptions.reconcile.label'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->requiresConfirmation()
                ->action(fn (Action $action) => $this->reconcile($action)),
        ];
    }

    /**
     * Run the sweep that heals dropped webhooks and report how it ended.
     *
     * A non-zero exit means a rail could not be read, so the entitlement was
     * not checked; the operator has to see that rather than a green toast.
     */
    protected function reconcile(Action $action): void
    {
        $exit = ContractAction::run(
            $action,
            static fn (): int => Artisan::call(ReconcileBillingEntitlements::NAME),
            'billing.reconciled',
            null,
        );

        $notification = Notification::make()->body(trim(Artisan::output()));

        if ($exit === 0) {
            $notification->success()->title(__('magic-starter::admin_misc.subscriptions.reconcile.succeeded'));
        } else {
            $notification->danger()->title(__('magic-starter::admin_misc.subscriptions.reconcile.failed'));
        }

        $notification->send();
    }
}
