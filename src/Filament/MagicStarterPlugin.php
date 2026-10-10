<?php

namespace FlutterSdk\MagicStarter\Filament;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Models\Contracts\FilamentUser;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Filament\Auth\TwoFactorAuthentication;
use FlutterSdk\MagicStarter\Filament\Ops\OpsAuthorization;
use FlutterSdk\MagicStarter\Filament\Ops\TelescopeRedaction;
use FlutterSdk\MagicStarter\Filament\Pages\Dashboard;
use FlutterSdk\MagicStarter\Filament\Widgets\HorizonStatusWidget;
use FlutterSdk\MagicStarter\Filament\Widgets\PulseSummaryWidget;
use FlutterSdk\MagicStarter\Filament\Widgets\StarterStatsWidget;
use FlutterSdk\MagicStarter\MagicStarter;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Foundation\Application;
use Laravel\Horizon\Horizon;
use Laravel\Pulse\Pulse;
use Laravel\Telescope\Telescope;
use LogicException;

/**
 * The Filament plugin that mounts the starter's admin resources on a panel.
 *
 * Filament calls `register()` the moment the plugin is passed to `->plugin()`,
 * so every option has to be set on the instance BEFORE that call:
 *
 *     $panel->plugin(MagicStarterPlugin::make()->withoutResources(['teams']));
 *
 * Panel access belongs to the user model through {@see Concerns\AuthorizesAdminPanel},
 * which only answers for a panel carrying this plugin.
 */
class MagicStarterPlugin implements Plugin
{
    public const ID = 'magic-starter';

    /**
     * Resource classes by key, replacing the defaults of the same key.
     *
     * @var array<string, string>
     */
    protected array $resources = [];

    /**
     * Resource keys that are never registered, whatever their feature gate says.
     *
     * @var list<string>
     */
    protected array $withoutResources = [];

    protected ?string $navigationGroup = null;

    protected ?Closure $authorizeUsing = null;

    protected ?Closure $authorizeBillingUsing = null;

    protected bool $horizon = false;

    protected bool $pulse = false;

    protected bool $telescope = false;

    protected ?string $sentryUrl = null;

    public static function make(): static
    {
        return app(static::class);
    }

    /**
     * The plugin instance registered on the current panel.
     *
     * @throws LogicException when the current panel does not carry the plugin
     */
    public static function get(): static
    {
        /** @var static $plugin */
        $plugin = filament(static::ID);

        return $plugin;
    }

    public function getId(): string
    {
        return static::ID;
    }

    /**
     * Replace the default user resource.
     */
    public function userResource(string $class): static
    {
        return $this->resource('users', $class);
    }

    /**
     * Replace the default team resource. It stays behind the teams feature gate.
     */
    public function teamResource(string $class): static
    {
        return $this->resource('teams', $class);
    }

    /**
     * Register a resource class under a key.
     *
     * A default key (`users`, `teams`, `subscriptions`, `billing_events`,
     * `webhook_deliveries`, `newsletter_subscribers`, `audits`) keeps its
     * feature gate; any other key is registered unconditionally.
     */
    public function resource(string $key, string $class): static
    {
        $this->resources[$key] = $class;

        return $this;
    }

    /**
     * Never register the resources under these keys.
     *
     * @param  list<string>  $keys
     */
    public function withoutResources(array $keys): static
    {
        $this->withoutResources = [
            ...$this->withoutResources,
            ...$keys,
        ];

        return $this;
    }

