<?php

/**
 * Magic Starter API route definitions.
 *
 * Registers authentication, team management, profile, session, notification,
 * and social login routes conditionally based on enabled features. All routes
 * respect the configured route prefix from `config('magic-starter.route_prefix')`.
 */

use FlutterSdk\MagicStarter\Contracts\ReportsUsage;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Http\Controllers\AuthController;
use FlutterSdk\MagicStarter\Http\Controllers\BillingController;
use FlutterSdk\MagicStarter\Http\Controllers\EmailVerificationController;
use FlutterSdk\MagicStarter\Http\Controllers\GuestAuthController;
use FlutterSdk\MagicStarter\Http\Controllers\NewsletterController;
use FlutterSdk\MagicStarter\Http\Controllers\NotificationController;
use FlutterSdk\MagicStarter\Http\Controllers\NotificationPreferenceController;
use FlutterSdk\MagicStarter\Http\Controllers\OtpController;
use FlutterSdk\MagicStarter\Http\Controllers\PasswordResetController;
use FlutterSdk\MagicStarter\Http\Controllers\ProfileController;
use FlutterSdk\MagicStarter\Http\Controllers\ProfilePhotoController;
use FlutterSdk\MagicStarter\Http\Controllers\PushTestController;
use FlutterSdk\MagicStarter\Http\Controllers\SessionController;
use FlutterSdk\MagicStarter\Http\Controllers\SettingsController;
use FlutterSdk\MagicStarter\Http\Controllers\TeamController;
use FlutterSdk\MagicStarter\Http\Controllers\TeamInvitationController;
use FlutterSdk\MagicStarter\Http\Controllers\TeamMemberController;
use FlutterSdk\MagicStarter\Http\Controllers\TeamPhotoController;
use FlutterSdk\MagicStarter\Http\Controllers\TimezoneController;
use FlutterSdk\MagicStarter\Http\Controllers\TwoFactorAuthenticationController;
use FlutterSdk\MagicStarter\Http\Controllers\TwoFactorChallengeController;
use FlutterSdk\MagicStarter\Http\Controllers\TwoFactorRecoveryCodeController;
use Illuminate\Support\Facades\Route;

