<?php

/**
 * Magic Starter API route definitions.
 *
 * Registers authentication, team management, profile, session, notification,
 * and social login routes conditionally based on enabled features. All routes
 * respect the configured route prefix from `config('magic-starter.route_prefix')`.
 */

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Http\Controllers\AuthController;
use FlutterSdk\MagicStarter\Http\Controllers\GuestAuthController;
use FlutterSdk\MagicStarter\Http\Controllers\NotificationController;
use FlutterSdk\MagicStarter\Http\Controllers\NotificationPreferenceController;
use FlutterSdk\MagicStarter\Http\Controllers\OtpController;
use FlutterSdk\MagicStarter\Http\Controllers\PasswordResetController;
use FlutterSdk\MagicStarter\Http\Controllers\ProfileController;
use FlutterSdk\MagicStarter\Http\Controllers\ProfilePhotoController;
use FlutterSdk\MagicStarter\Http\Controllers\SessionController;
use FlutterSdk\MagicStarter\Http\Controllers\TeamController;
use FlutterSdk\MagicStarter\Http\Controllers\TeamInvitationController;
use FlutterSdk\MagicStarter\Http\Controllers\TeamMemberController;
use FlutterSdk\MagicStarter\Http\Controllers\TeamPhotoController;
use FlutterSdk\MagicStarter\Http\Controllers\TwoFactorAuthenticationController;
use FlutterSdk\MagicStarter\Http\Controllers\TwoFactorChallengeController;
use FlutterSdk\MagicStarter\Http\Controllers\TwoFactorRecoveryCodeController;
use FlutterSdk\MagicStarter\Http\Controllers\EmailVerificationController;
use FlutterSdk\MagicStarter\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::prefix((string) config('magic-starter.route_prefix', ''))
    ->group(function (): void {
        Route::prefix('auth')->middleware('throttle:5,1')->group(function (): void {
            Route::post('register', [AuthController::class, 'register']);
            Route::post('login', [AuthController::class, 'login']);
            Route::post('social/{provider}', [AuthController::class, 'socialLogin']);

            Route::post('forgot-password', [PasswordResetController::class, 'sendResetLinkEmail']);
            Route::post('reset-password', [PasswordResetController::class, 'reset']);

            if (Features::enabled(Features::twoFactorAuthentication())) {
                Route::post('two-factor-challenge', [TwoFactorChallengeController::class, 'store']);
            }

            if (Features::hasGuestAuthFeatures()) {
                Route::post('guest', [GuestAuthController::class, 'login']);
            }

            if (Features::hasPhoneOtpFeatures()) {
                Route::post('otp/send', [OtpController::class, 'send']);
                Route::post('otp/verify', [OtpController::class, 'verify']);
            }
        });

        Route::middleware('throttle:5,1')->get('settings', [SettingsController::class, 'index']);

        // To require email verification on protected routes, add the 'verified'
        // middleware: Route::middleware(['auth:sanctum', 'verified'])->group(...)
        // Your User model must implement MustVerifyEmail.
        Route::middleware('auth:sanctum')->group(function (): void {
            Route::prefix('auth')->group(function (): void {
                Route::post('logout', [AuthController::class, 'logout']);
                Route::get('user', [AuthController::class, 'user']);
            });

            if (Features::enabled(Features::teams())) {
                Route::get('teams', [TeamController::class, 'index']);
                Route::post('teams', [TeamController::class, 'store']);
                Route::get('teams/{team}', [TeamController::class, 'show']);
                Route::put('teams/{team}', [TeamController::class, 'update']);
                Route::delete('teams/{team}', [TeamController::class, 'destroy']);

                Route::prefix('teams/{team}')->group(function (): void {
                    Route::get('invitations', [TeamInvitationController::class, 'index']);
                    Route::post('invitations', [TeamInvitationController::class, 'store']);
                    Route::delete('invitations/{invitation}', [TeamInvitationController::class, 'destroy']);

                    if (Features::enabled(Features::profilePhotos())) {
                        Route::post('profile-photo', [TeamPhotoController::class, 'update']);
                        Route::delete('profile-photo', [TeamPhotoController::class, 'delete']);
                    }

                    Route::get('members', [TeamMemberController::class, 'index']);
                    Route::put('members/{user}', [TeamMemberController::class, 'update']);
                    Route::delete('members/{user}', [TeamMemberController::class, 'destroy']);
                    Route::delete('leave', [TeamMemberController::class, 'leave']);
                });

                Route::post('invitations/{token}/accept', [TeamInvitationController::class, 'accept']);
                Route::put('user/current-team', [AuthController::class, 'switchTeam']);
            }

            Route::prefix('user')->group(function (): void {
                Route::put('profile', [ProfileController::class, 'update']);
                Route::put('password', [ProfileController::class, 'updatePassword']);
                Route::match(['post', 'delete'], '/', [ProfileController::class, 'destroy']);

                if (Features::enabled(Features::profilePhotos())) {
                    Route::post('profile-photo', [ProfilePhotoController::class, 'update']);
                    Route::delete('profile-photo', [ProfilePhotoController::class, 'delete']);
                }
            });

            if (Features::enabled(Features::sessions())) {
                Route::prefix('sessions')->group(function (): void {
                    Route::get('/', [SessionController::class, 'index']);
                    Route::delete('/other', [SessionController::class, 'destroyOther']);
                    Route::delete('/{token}', [SessionController::class, 'destroy']);
                });
            }

            if (Features::enabled(Features::notifications())) {
                Route::prefix('notifications')->group(function (): void {
                    Route::get('/', [NotificationController::class, 'index']);
                    Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
                    Route::post('/{id}/read', [NotificationController::class, 'markAsRead']);
                    Route::post('/read-all', [NotificationController::class, 'markAllAsRead']);
                    Route::delete('/{id}', [NotificationController::class, 'destroy']);
                });

                Route::prefix('notification-preferences')->group(function (): void {
                    Route::get('/', [NotificationPreferenceController::class, 'show']);
                    Route::put('/', [NotificationPreferenceController::class, 'update']);
                });
            }

            if (Features::enabled(Features::twoFactorAuthentication())) {
                Route::post('two-factor-authentication', [TwoFactorAuthenticationController::class, 'store']);
                Route::post('two-factor-authentication/confirm', [TwoFactorAuthenticationController::class, 'confirm']);
                Route::delete('two-factor-authentication', [TwoFactorAuthenticationController::class, 'destroy']);

                Route::get('two-factor-recovery-codes', [TwoFactorRecoveryCodeController::class, 'index']);
                Route::post('two-factor-recovery-codes', [TwoFactorRecoveryCodeController::class, 'store']);
            }
        });

        if (Features::hasEmailVerificationFeatures()) {
            // Public route — signed URL acts as authentication.
            Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
                ->middleware('signed')
                ->name('verification.verify');

            // Protected route — requires Sanctum authentication and is rate-limited.
            Route::middleware(['auth:sanctum', 'throttle:6,1'])->group(function (): void {
                Route::post('email/verification-notification', [EmailVerificationController::class, 'sendVerificationNotification'])
                    ->name('verification.send');
            });
        }
    });
