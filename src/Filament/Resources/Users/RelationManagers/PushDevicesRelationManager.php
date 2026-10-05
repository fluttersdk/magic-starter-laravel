<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\Users\RelationManagers;

use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FlutterSdk\MagicStarter\Filament\Support\ContractAction;
use FlutterSdk\MagicStarter\Models\PushDevice;
use Illuminate\Database\Eloquent\Model;

/**
 * The push subscriptions the user's clients have reported on. Releasing one
 * removes the row, so a device nobody is signed into stops vouching for reachability.
 */
class PushDevicesRelationManager extends RelationManager
{
    protected static string $relationship = 'pushDevices';

    protected static bool $shouldSkipAuthorization = true;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return method_exists($ownerRecord, 'pushDevices');
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return (string) __('magic-starter::admin_users.relations.push_devices.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('subscription_id')
                    ->label(__('magic-starter::admin_users.relations.push_devices.subscription_id'))
                    ->limit(24)
                    ->copyable(),
                TextColumn::make('external_id')
                    ->label(__('magic-starter::admin_users.relations.push_devices.external_id')),
                TextColumn::make('reachability')
                    ->label(__('magic-starter::admin_users.relations.push_devices.reachability'))
                    ->badge()
                    ->color(static fn (string $state): string => $state === PushDevice::REACHABLE ? 'success' : 'gray'),
                TextColumn::make('reported_at')
                    ->label(__('magic-starter::admin_users.relations.push_devices.reported_at'))
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('reported_at', 'desc')
            ->recordActions([
                Action::make('release')
                    ->label(__('magic-starter::admin_users.relations.push_devices.release'))
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->successNotificationTitle(__('magic-starter::admin_users.relations.push_devices.released'))
                    ->visible(static fn (Model $record): bool => filled($record->getAttribute('subscription_id')))
                    ->action(function (Action $action, Model $record): void {
                        $owner = $this->getOwnerRecord();

                        ContractAction::run(
                            $action,
                            fn () => PushDevice::release($owner, (string) $record->getAttribute('subscription_id')),
                            'user.push_device_released',
                            $owner,
                        );

                        $action->success();
                    }),
            ]);
    }
}
