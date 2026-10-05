<?php

namespace FlutterSdk\MagicStarter\Filament;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Models\Contracts\FilamentUser;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Widgets\Widget;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
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
     * A default key (`users`, `teams`, `subscriptions`, `newsletter_subscribers`,
     * `audits`) keeps its feature gate; any other key is registered unconditionally.
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
     * Mount the enabled resources, the dashboard and its widget on the panel.
     *
     * @throws LogicException when the user model does not implement FilamentUser
     */
    public function register(Panel $panel): void
    {
        // 1. Without FilamentUser, Filament's Authenticate middleware admits every
        //    signed-in user outside the `local` environment and never consults
        //    the allowlist. Refuse to mount rather than open the panel.
        $userModel = MagicStarter::userModel();

        if (! is_subclass_of($userModel, FilamentUser::class)) {
            throw new LogicException(
                "The user model [{$userModel}] must implement " . FilamentUser::class
                . ' (use the AuthorizesAdminPanel trait) before the magic-starter plugin can be registered.',
            );
        }

        // 2. Classes the later admin steps add are skipped until they exist.
        $panel->resources($this->enabledResources());

        $dashboard = 'FlutterSdk\\MagicStarter\\Filament\\Pages\\Dashboard';

        if (is_subclass_of($dashboard, Page::class)) {
            $panel->pages([
                $dashboard,
            ]);
        }

        $statsWidget = 'FlutterSdk\\MagicStarter\\Filament\\Widgets\\StarterStatsWidget';

        if (is_subclass_of($statsWidget, Widget::class)) {
            $panel->widgets([
                $statsWidget,
            ]);
        }
    }

    public function boot(Panel $panel): void {}

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
            'subscriptions' => Features::hasBillingFeatures(),
            'newsletter_subscribers' => Features::hasNewsletterSubscriptionFeatures(),
            'audits' => Features::hasAuditFeatures(),
            default => true,
        };
    }
}
