<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\Teams\RelationManagers;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FlutterSdk\MagicStarter\Contracts\AddsTeamMembers;
use FlutterSdk\MagicStarter\Contracts\RemovesTeamMembers;
use FlutterSdk\MagicStarter\Contracts\TransfersTeamOwnership;
use FlutterSdk\MagicStarter\Contracts\UpdatesTeamMemberRoles;
use FlutterSdk\MagicStarter\Enums\Role;
use FlutterSdk\MagicStarter\Filament\Resources\Teams\TeamResource;
use FlutterSdk\MagicStarter\Filament\Support\ContractAction;
use FlutterSdk\MagicStarter\MagicStarter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A team's members, with the writes that change who they are and what they may do.
 *
 * The owner's row carries no remove, role or transfer action. Filament treats a
 * hidden action as disabled, so a crafted call cannot mount one either; the
 * contracts refuse the same writes regardless, as the second line.
 */
class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    /**
     * Fixed rather than read from the shared static property, as the resource
     * base does: the panel is the gate, and the contracts hold the refusals.
     */
    public static function shouldSkipAuthorization(): bool
    {
        return true;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return (string) __('magic-starter::admin_teams.members.heading');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('magic-starter::admin_teams.members.columns.name'))
                    ->searchable(),
                TextColumn::make('email')
                    ->label(__('magic-starter::admin_teams.members.columns.email'))
                    ->searchable(),
                TextColumn::make('role')
                    ->label(__('magic-starter::admin_teams.members.columns.role'))
                    ->state(static fn (Model $record): ?string => static::pivotRole($record))
                    ->formatStateUsing(static fn (?string $state): string => TeamResource::roleLabel($state))
                    ->badge(),
            ])
            ->headerActions([
                $this->addAction(),
            ])
            ->recordActions([
                $this->changeRoleAction(),
                $this->makeOwnerAction(),
                $this->removeAction(),
            ]);
    }

    protected function addAction(): Action
    {
        return Action::make('add')
            ->label(__('magic-starter::admin_teams.members.actions.add.label'))
            ->icon(Heroicon::OutlinedUserPlus)
            ->schema([
                TextInput::make('email')
                    ->label(__('magic-starter::admin_teams.members.fields.email'))
                    ->email()
                    ->required(),
                Select::make('role')
                    ->label(__('magic-starter::admin_teams.members.fields.role'))
                    ->options(TeamResource::roleOptions())
                    ->default(Role::MEMBER->value)
                    ->required(),
            ])
            ->successNotificationTitle(__('magic-starter::admin_teams.members.actions.add.success'))
            ->action(function (array $data, Action $action): void {
                $team = $this->getOwnerRecord();

                // The audit row names the member by key, as every other member
                // action does, so a later account purge can find and drop it;
                // an email in the context would outlive the person.
                $member = MagicStarter::userModel()::query()
                    ->where('email', Str::lower($data['email']))
                    ->first();

                ContractAction::run(
                    $action,
                    static fn () => app(AddsTeamMembers::class)->add(
                        TeamResource::actor(),
                        $team,
                        $data['email'],
                        $data['role'],
                    ),
                    'team.member_added',
                    $team,
                    ['member_id' => (string) $member?->getKey(), 'role' => $data['role']],
                );
            });
    }

    protected function changeRoleAction(): Action
    {
        return Action::make('changeRole')
            ->label(__('magic-starter::admin_teams.members.actions.change_role.label'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->schema([
                Select::make('role')
                    ->label(__('magic-starter::admin_teams.members.fields.role'))
                    ->options(TeamResource::roleOptions())
                    ->required(),
            ])
            ->fillForm(static fn (Model $record): array => [
                'role' => static::pivotRole($record),
            ])
            ->hidden(fn (Model $record): bool => $this->isOwner($record))
            ->successNotificationTitle(__('magic-starter::admin_teams.members.actions.change_role.success'))
            ->action(function (Model $record, array $data, Action $action): void {
                $team = $this->getOwnerRecord();

                ContractAction::run(
                    $action,
                    static fn () => app(UpdatesTeamMemberRoles::class)->update(
                        TeamResource::actor(),
                        $team,
                        $record,
                        $data['role'],
                    ),
                    'team.member_role_updated',
                    $team,
                    ['member_id' => (string) $record->getKey(), 'role' => $data['role']],
                );
            });
    }

    protected function makeOwnerAction(): Action
    {
        return Action::make('makeOwner')
            ->label(__('magic-starter::admin_teams.members.actions.make_owner.label'))
            ->icon(Heroicon::OutlinedKey)
            ->requiresConfirmation()
            ->modalHeading(__('magic-starter::admin_teams.members.actions.make_owner.heading'))
            ->modalDescription(__('magic-starter::admin_teams.members.actions.make_owner.description'))
            ->hidden(fn (Model $record): bool => $this->isOwner($record))
            ->successNotificationTitle(__('magic-starter::admin_teams.members.actions.make_owner.success'))
            ->action(function (Model $record, Action $action): void {
                $team = $this->getOwnerRecord();

                ContractAction::run(
                    $action,
                    static fn () => app(TransfersTeamOwnership::class)->transfer(
                        TeamResource::actor(),
                        $team,
                        $record,
                    ),
                    'team.ownership_transferred',
                    $team,
                    ['member_id' => (string) $record->getKey()],
                );
            });
    }

    protected function removeAction(): Action
    {
        return Action::make('remove')
            ->label(__('magic-starter::admin_teams.members.actions.remove.label'))
            ->icon(Heroicon::OutlinedUserMinus)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('magic-starter::admin_teams.members.actions.remove.heading'))
            ->hidden(fn (Model $record): bool => $this->isOwner($record))
            ->successNotificationTitle(__('magic-starter::admin_teams.members.actions.remove.success'))
            ->action(function (Model $record, Action $action): void {
                $team = $this->getOwnerRecord();

                ContractAction::run(
                    $action,
                    static fn () => app(RemovesTeamMembers::class)->remove(TeamResource::actor(), $team, $record),
                    'team.member_removed',
                    $team,
                    ['member_id' => (string) $record->getKey()],
                );
            });
    }

    /**
     * The role stored on the membership row the relationship loaded with the member.
     */
    protected static function pivotRole(Model $member): ?string
    {
        return $member->getRelation('pivot')->role;
    }

    protected function isOwner(Model $member): bool
    {
        return (string) $this->getOwnerRecord()->getAttribute('user_id') === (string) $member->getKey();
    }
}
