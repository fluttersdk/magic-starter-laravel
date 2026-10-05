<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\Teams\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use FlutterSdk\MagicStarter\Contracts\DeletesTeams;
use FlutterSdk\MagicStarter\Filament\Concerns\WritesThroughContracts;
use FlutterSdk\MagicStarter\Filament\Resources\Teams\TeamResource;
use FlutterSdk\MagicStarter\Filament\Support\ContractAction;

class EditTeam extends EditRecord
{
    use WritesThroughContracts;

    protected static string $resource = TeamResource::class;

    /**
     * A plain action rather than Filament's delete action: that one calls
     * `$record->delete()` and would skip the contract, including the refusal
     * while a subscription still funds the team.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('delete')
                ->label(__('magic-starter::admin_teams.actions.delete.label'))
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading(__('magic-starter::admin_teams.actions.delete.heading'))
                ->modalDescription(__('magic-starter::admin_teams.actions.delete.description'))
                ->successNotificationTitle(__('magic-starter::admin_teams.actions.delete.success'))
                ->successRedirectUrl(fn (): string => TeamResource::getUrl('index'))
                ->action(function (Action $action): void {
                    $team = $this->getRecord();

                    ContractAction::run(
                        $action,
                        static fn () => app(DeletesTeams::class)->delete($team),
                        'team.deleted',
                        $team,
                    );
                }),
        ];
    }
}