// Every route below carries the host's own middleware, and the default is none.
//
// These routes are loaded by the package's service provider rather than from
// the host's `routes/api.php`, so they join no middleware group on their own:
// Laravel's `api` group, and anything the host added beside it, simply never
// runs on them.
//
// That is invisible until something in a group is load-bearing. It was found on
// a host whose `SetApiLocale` middleware resolves the caller's language from
// their stored `users.locale`: it ran on every route the host wrote and on none
// of this package's, so a Turkish account read a Turkish interface and English
// refusals from exactly the screens this package owns.
//
// The default stays EMPTY rather than `['api']`. Every route here already
// declares the throttle it wants by name, and a host's `api` group usually
// carries `throttle:api` as well, so defaulting into it would silently halve
// the rate limit a prior release granted. The host names what it wants.
//
// Below only. The vendor webhook routes load from `webhooks.php` under their
// own gate and inherit nothing from here, because a vendor calls them rather
// than a user: there is nobody for a locale resolver or a tenant scope to
// resolve, and an auth middleware would reject the call outright.
Route::prefix((string) config('magic-starter.route_prefix', ''))
    ->middleware((array) config('magic-starter.route_middleware', []))
    ->group(function (): void {
        Route::prefix('auth')->middleware(['throttle:magic-starter-auth-login'])->group(function (): void {
            Route::post('login', [AuthController::class, 'login']);
        });

        Route::prefix('auth')->middleware(['throttle:magic-starter-auth-register'])->group(function (): void {
            Route::post('register', [AuthController::class, 'register']);
        });

        Route::prefix('auth')->middleware(['throttle:magic-starter-auth-social'])->group(function (): void {
            Route::post('social/{provider}', [AuthController::class, 'socialLogin']);
        });

        Route::prefix('auth')->middleware(['throttle:magic-starter-auth-password-reset'])->group(function (): void {
            Route::post('forgot-password', [PasswordResetController::class, 'sendResetLinkEmail']);
            Route::post('reset-password', [PasswordResetController::class, 'reset']);
        });

        Route::prefix('auth')->middleware(['throttle:magic-starter-2fa-challenge'])->group(function (): void {
            if (Features::enabled(Features::twoFactorAuthentication())) {
                Route::post('two-factor-challenge', [TwoFactorChallengeController::class, 'store']);
            }
        });

        Route::prefix('auth')->middleware(['throttle:magic-starter-guest-auth'])->group(function (): void {
            if (Features::hasGuestAuthFeatures()) {
                Route::post('guest', [GuestAuthController::class, 'login']);
            }
        });

        Route::prefix('auth')->middleware(['throttle:magic-starter-otp'])->group(function (): void {
            if (Features::hasPhoneOtpFeatures()) {
                Route::post('otp/send', [OtpController::class, 'send']);
                Route::post('otp/verify', [OtpController::class, 'verify']);
            }
        });

        Route::middleware(['throttle:magic-starter-settings'])->get('settings', [SettingsController::class, 'index']);

        if (Features::hasTimezoneFeatures()) {
            Route::get('timezones', [TimezoneController::class, 'index']);
        }

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

                    // A push the caller sends to their OWN devices, so a person
                    // can find out whether push reaches their phone before an
                    // incident rather than during one. It takes no recipient:
                    // the target comes from the session.
                    //
                    // The limiter is REQUIRED rather than defensive. Nothing in
                    // this group inherits a throttle, and an application that
                    // registers these routes under an api prefix of its own does
                    // not necessarily call `throttleApi()` there either, so
                    // without this the one endpoint here that costs money on
                    // every call would be the one endpoint with no bound.
                    Route::post('/push-test', [PushTestController::class, 'store'])
                        ->middleware('throttle:magic-starter-push-test');
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

                Route::post('two-factor-recovery-codes/show', [TwoFactorRecoveryCodeController::class, 'index']);
                Route::post('two-factor-recovery-codes', [TwoFactorRecoveryCodeController::class, 'store']);
            }

            if (Features::hasNewsletterSubscriptionFeatures()) {
                Route::get('user/newsletter', [NewsletterController::class, 'show']);
                Route::put('user/newsletter', [NewsletterController::class, 'update']);
            }

            if (Features::hasBillingFeatures()) {
                Route::get('billing', [BillingController::class, 'show']);
                Route::get('billing/plans', [BillingController::class, 'plans']);
                Route::get('billing/invoices', [BillingController::class, 'invoices']);
                Route::get('billing/payment-method', [BillingController::class, 'paymentMethod']);
                // Asked by the client before it offers a STORE purchase: one
                // store account can fund only one subject, so a second purchase
                // would transfer the subscription rather than add one.
                Route::get('billing/store-funded-team', [BillingController::class, 'storeFundedTeam']);
                Route::get('billing/portal', [BillingController::class, 'portal']);

                // Registered ONLY when a consumer has bound the usage contract,
                // because this package cannot count what it does not know. An
                // unbound application therefore 404s here, which is an honest
                // "not wired yet"; a bound-by-default empty map would read to
                // every cap the consumer gates on it as "you have used nothing"
                // and open all of them. See the ReportsUsage docblock.
                if (app()->bound(ReportsUsage::class)) {
                    Route::get('billing/usage', [BillingController::class, 'usage']);
                }

                // The three card-rail WRITES. Registered beside the reads and
                // gated the same way, because what separates them is not the
                // route file: they are the OWNER's while the reads are open to
                // any member, and that gate lives on the policy the controller
                // asks rather than on a middleware here. They act on a tier the
                // adopter publishes, so an application that has published no
                // catalogue serves these routes and refuses every call to them,
                // which is a different fact from the route being absent.
                Route::post('billing/checkout', [BillingController::class, 'checkout']);
                Route::post('billing/swap', [BillingController::class, 'swap']);
                Route::post('billing/cancel', [BillingController::class, 'cancel']);
            }
        });

        if (Features::hasEmailVerificationFeatures()) {
            // Public route — signed URL acts as authentication.
            Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
                ->middleware('signed')
                ->name('verification.verify');

            // Protected route — requires Sanctum authentication and is rate-limited.
            Route::middleware(['auth:sanctum', 'throttle:magic-starter-email-verification'])->group(function (): void {
                Route::post('email/verification-notification', [EmailVerificationController::class, 'sendVerificationNotification'])
                    ->name('verification.send');
            });
        }
    });
