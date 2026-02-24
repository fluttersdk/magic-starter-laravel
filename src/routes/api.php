<?php

/**
 * Magic Starter API route definitions.
 *
 * Registers authentication, team management, profile, session, and social login
 * routes conditionally based on enabled features. All routes respect the
 * configured route prefix from `config('magic-starter.route_prefix')`.
 */

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Http\Controllers\AuthController;
use FlutterSdk\MagicStarter\Http\Controllers\PasswordResetController;
use FlutterSdk\MagicStarter\Http\Controllers\ProfileController;
use FlutterSdk\MagicStarter\Http\Controllers\ProfilePhotoController;
use FlutterSdk\MagicStarter\Http\Controllers\SessionController;
use FlutterSdk\MagicStarter\Http\Controllers\TeamController;
use FlutterSdk\MagicStarter\Http\Controllers\TeamInvitationController;
use FlutterSdk\MagicStarter\Http\Controllers\TeamMemberController;
use Illuminate\Support\Facades\Route;

Route::prefix((string) config('magic-starter.route_prefix', ''))
    ->group(function (): void {
        Route::prefix('auth')->middleware('throttle:5,1')->group(function (): void {
            Route::post('register', [AuthController::class, 'register']);
            Route::post('login', [AuthController::class, 'login']);
            Route::post('social/{provider}', [AuthController::class, 'socialLogin']);

            Route::post('forgot-password', [PasswordResetController::class, 'sendResetLinkEmail']);
            Route::post('reset-password', [PasswordResetController::class, 'reset']);
        });

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::prefix('auth')->group(function (): void {
                Route::post('logout', [AuthController::class, 'logout']);
                Route::get('user', [AuthController::class, 'user']);
            });

            if (Features::enabled(Features::teams())) {
                Route::apiResource('teams', TeamController::class);

                Route::prefix('teams/{team}')->group(function (): void {
                    Route::get('invitations', [TeamInvitationController::class, 'index']);
                    Route::post('invitations', [TeamInvitationController::class, 'store']);
                    Route::delete('invitations/{invitation}', [TeamInvitationController::class, 'destroy']);

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
        });
    });
