<?php

namespace FlutterSdk\MagicStarter;

use FlutterSdk\MagicStarter\Console\InstallCommand;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;
use Laravel\Sanctum\Sanctum;
use RuntimeException;

/**
 * Service provider for the Magic Starter package.
 *
 * Handles configuration merging, action contract binding,
 * Sanctum token model setup, password reset URL, and
 * personal team creation on registration.
 */
class MagicStarterServiceProvider extends ServiceProvider
{
    /**
     * Register package services, merge configuration, and bind action contracts.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/magic-starter.php',
            'magic-starter',
        );

        // Prevent Sanctum from auto-loading its own migrations — we provide a custom version.
        if (class_exists(Sanctum::class) && method_exists(Sanctum::class, 'ignoreMigrations')) {
            Sanctum::ignoreMigrations();
        }

        // Bind all action contracts to package default implementations.
        // Consuming apps can override any of these with singleton() in their AppServiceProvider.
        $this->app->bind(Contracts\CreatesUsers::class, Actions\CreateUser::class);
        $this->app->bind(Contracts\UpdatesUserProfiles::class, Actions\UpdateUserProfile::class);
        $this->app->bind(Contracts\UpdatesUserPasswords::class, Actions\UpdateUserPassword::class);
        $this->app->bind(Contracts\DeletesUsers::class, Actions\DeleteUser::class);
        $this->app->bind(Contracts\CreatesTeams::class, Actions\CreateTeam::class);
        $this->app->bind(Contracts\UpdatesTeams::class, Actions\UpdateTeam::class);
        // Bound to the store-guarded action rather than the plain one. The
        // guard is unconditional like every binding above it: it reads
        // `plan_provider`/`plan_status` through Eloquent's own attribute
        // access, which answers null on a table that never got the
        // provenance columns (billing feature off, or `billing.billable`
        // pointed elsewhere), so the extra check is a safe no-op there and
        // a real one only where the schema and the config both say it should be.
        $this->app->bind(Contracts\DeletesTeams::class, Actions\StoreSubscriptionGuardedDeleteTeam::class);
        $this->app->bind(Contracts\AddsTeamMembers::class, Actions\AddTeamMember::class);
        $this->app->bind(Contracts\RemovesTeamMembers::class, Actions\RemoveTeamMember::class);
        $this->app->bind(Contracts\InvitesTeamMembers::class, Actions\InviteTeamMember::class);
        $this->app->bind(Contracts\UpdatesTeamMemberRoles::class, Actions\UpdateTeamMemberRole::class);
        $this->app->bind(Contracts\CreatesGuestUsers::class, Actions\CreateGuestUser::class);
        $this->app->bind(Contracts\SendsOtpCodes::class, Actions\LogOtpProvider::class);
        $this->app->bind(Contracts\VerifiesOtpCodes::class, Actions\CacheOtpVerifier::class);
        $this->app->singleton(Support\TwoFactorAuthenticationProvider::class);
        $this->app->bind(Contracts\EnablesTwoFactorAuthentication::class, Actions\EnableTwoFactorAuthentication::class);
        $this->app->bind(Contracts\ConfirmsTwoFactorAuthentication::class, Actions\ConfirmTwoFactorAuthentication::class);
        $this->app->bind(Contracts\DisablesTwoFactorAuthentication::class, Actions\DisableTwoFactorAuthentication::class);
        $this->app->bind(Contracts\GeneratesNewRecoveryCodes::class, Actions\GenerateNewRecoveryCodes::class);

        // Bound unconditionally, like every binding above it: the billing
        // feature gates the SCHEMA (see InstallCommand), not the arbitration
        // contract. A consumer has to be able to bind its own entitlement
        // writer before it decides to switch billing on.
        $this->app->bind(Contracts\WritesEntitlement::class, Actions\WriteEntitlement::class);

        // Cashier is wired HERE and never in boot(), because boot() is already
        // too late: CashierServiceProvider::boot() registers the stripe/webhook
        // route under config('cashier.path') the moment it runs, and every
        // provider's register() runs before any provider's boot(), so this is
        // the only phase that can still refuse those routes (maintainer
        // confirmed, laravel/cashier#1739). The package serves the webhook
        // itself, under a key of its own, so Cashier's copy would be a second
        // endpoint on the same events.
        //
        // The gate is the billing feature and not the mere presence of Cashier,
        // because the require is unconditional (see the Billing block in
        // config/magic-starter.php): an adopter who installs this package and
        // drives Cashier directly keeps Cashier's own routes untouched.
        //
        // The useXModel() family belongs in this phase for the same reason, and
        // the maintainer's answer on #1739 covers both: Cashier resolves these
        // statics from its own boot() onwards, so register() is the last phase
        // that still lands.
        //
        // The two subscription calls hand over a CLASS NAME the package owns
        // outright, and neither instantiates a model nor reads the billable
        // subject. useCustomerModel() is the one that DOES resolve the billable,
        // which is why the guard runs beside it here rather than only in boot():
        // by boot() Cashier has already been handed a model, so a refusal there
        // would come after the wrong one was accepted.
        //
        // Wiring it is not optional now that the Stripe rail ships. Cashier's
        // findBillable() reads this static, and left at its 'App\Models\User'
        // default it searches the users table on a team-billing application and
        // matches nobody: every delivery resolves no customer, returns 200, and
        // writes no entitlement.
        if (Features::hasBillingFeatures()) {
            Cashier::ignoreRoutes();

            Cashier::useSubscriptionModel(Models\Subscription::class);
            Cashier::useSubscriptionItemModel(Models\SubscriptionItem::class);

            $this->guardBillableSubject();

            Cashier::useCustomerModel(MagicStarter::billableModel());
        }

        // OneSignal SDK client singleton (resolved lazily, only when injected).
        $this->app->singleton(\onesignal\client\api\DefaultApi::class, function (): \onesignal\client\api\DefaultApi {
            $restApiKey = config('magic-starter.onesignal.rest_api_key')
                ?? config('services.onesignal.rest_api_key');

            if ($restApiKey === null || $restApiKey === '') {
                throw new \RuntimeException(
                    'OneSignal REST API key missing. Set ONESIGNAL_REST_API_KEY, magic-starter.onesignal.rest_api_key, or services.onesignal.rest_api_key.',
                );
            }

            $config = \onesignal\client\Configuration::getDefaultConfiguration()
                ->setRestApiKeyToken((string) $restApiKey);

            return new \onesignal\client\api\DefaultApi(new \GuzzleHttp\Client, $config);
        });
    }

    /**
     * Bootstrap package routes, commands, Sanctum, password reset, and event listeners.
     */
    public function boot(): void
    {
        // 0. Refuse a billable subject the rest of this boot cannot serve.
        $this->guardBillableSubject();

        // 1. Register named rate limiters for package endpoints.
        $this->configureRateLimiting();

        // 2. Sanctum personal access token model binding.
        if (class_exists(Sanctum::class)) {
            Sanctum::usePersonalAccessTokenModel(
                Models\PersonalAccessToken::class,
            );
        }

        // 2. Password reset URL using package config with app config fallback.
        ResetPassword::createUrlUsing(
            function (object $notifiable, string $token) {
                $frontendUrl = config(
                    'magic-starter.frontend_url',
                    config('app.frontend_url'),
                );

                return "{$frontendUrl}/auth/reset-password?token={$token}&email="
                    . $notifiable->getEmailForPasswordReset();
            },
        );

        // 3. Auto-register team policy and personal team creation when teams feature is enabled.
        if (Features::hasTeamFeatures()) {
            Gate::policy(MagicStarter::teamModel(), Policies\TeamPolicy::class);

            Event::listen(
                Registered::class,
                Listeners\CreatePersonalTeamListener::class,
            );
        }

        // 3.4. Register the billing WRITE gate when the billing feature is on.
        //
        // A NAMED ABILITY and never `Gate::policy(MagicStarter::billableModel(),
        // ...)`, and the difference is load-bearing rather than stylistic. The
        // policy map is keyed by model class and `Gate::policy()` is a plain
        // assignment, so under `billing.billable = 'team'` the billable model IS
        // the team model and a second registration would REPLACE the entry made
        // three lines above: either team member management, invitations and
        // deletion go unguarded, or every billing write is refused. Nothing on
        // the billing side could observe it, because the billing ability would
        // keep answering correctly the whole time.
        //
        // Gated on the feature and defined AFTER the teams block on purpose: the
        // coexistence this comment defends is only real when both are on, so the
        // two registrations sit where a reader sees them together.
        if (Features::hasBillingFeatures()) {
            Gate::define('manageBilling', [Policies\BillingPolicy::class, 'manage']);

            // 3.4b. Say ONCE that a configured store rail has no signing secret.
            //
            // Boot rather than per request, and a log line rather than a throw,
            // because the proportion matters: the rail cannot work, and nothing
            // else about the application is broken by it. A throw here would take
            // artisan down with the web surface (see guardBillableSubject() for
            // what that costs), and a per-request warning would say the same
            // thing once per delivery of an endpoint that is not even
            // registered, since the route file withholds it on this exact
            // condition.
            $this->warnAboutHalfConfiguredStoreRail();
        }

        // 3.5. Auto-gate notification channels when notification feature is enabled.
        if (Features::hasNotificationFeatures()) {
            Event::listen(
                NotificationSending::class,
                Listeners\GateNotificationChannels::class,
            );
        }

        // 3.6. Register OneSignal push + SMS channels when onesignal feature is enabled.
        if (Features::hasOnesignalFeatures()) {
            $channelManager = $this->app->make(\Illuminate\Notifications\ChannelManager::class);

            $channelManager->extend('onesignal', fn ($app) => $app->make(
                \FlutterSdk\MagicStarter\Notifications\Channels\OneSignalChannel::class,
            ));

            // SMS rides the same channel via a distinct driver + builder (toSms),
            // so push and SMS stay independently toggleable. Adding sms to a
            // notification's channel set stays an opt-in consumer decision, and
            // so does the subscription: the notification's toSms() calls
            // OneSignalSubscriptions::ensureSmsSubscription() before it targets
            // the sms channel (this driver only sends).
            $channelManager->extend('onesignal-sms', fn ($app) => $app->make(
                \FlutterSdk\MagicStarter\Notifications\Channels\OneSignalChannel::class,
                ['builderMethod' => 'toSms'],
            ));

            NotificationPreferenceRegistry::channelAliases([
                'push' => 'onesignal',
                'sms' => 'onesignal-sms',
            ]);
        }

        // 3.7. Register package translations.
        $this->loadTranslationsFrom(__DIR__ . '/../lang', 'magic-starter');

        // 4. Load package routes unless explicitly ignored.
        if (! MagicStarter::shouldIgnoreRoutes()) {
            $this->loadRoutesFrom(__DIR__ . '/routes/api.php');

            // 4b. The vendor webhook routes, loaded from a SEPARATE file with a
            // separate gate. Everything in api.php sits under
            // `magic-starter.route_prefix`, which an adopter may move with a
            // deploy; a webhook URL is registered in a vendor dashboard and
            // cannot move at all, so it must not inherit that prefix. The file
            // groups each rail under its own key and carries the reasoning.
            if (Features::hasBillingFeatures()) {
                $this->loadRoutesFrom(__DIR__ . '/routes/webhooks.php');
            }
        }

        // 5. Console-only: publish config, migrations, and stubs.
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/magic-starter.php' => config_path('magic-starter.php'),
            ], 'magic-starter-config');

            $this->publishesMigrations([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'magic-starter-migrations');

            $this->publishes([
                __DIR__ . '/../stubs/actions' => app_path('Actions/MagicStarter'),
            ], 'magic-starter-stubs');

            $this->publishes([
                __DIR__ . '/../stubs/policies' => app_path('Policies'),
            ], 'magic-starter-policies');
            $this->publishes([
                __DIR__ . '/../stubs/models/Team.php' => app_path('Models/Team.php'),
                __DIR__ . '/../stubs/models/TeamUser.php' => app_path('Models/TeamUser.php'),
                __DIR__ . '/../stubs/models/TeamInvitation.php' => app_path('Models/TeamInvitation.php'),
            ], 'magic-starter-models');
            $this->publishes([
                __DIR__ . '/../lang' => $this->app->langPath('vendor/magic-starter'),
            ], 'magic-starter-lang');
            $this->publishes([
                __DIR__ . '/../stubs/models/User.php' => app_path('Models/User.php'),
            ], 'magic-starter-user-model');
            $this->publishes([
                __DIR__ . '/../stubs/factories/UserFactory.php' => database_path('factories/UserFactory.php'),
            ], 'magic-starter-factories');

        }
    }

    /**
     * Refuse to boot when the declared billable subject cannot exist.
     *
     * `magic-starter.billing.billable` names WHAT this application bills, and
     * `'team'` is only answerable while the teams feature is on: with teams off
     * this provider registers no personal-team listener (step 3 below), so there
     * is no team at all, not even a personal one, and every entitlement write
     * would have nothing to land on. Saying so here costs one boot; saying it at
     * the first payment costs a customer their tier.
     *
     * CALLED FROM BOTH PHASES, and neither call is redundant. Config is loaded
     * before any provider registers, so both phases can read the token, and what
     * decides where the guard belongs is ORDER OF USE. In `boot()` the first use
     * is the teams gate's `Gate::policy` further down this method, so a refusal
     * at the top of it is strictly earlier. In `register()` the first use is
     * `Cashier::useCustomerModel(MagicStarter::billableModel())`, which Cashier
     * requires in that phase, and `boot()` would be too late to stop the wrong
     * model being handed over. The `register()` call sits inside the billing
     * gate with the reader it protects; this one covers every application that
     * has teams and no billing at all.
     *
     * The method is idempotent by construction: it reads config and either
     * throws or returns, so calling it twice costs two config reads.
     *
     * @throws RuntimeException
     */
    private function guardBillableSubject(): void
    {
        $subject = config('magic-starter.billing.billable');

        // The token is validated EXHAUSTIVELY here and not only on the 'team'
        // branch below, because an unrecognised value is the failure this guard
        // exists to make early. Left to the lazy path it boots cleanly and throws
        // at whatever first resolves the billable, which from the moment a rail
        // ships is a payment webhook: money taken, entitlement unwritten, and a
        // typo like 'teams' as the cause. The migration was rescued from exactly
        // this shape of deferred failure, so the guard must not reintroduce it.
        // ABSENCE is exempt, not NULL, and the distinction is the whole point.
        // `mergeConfigFrom` is a shallow merge, so a consumer who published a
        // `billing` block before this key existed has no key at all, and
        // `billableModel()`'s `'user'` default is what serves them; refusing to
        // boot over that would break every such adopter on upgrade.
        //
        // But an EXPLICIT null is a present key, and `config()`'s default only
        // fires for a missing one (`Arr::get` treats a null value as existing),
        // so `billableModel()` throws on it. Exempting null here rather than
        // absence would therefore boot green and defer that throw to whatever
        // first resolves the billable, which is the exact deferral this guard
        // exists to remove. `has()` is what tells the two apart.
        if (config()->has('magic-starter.billing.billable')
            && ! in_array($subject, ['user', 'team'], true)
        ) {
            throw new RuntimeException(sprintf(
                '[magic-starter.billing.billable] is [%s], which is not a billable subject. '
                . "Set it to 'user' or to 'team'.",
                is_string($subject) ? $subject : get_debug_type($subject),
            ));
        }

        if ($subject !== 'team') {
            return;
        }

        if (Features::hasTeamFeatures()) {
            return;
        }

        throw new RuntimeException(sprintf(
            "[magic-starter.billing.billable] is set to 'team', which requires the [%s] feature: "
            . 'with teams off no team exists to hold an entitlement, so nothing could be billed. '
            . "Enable it in [magic-starter.features], or bill the 'user' subject instead. "
            . 'This throws from boot, so artisan is down while it holds: if the value came from a '
            . 'cached config, delete bootstrap/cache/config.php by hand, because config:clear '
            . 'cannot run either.',
            Features::teams(),
        ));
    }

    /**
     * Log, once per boot, that the store rail is configured without the secret
     * that authenticates its deliveries.
     *
     * ERROR level rather than warning, and the distinction is the point: a
     * warning is a decision not to act on something a rail said, and this is a
     * rail that cannot say anything at all. The whole store surface is inert
     * while it holds, and nothing else in the application will ever mention it.
     *
     * The `reason` is DISTINCT from the per-request `unconfigured_secret` the
     * controller refuses a delivery with, deliberately. The two describe
     * different failures: this one is a rail that was never registered, that one
     * is a registered endpoint refusing a caller it cannot identify, which is
     * what an adopter who configured nothing but the route still gets. A shared
     * reason would make a boot-time misconfiguration indistinguishable from a
     * forged delivery in the log.
     *
     * {@see StoreRailConfiguration::isMisconfigured()} is the one predicate
     * behind this line, the route file's registration and the installer's
     * refusal, so the three cannot drift into disagreeing about what a
     * configured rail is.
     */
    private function warnAboutHalfConfiguredStoreRail(): void
    {
        if (! Support\StoreRailConfiguration::isMisconfigured()) {
            return;
        }

        Log::error(
            'The RevenueCat store rail is configured without a webhook signing secret, so its endpoint is '
            . 'not registered and no store purchase can reach this application. Set REVENUECAT_WEBHOOK_SECRET '
            . '(or [magic-starter.billing.revenuecat.webhook_secret]) to the secret from the RevenueCat '
            . 'dashboard webhook integration.',
            [
                'reason' => 'store_rail_without_webhook_secret',
                'path' => config('magic-starter.billing.revenuecat.path', 'webhooks/revenuecat'),
            ],
        );
    }

    /**
     * Configure named rate limiters for Magic Starter endpoints.
     */
    private function configureRateLimiting(): void
    {
        // Authentication endpoints
        RateLimiter::for('magic-starter-auth-login', function ($request) {
            return Limit::perMinute(5)->by($request->ip() . '|' . $request->input('email', ''));
        });

        RateLimiter::for('magic-starter-auth-register', function ($request) {
            return Limit::perMinute(3)->by($request->ip());
        });

        RateLimiter::for('magic-starter-auth-social', function ($request) {
            return Limit::perMinute(10)->by($request->ip() . '|' . $request->route('provider', ''));
        });

        RateLimiter::for('magic-starter-auth-password-reset', function ($request) {
            return Limit::perMinute(3)->by($request->ip() . '|' . $request->input('email', ''));
        });

        // 2FA challenge
        RateLimiter::for('magic-starter-2fa-challenge', function ($request) {
            return Limit::perMinute(5)->by($request->ip() . '|' . $request->input('two_factor_token', ''));
        });

        // Guest auth
        RateLimiter::for('magic-starter-guest-auth', function ($request) {
            return Limit::perMinute(10)->by($request->ip() . '|' . $request->input('device_id', ''));
        });

        // OTP endpoints - combined limiter for both send and verify
        RateLimiter::for('magic-starter-otp', function ($request) {
            $key = $request->ip();
            if ($request->is('*otp/send*')) {
                return Limit::perMinute(2)->by($key . '|send|' . $request->input('phone', ''));
            }

            return Limit::perMinute(5)->by($key . '|verify|' . $request->input('phone', ''));
        });

        // Public settings
        RateLimiter::for('magic-starter-settings', function ($request) {
            return Limit::perMinute(30)->by($request->ip());
        });

        // Email verification
        RateLimiter::for('magic-starter-email-verification', function ($request) {
            return Limit::perMinute(1)->by($request->user()?->id ?: $request->ip());
        });
    }
}
