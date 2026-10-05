<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\Teams\RelationManagers;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FlutterSdk\MagicStarter\Contracts\CancelsTeamInvitations;
use FlutterSdk\MagicStarter\Contracts\InvitesTeamMembers;
use FlutterSdk\MagicStarter\Contracts\ResendsTeamInvitations;
use FlutterSdk\MagicStarter\Enums\Role;
use FlutterSdk\MagicStarter\Filament\Resources\Teams\TeamResource;
use FlutterSdk\MagicStarter\Filament\Support\ContractAction;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\TeamInvitation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A team's pending invitations: send one, cancel one, send one again.
 */
class InvitationsRelationManager extends RelationManager
{
    protected static string $relationship = 'invitations';

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
        return (string) __('magic-starter::admin_teams.invitations.heading');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('email')
            ->columns([
                TextColumn::make('email')
                    ->label(__('magic-starter::admin_teams.invitations.columns.email'))
                    ->searchable(),
                TextColumn::make('role')
                    ->label(__('magic-starter::admin_teams.invitations.columns.role'))
                    ->formatStateUsing(static fn (?string $state): string => TeamResource::roleLabel($state))
                    ->badge(),
                TextColumn::make('expires_at')
                    ->label(__('magic-starter::admin_teams.invitations.columns.expires_at'))
                    ->dateTime(),
                TextColumn::make('status')
                    ->label(__('magic-starter::admin_teams.invitations.columns.status'))
                    ->state(static fn (TeamInvitation $record): ?string => $record->isExpired()
                        ? (string) __('magic-starter::admin_teams.invitations.status.expired')
                        : null)
                    ->badge()
                    ->color('danger'),
            ])
            ->headerActions([
                $this->inviteAction(),
            ])
            ->recordActions([
                $this->resendAction(),
                $this->cancelAction(),
            ]);
    }

    protected function inviteAction(): Action
    {
        return Action::make('invite')
            ->label(__('magic-starter::admin_teams.invitations.actions.invite.label'))
            ->icon(Heroicon::OutlinedEnvelope)
            ->schema([
                TextInput::make('email')
                    ->label(__('magic-starter::admin_teams.invitations.fields.email'))
                    ->email()
                    ->required(),
                Select::make('role')
                    ->label(__('magic-starter::admin_teams.invitations.fields.role'))
                    ->options(TeamResource::roleOptions())
                    ->default(Role::MEMBER->value)
                    ->required(),
            ])
            ->successNotificationTitle(__('magic-starter::admin_teams.invitations.actions.invite.success'))
            ->action(function (array $data, Action $action): void {
                $team = $this->getOwnerRecord();
                $email = Str::lower($data['email']);

                ContractAction::run(
                    $action,
                    function () use ($team, $email, $data): void {
                        $this->ensureInvitable($team, $email);

                        app(InvitesTeamMembers::class)->invite(TeamResource::actor(), $team, $email, $data['role']);
                    },
                    'team.invitation_sent',
                    $team,
                );
            });
    }

    protected function resendAction(): Action
    {
        return Action::make('resend')
            ->label(__('magic-starter::admin_teams.invitations.actions.resend.label'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->successNotificationTitle(__('magic-starter::admin_teams.invitations.actions.resend.success'))
            ->action(function (Model $record, Action $action): void {
                ContractAction::run(
                    $action,
                    static fn () => app(ResendsTeamInvitations::class)->resend(TeamResource::actor(), $record),
                    'team.invitation_resent',
                    $record,
                );
            });
    }

    protected function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label(__('magic-starter::admin_teams.invitations.actions.cancel.label'))
            ->icon(Heroicon::OutlinedXMark)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('magic-starter::admin_teams.invitations.actions.cancel.heading'))
            ->successNotificationTitle(__('magic-starter::admin_teams.invitations.actions.cancel.success'))
            ->action(function (Model $record, Action $action): void {
                ContractAction::run(
                    $action,
                    static fn () => app(CancelsTeamInvitations::class)->cancel(TeamResource::actor(), $record),
                    'team.invitation_cancelled',
                    $record,
                );
            });
    }

    /**
     * The two refusals the invitation endpoint makes before it calls the
     * contract: a second invitation for the same address would hit the unique
     * `(team_id, email)` index, and a member needs no invitation.
     *
     * @throws ValidationException
     */
    private function ensureInvitable(Model $team, string $email): void
    {
        if ($this->getRelationship()->where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => [__('magic-starter::teams.invitations.already_sent')],
            ]);
        }

        $userModel = MagicStarter::userModel();
        $existing = $userModel::query()->where('email', $email)->first();

        if ($existing === null) {
            return;
        }

        $membershipModel = MagicStarter::membershipModel();
        $isMember = (string) $team->getAttribute('user_id') === (string) $existing->getKey()
            || $membershipModel::query()
                ->where('team_id', $team->getKey())
                ->where('user_id', $existing->getKey())
                ->exists();

        if ($isMember) {
            throw ValidationException::withMessages([
                'email' => [__('magic-starter::teams.members.already_a_member')],
            ]);
        }
    }
}
