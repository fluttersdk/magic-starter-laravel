<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\Users\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use FlutterSdk\MagicStarter\Contracts\CancelsUserDeletion;
use FlutterSdk\MagicStarter\Contracts\DisablesTwoFactorAuthentication;
use FlutterSdk\MagicStarter\Contracts\RevokesApiTokens;
use FlutterSdk\MagicStarter\Contracts\SchedulesUserDeletion;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Filament\Concerns\WritesThroughContracts;
use FlutterSdk\MagicStarter\Filament\Resources\Users\UserResource;
use FlutterSdk\MagicStarter\Filament\Support\ContractAction;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Model;

/**
 * The edit page. Its header actions are the account operations a person cannot
 * do for themselves, each one a package contract run through {@see ContractAction}.
 */
class EditUser extends EditRecord
{
    use WritesThroughContracts;

    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->resetTwoFactorAction(),
            $this->revokeTokensAction(),
            $this->resendVerificationAction(),
            $this->scheduleDeletionAction(),
            $this->cancelDeletionAction(),
        ];
    }

    protected function resetTwoFactorAction(): Action
    {
        return Action::make('reset_two_factor')
            ->label(__('magic-starter::admin_users.actions.reset_two_factor.label'))
            ->icon(Heroicon::OutlinedShieldExclamation)
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription(__('magic-starter::admin_users.actions.reset_two_factor.description'))
            ->successNotificationTitle(__('magic-starter::admin_users.actions.reset_two_factor.success'))
            ->visible(fn (): bool => Features::hasTwoFactorAuthenticationFeatures()
                && filled($this->user()->getAttribute('two_factor_secret')))
            ->action(function (Action $action): void {
                ContractAction::run(
                    $action,
                    fn () => app(DisablesTwoFactorAuthentication::class)->disable($this->user()),
                    'user.two_factor_reset',
                    $this->user(),
                );

                $action->success();
            });
    }

    protected function revokeTokensAction(): Action
    {
        return Action::make('revoke_tokens')
            ->label(__('magic-starter::admin_users.actions.revoke_tokens.label'))
            ->icon(Heroicon::OutlinedKey)
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription(__('magic-starter::admin_users.actions.revoke_tokens.description'))
            ->successNotificationTitle(__('magic-starter::admin_users.actions.revoke_tokens.success'))
            ->visible(fn (): bool => method_exists($this->user(), 'tokens'))
            ->action(function (Action $action): void {
                ContractAction::run(
                    $action,
                    fn () => app(RevokesApiTokens::class)->revoke($this->user()),
                    'user.tokens_revoked',
                    $this->user(),
                );

                $action->success();
            });
    }

    protected function resendVerificationAction(): Action
    {
        return Action::make('resend_verification')
            ->label(__('magic-starter::admin_users.actions.resend_verification.label'))
            ->icon(Heroicon::OutlinedEnvelope)
            ->successNotificationTitle(__('magic-starter::admin_users.actions.resend_verification.success'))
            ->visible(fn (): bool => Features::hasEmailVerificationFeatures()
                && $this->user() instanceof MustVerifyEmail
                && filled($this->user()->getAttribute('email'))
                && ! $this->user()->hasVerifiedEmail())
            ->action(function (Action $action): void {
                ContractAction::run(
                    $action,
                    fn () => $this->user()->sendEmailVerificationNotification(),
                    'user.verification_resent',
                    $this->user(),
                );

                $action->success();
            });
    }

    protected function scheduleDeletionAction(): Action
    {
        return Action::make('schedule_deletion')
            ->label(__('magic-starter::admin_users.actions.schedule_deletion.label'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('magic-starter::admin_users.actions.schedule_deletion.description'))
            ->schema([
                Checkbox::make('immediately')
                    ->label(__('magic-starter::admin_users.actions.schedule_deletion.immediately')),
            ])
            ->successNotificationTitle(__('magic-starter::admin_users.actions.schedule_deletion.success'))
            ->action(function (Action $action, array $data): void {
                ContractAction::run(
                    $action,
                    fn () => app(SchedulesUserDeletion::class)->schedule(
                        $this->user(),
                        immediately: (bool) ($data['immediately'] ?? false),
                    ),
                    'user.deletion_scheduled',
                    $this->user(),
                );

                $action->success();
            });
    }

    /**
     * An orphan (the identity provider deleted the account) cannot be cancelled,
     * so the action is not offered for one.
     */
    protected function cancelDeletionAction(): Action
    {
        return Action::make('cancel_deletion')
            ->label(__('magic-starter::admin_users.actions.cancel_deletion.label'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->requiresConfirmation()
            ->modalDescription(__('magic-starter::admin_users.actions.cancel_deletion.description'))
            ->successNotificationTitle(__('magic-starter::admin_users.actions.cancel_deletion.success'))
            ->failureNotificationTitle(__('magic-starter::admin_users.actions.cancel_deletion.failure'))
            ->visible(fn (): bool => $this->user()->getAttribute('deletion_scheduled_at') !== null
                && $this->user()->getAttribute('orphaned_at') === null)
            ->action(function (Action $action): void {
                $cancelled = ContractAction::run(
                    $action,
                    fn (): bool => app(CancelsUserDeletion::class)->cancel($this->user()),
                    'user.deletion_cancelled',
                    $this->user(),
                );

                $cancelled ? $action->success() : $action->failure();
            });
    }

    /**
     * The record as the Authenticatable the contracts take.
     *
     * @return Model&Authenticatable
     */
    private function user(): Model
    {
        /** @var Model&Authenticatable $user */
        $user = $this->getRecord();

        return $user;
    }
}
