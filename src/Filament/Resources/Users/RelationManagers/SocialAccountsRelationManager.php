<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\Users\RelationManagers;

use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FlutterSdk\MagicStarter\Contracts\DisconnectsSocialAccounts;
use FlutterSdk\MagicStarter\Filament\Support\ContractAction;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * The identities the user signs in with at a provider. Disconnecting one goes
 * through the contract, which refuses to remove the last way into the account.
 */
class SocialAccountsRelationManager extends RelationManager
{
    protected static string $relationship = 'socialAccounts';

    protected static bool $shouldSkipAuthorization = true;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return (string) __('magic-starter::admin_users.relations.social_accounts.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('provider')
                    ->label(__('magic-starter::admin_users.relations.social_accounts.provider'))
                    ->badge(),
                TextColumn::make('email_at_link')
                    ->label(__('magic-starter::admin_users.relations.social_accounts.email')),
                IconColumn::make('owner_confirmed')
                    ->label(__('magic-starter::admin_users.relations.social_accounts.owner_confirmed'))
                    ->boolean(),
                TextColumn::make('revoked_at')
                    ->label(__('magic-starter::admin_users.relations.social_accounts.revoked_at'))
                    ->dateTime(),
                TextColumn::make('created_at')
                    ->label(__('magic-starter::admin_users.relations.social_accounts.created_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('disconnect')
                    ->label(__('magic-starter::admin_users.relations.social_accounts.disconnect'))
                    ->icon(Heroicon::OutlinedLinkSlash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->successNotificationTitle(__('magic-starter::admin_users.relations.social_accounts.disconnected'))
                    ->action(function (Action $action, Model $record): void {
                        /** @var Model&Authenticatable $owner */
                        $owner = $this->getOwnerRecord();

                        ContractAction::run(
                            $action,
                            fn () => app(DisconnectsSocialAccounts::class)->disconnect(
                                $owner,
                                (string) $record->getAttribute('provider'),
                            ),
                            'user.social_disconnected',
                            $owner,
                        );

                        $action->success();
                    }),
            ]);
    }
}
