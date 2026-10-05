<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\Users\RelationManagers;

use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FlutterSdk\MagicStarter\Contracts\RevokesApiTokens;
use FlutterSdk\MagicStarter\Filament\Support\ContractAction;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * The user's API tokens, one of which can be revoked through the contract.
 */
class TokensRelationManager extends RelationManager
{
    protected static string $relationship = 'tokens';

    protected static bool $shouldSkipAuthorization = true;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return method_exists($ownerRecord, 'tokens');
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return (string) __('magic-starter::admin_users.relations.tokens.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('magic-starter::admin_users.relations.tokens.name')),
                TextColumn::make('ip_address')
                    ->label(__('magic-starter::admin_users.relations.tokens.ip_address')),
                TextColumn::make('last_used_at')
                    ->label(__('magic-starter::admin_users.relations.tokens.last_used_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('expires_at')
                    ->label(__('magic-starter::admin_users.relations.tokens.expires_at'))
                    ->dateTime(),
                TextColumn::make('created_at')
                    ->label(__('magic-starter::admin_users.relations.tokens.created_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('revoke')
                    ->label(__('magic-starter::admin_users.relations.tokens.revoke'))
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->successNotificationTitle(__('magic-starter::admin_users.relations.tokens.revoked'))
                    ->action(function (Action $action, Model $record): void {
                        /** @var Model&Authenticatable $owner */
                        $owner = $this->getOwnerRecord();

                        ContractAction::run(
                            $action,
                            fn () => app(RevokesApiTokens::class)->revoke($owner, (string) $record->getKey()),
                            'user.tokens_revoked',
                            $owner,
                        );

                        $action->success();
                    }),
            ]);
    }
}