    public function navigationGroup(?string $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    /**
     * The navigation group the package's resources sit under.
     */
    public function getNavigationGroup(): string
    {
        return $this->navigationGroup ?? (string) __('magic-starter::admin.navigation_group');
    }

    /**
     * Replace the allowlist gate with the given callback.
     *
     * The callback receives the user and the panel and must return `true` to
     * admit; any other value denies.
     */
    public function authorizeUsing(?Closure $callback): static
    {
        $this->authorizeUsing = $callback;

        return $this;
    }

    public function getAuthorizeUsing(): ?Closure
    {
        return $this->authorizeUsing;
    }

    /**
     * Replace the `magic-starter.admin.billing_emails` list that decides who may
     * run the billing actions with the given callback.
     *
     * The callback receives the panel user and the panel and must return `true`
     * to allow; any other value denies. It narrows an admitted admin further and
     * never opens the panel itself.
     */
    public function authorizeBillingUsing(?Closure $callback): static
    {
        $this->authorizeBillingUsing = $callback;

        return $this;
    }

    public function getAuthorizeBillingUsing(): ?Closure
    {
        return $this->authorizeBillingUsing;
    }

    /**
     * Put Horizon behind the panel gate, link it under "Operations" and mount
     * its status widget. Horizon keeps its own routes: mount them on the admin
     * host with `HORIZON_DOMAIN` and `HORIZON_PATH`.
     *
     * @throws LogicException when laravel/horizon is not installed
     */
    public function horizon(): static
    {
        $this->ensureInstalled(Horizon::class, 'laravel/horizon');

        $this->horizon = true;

        return $this;
    }

    /**
     * Put Pulse behind the panel gate, link it under "Operations" and mount
     * its summary widget. Pulse keeps its own routes: mount them on the admin
     * host with `PULSE_DOMAIN` and `PULSE_PATH`.
     *
     * @throws LogicException when laravel/pulse is not installed
     */
    public function pulse(): static
    {
        $this->ensureInstalled(Pulse::class, 'laravel/pulse');

        $this->pulse = true;

        return $this;
    }

    /**
     * Put Telescope behind the panel gate, link it under "Operations", mask the
     * starter's secrets in its entries and, outside `local`, keep only the
     * batches worth reading. Telescope keeps its own routes: mount them on the
     * admin host with `TELESCOPE_DOMAIN` and `TELESCOPE_PATH`.
     *
     * @throws LogicException when laravel/telescope is not installed
     */
    public function telescope(): static
    {
        $this->ensureInstalled(Telescope::class, 'laravel/telescope');

        $this->telescope = true;

        return $this;
    }

    /**
     * Link an external Sentry project under "Operations"; null removes the link.
     */
    public function sentryUrl(?string $url): static
    {
        $this->sentryUrl = $url;

        return $this;
    }

    /**
     * Mount the enabled resources, the dashboard and its widget on the panel.
     *
     * @throws LogicException when the user model does not implement FilamentUser
     */
    public function register(Panel $panel): void
    {
        // 1. Without FilamentUser, Filament's Authenticate middleware admits every
        //    signed-in user when `app.env` is `local` and never consults the
        //    allowlist. Refuse to mount rather than open the panel.
        $userModel = MagicStarter::userModel();

        if (! is_subclass_of($userModel, FilamentUser::class)) {
            throw new LogicException(
                "The user model [{$userModel}] must implement " . FilamentUser::class
                . ' (use the AuthorizesAdminPanel trait) before the magic-starter plugin can be registered.',
            );
        }

        // 2. Resources, the dashboard and its headline widget.
        $panel->resources($this->enabledResources());

        $panel->pages([
            Dashboard::class,
        ]);

        $panel->widgets([
            StarterStatsWidget::class,
        ]);

        // 3. The starter's own second factor at login, unless the app already
        //    configured multi-factor authentication on this panel. Its own setup
        //    wins outright: `multiFactorAuthentication()` also resets the
        //    required flag, so adding to it would quietly undo that choice.
        if (! $panel->hasMultiFactorAuthentication()) {
            $panel->multiFactorAuthentication([TwoFactorAuthentication::make()]);
        }

        // 4. The operations tools the app opted into.
        $this->registerOperations($panel);
    }

    public function boot(Panel $panel): void {}

    /**
     * Link and summarise the enabled tools, then put them behind the panel gate.
     */
    protected function registerOperations(Panel $panel): void
    {
        // 1. Links and widgets.
        $panel->navigationItems($this->operationsNavigationItems());

        $panel->widgets([
            ...($this->horizon ? [HorizonStatusWidget::class] : []),
            ...($this->pulse ? [PulseSummaryWidget::class] : []),
        ]);

        if (! $this->horizon && ! $this->pulse && ! $this->telescope) {
            return;
        }

        // 2. Each tool's rule is a slot its last writer owns, and an app's own
        //    HorizonServiceProvider or published TelescopeServiceProvider writes
        //    it in boot. Writing after boot keeps the panel gate in force.
        $authorization = new OpsAuthorization($panel);

        app()->booted(function (Application $app) use ($authorization): void {
            if ($this->horizon) {
                $authorization->guardHorizon();
            }

            if ($this->pulse) {
                $authorization->guardPulse($app->make(Gate::class));
            }

            if ($this->telescope) {
                $this->configureTelescope($app, $authorization);
            }
        });
    }

    /**
     * Telescope's secrets are masked in every environment; outside `local` it
     * keeps only the batches worth reading.
     */
    protected function configureTelescope(Application $app, OpsAuthorization $authorization): void
    {
        $authorization->guardTelescope();

        TelescopeRedaction::hideSecrets();

        if (! $app->environment('local')) {
            TelescopeRedaction::keepNoteworthyBatches();
        }
    }

    /**
     * The "Operations" group: one link per enabled tool, by the route name the
     * tool registers, plus the optional Sentry link.
     *
     * @return list<NavigationItem>
     */
    protected function operationsNavigationItems(): array
    {
        $tools = [
            'horizon' => [$this->horizon, 'horizon.index', Heroicon::OutlinedQueueList],
            'pulse' => [$this->pulse, 'pulse', Heroicon::OutlinedChartBar],
            'telescope' => [$this->telescope, 'telescope', Heroicon::OutlinedMagnifyingGlass],
        ];

        $items = [];

        foreach ($tools as $key => [$enabled, $route, $icon]) {
            if (! $enabled) {
                continue;
            }

            $items[] = $this->operationsItem($key, $icon)
                ->url(static fn (): string => route($route));
        }

        if ($this->sentryUrl !== null) {
            $items[] = $this->operationsItem('sentry', Heroicon::OutlinedBugAnt)
                ->url($this->sentryUrl, shouldOpenInNewTab: true);
        }

        return $items;
    }

    /**
     * Labels are closures so they translate in the request's locale, not the
     * one the panel happened to be built in.
     */
    protected function operationsItem(string $key, Heroicon $icon): NavigationItem
    {
        return NavigationItem::make(static fn (): string => (string) __("magic-starter::admin_ops.navigation.{$key}"))
            ->group(static fn (): string => (string) __('magic-starter::admin_ops.navigation_group'))
            ->icon($icon);
    }

    /**
     * @throws LogicException when the tool's package is not installed
     */
    protected function ensureInstalled(string $class, string $package): void
    {
        if (! class_exists($class)) {
            throw new LogicException(
                "The magic-starter plugin cannot put {$package} behind the panel gate: "
                . "run `composer require {$package}` first.",
            );
        }
    }

    /**
     * The resource classes to mount: defaults merged with overrides, minus the
     * dropped keys, the disabled features and the classes that do not exist.
     *
     * @return list<class-string>
     */
    protected function enabledResources(): array
    {
        $resources = [
            'users' => 'FlutterSdk\\MagicStarter\\Filament\\Resources\\Users\\UserResource',
            'teams' => 'FlutterSdk\\MagicStarter\\Filament\\Resources\\Teams\\TeamResource',
            'subscriptions' => 'FlutterSdk\\MagicStarter\\Filament\\Resources\\Subscriptions\\SubscriptionResource',
            'billing_events' => 'FlutterSdk\\MagicStarter\\Filament\\Resources\\BillingEvents\\BillingEventResource',
            'webhook_deliveries' => 'FlutterSdk\\MagicStarter\\Filament\\Resources\\WebhookDeliveries\\'
                . 'WebhookDeliveryResource',
            'newsletter_subscribers' => 'FlutterSdk\\MagicStarter\\Filament\\Resources\\NewsletterSubscribers\\'
                . 'NewsletterSubscriberResource',
            'audits' => 'FlutterSdk\\MagicStarter\\Filament\\Resources\\Audits\\AuditResource',
            ...$this->resources,
        ];

        $enabled = [];

        foreach ($resources as $key => $class) {
            if (in_array($key, $this->withoutResources, true)) {
                continue;
            }

            if (! $this->isFeatureEnabled($key) || ! class_exists($class)) {
                continue;
            }

            $enabled[] = $class;
        }

        return $enabled;
    }

    /**
     * Whether the feature a resource key belongs to is enabled. Keys outside the
     * defaults carry no gate.
     */
    protected function isFeatureEnabled(string $key): bool
    {
        return match ($key) {
            'teams' => Features::hasTeamFeatures(),
            'subscriptions', 'billing_events', 'webhook_deliveries' => Features::hasBillingFeatures(),
            'newsletter_subscribers' => Features::hasNewsletterSubscriptionFeatures(),
            'audits' => Features::hasAuditFeatures(),
            default => true,
        };
    }
}
