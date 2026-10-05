<?php

namespace FlutterSdk\MagicStarter\Filament\Auth;

use Closure;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\Contracts\MultiFactorAuthenticationProvider;
use Filament\Forms\Components\OneTimeCodeInput;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Support\TwoFactorAuthenticationProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use SensitiveParameter;

/**
 * The panel login's second factor: the TOTP secret and recovery codes the user
 * set up through the starter's API.
 *
 * It reads the same columns and applies the same rule as the API challenge
 * (`AuthenticatesUsers`): the two-factor feature is on and the user confirmed a
 * code. It never writes a secret: two-factor is turned on through the API, and
 * turned off there or by an admin's `reset_two_factor` on the Users resource,
 * which also lifts this challenge.
 */
class TwoFactorAuthentication implements MultiFactorAuthenticationProvider
{
    public const ID = 'two_factor';

    public static function make(): static
    {
        return app(static::class);
    }

    public function __construct(
        protected TwoFactorAuthenticationProvider $totp,
    ) {}

    public function getId(): string
    {
        return static::ID;
    }

    public function isEnabled(Authenticatable $user): bool
    {
        return Features::hasTwoFactorAuthenticationFeatures()
            && method_exists($user, 'hasEnabledTwoFactorAuthentication')
            && $user->hasEnabledTwoFactorAuthentication();
    }

    public function getLoginFormLabel(): string
    {
        return __('filament-panels::auth/multi-factor/app/provider.login_form.label');
    }

    public function getManagementSchemaComponents(): array
    {
        return [
            Text::make(__('magic-starter::admin.two_factor.managed_in_app')),
        ];
    }

    public function getChallengeFormComponents(Authenticatable $user): array
    {
        return [
            OneTimeCodeInput::make('code')
                ->label(__('filament-panels::auth/multi-factor/app/provider.login_form.code.label'))
                ->belowContent(fn (Get $get): Action => Action::make('useRecoveryCode')
                    ->label(__('filament-panels::auth/multi-factor/app/provider.login_form.code.actions.use_recovery_code.label'))
                    ->link()
                    ->action(fn (Set $set) => $set('useRecoveryCode', true))
                    ->visible(fn (): bool => ! $get('useRecoveryCode')))
                ->validationAttribute(__('filament-panels::auth/multi-factor/app/provider.login_form.code.validation_attribute'))
                ->required(fn (Get $get): bool => (! $get('useRecoveryCode')) || blank($get('recoveryCode')))
                ->rule(fn (): Closure => function (string $attribute, #[SensitiveParameter] mixed $value, Closure $fail) use ($user): void {
                    if (is_string($value) && $this->verifyCode($user, $value)) {
                        return;
                    }

                    $fail(__('filament-panels::auth/multi-factor/app/provider.login_form.code.messages.invalid'));
                }),
            TextInput::make('recoveryCode')
                ->label(__('filament-panels::auth/multi-factor/app/provider.login_form.recovery_code.label'))
                ->validationAttribute(__('filament-panels::auth/multi-factor/app/provider.login_form.recovery_code.validation_attribute'))
                ->password()
                ->autocomplete('one-time-code')
                ->rule(fn (): Closure => function (string $attribute, #[SensitiveParameter] mixed $value, Closure $fail) use ($user): void {
                    if (blank($value)) {
                        return;
                    }

                    if (is_string($value) && $this->redeemRecoveryCode($user, $value)) {
                        return;
                    }

                    $fail(__('filament-panels::auth/multi-factor/app/provider.login_form.recovery_code.messages.invalid'));
                })
                ->visible(fn (Get $get): bool => (bool) $get('useRecoveryCode'))
                ->live(onBlur: true),
        ];
    }

    /**
     * The API challenge's check: one step of drift, and a code spent on either
     * surface is spent on both.
     *
     * @param  mixed  $user  a user model carrying TwoFactorAuthenticatable
     */
    protected function verifyCode(mixed $user, #[SensitiveParameter] string $code): bool
    {
        return $this->totp->verifyOnce($user->twoFactorSecret() ?? '', $code);
    }

    /**
     * @param  mixed  $user  a user model carrying TwoFactorAuthenticatable
     */
    protected function redeemRecoveryCode(mixed $user, #[SensitiveParameter] string $code): bool
    {
        return $user->redeemRecoveryCode($code);
    }
}
