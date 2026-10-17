<?php

namespace FlutterSdk\MagicStarter\Traits;

use FlutterSdk\MagicStarter\Jobs\PruneSmsSubscriptions;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\NotificationSetting;
use FlutterSdk\MagicStarter\NotificationPreferenceRegistry;
use FlutterSdk\MagicStarter\Support\OneSignalSubscriptions;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Notification management trait for user models.
 *
 * Provides notification preference management (per-type channel toggles)
 * and push notification routing (OneSignal external ID resolution). It also
 * resets the SMS registration guard and queues the removal of the old number
 * from OneSignal when the model's phone changes.
 *
 * Add to your User model:
 *
 * ```php
 * use FlutterSdk\MagicStarter\Traits\HasNotifications;
 *
 * class User extends Authenticatable
 * {
 *     use HasNotifications;
 * }
 * ```
 */
trait HasNotifications
{
    /**
     * Keep the OneSignal SMS subscription in step with the phone on the model.
     *
     * Every write of `phone` goes through Eloquent (the profile action, user
     * creation, an admin form, consumer code), so the reaction lives here and
     * none of those paths needs to know about it.
     *
     * - `saving` resets the `sms_registered_at` guard, which sends the next SMS
     *   back through `OneSignalSubscriptions::ensureSmsSubscription()`. It acts
     *   when the attribute is loaded OR the table has the column, so a query that
     *   did not select it still clears the guard in the database. An app that
     *   never published the column must not get an unknown-column write, which
     *   is why the schema is asked, and only on a phone change.
     * - `updated` queues {@see PruneSmsSubscriptions} so the number the account
     *   stopped using leaves OneSignal. `getOriginal()` still holds the previous
     *   value there, and a first phone has nothing to prune. The dispatch waits
     *   for the outermost commit through `DB::afterCommit`, so a rolled-back
     *   change sends nothing, and the model event itself never calls OneSignal.
     *   On a `sync` queue the job runs right there, so a failure is reported
     *   rather than thrown: the phone change has already been stored.
     */
    public static function bootHasNotifications(): void
    {
        static::saving(function (Model $model): void {
            if (
                $model->exists
                && $model->isDirty('phone')
                && (
                    array_key_exists('sms_registered_at', $model->getAttributes())
                    || Schema::connection($model->getConnectionName())
                        ->hasColumn($model->getTable(), 'sms_registered_at')
                )
            ) {
                $model->setAttribute('sms_registered_at', null);
            }
        });

        static::updated(function (Model $model): void {
            if (! $model->wasChanged('phone')) {
                return;
            }

            $previous = $model->getOriginal('phone');

            if (
                ! is_string($previous)
                || trim($previous) === ''
                || ! OneSignalSubscriptions::provisioned()
            ) {
                return;
            }

            DB::afterCommit(fn () => rescue(function () use ($model): void {
                PruneSmsSubscriptions::dispatch($model);
            }, report: true));
        });
    }

    /**
     * Merge the trait's attribute casts into the model.
     *
     * Eloquent calls this automatically for every model that uses the trait,
     * so consuming apps get the `sms_registered_at` datetime cast (used by the
     * on-demand OneSignal SMS subscription guard, which the trait boot
     * resets when the phone changes) without declaring it manually.
     */
    public function initializeHasNotifications(): void
    {
        $this->mergeCasts([
            'sms_registered_at' => 'datetime',
        ]);
    }

    /**
     * Get the notification settings overrides for the user.
     *
     * @return MorphMany<NotificationSetting, $this>
     */
    public function notificationSettings(): MorphMany
    {
        return $this->morphMany(NotificationSetting::class, 'notifiable');
    }

    /**
     * Determine if the user prefers to receive a notification type on a given channel.
     */
    public function prefers(string $type, string $channel): bool
    {
        // 1. If type not in registry, allow delivery by default.
        if (! NotificationPreferenceRegistry::has($type)) {
            return true;
        }

        // 2. Check DB for explicit override.
        $override = $this->notificationSettings->where('type', $type)->where('channel', $channel)->first();

        if ($override !== null) {
            return $override->is_enabled;
        }

        // 3. If no override, check if channel is enabled by default in registry.
        return in_array($channel, NotificationPreferenceRegistry::defaults($type), true);
    }

    /**
     * Get the full matrix of notification preferences across all registered types and channels.
     *
     * Returns slug-based keys for the matrix, even when the registry uses FQCN keys.
     * This ensures the API response shape is consistent regardless of the registry key format.
     *
     * @return array<string, array{label: string, channels: array<string, array{enabled: bool, locked: bool}>}>
     */
    public function notificationPreferenceMatrix(): array
    {
        $matrix = [];
        $settings = $this->notificationSettings->groupBy('type');
        $types = NotificationPreferenceRegistry::all();

        foreach ($types as $registryKey => $definition) {
            // 1. Resolve slug from the registry key (FQCN or legacy string).
            $slug = NotificationPreferenceRegistry::resolveSlug($registryKey) ?? $registryKey;

            // 2. Look up DB overrides by slug (DB stores slugs, not FQCNs).
            $typeSettings = $settings->get($slug, collect())->keyBy('channel');
            $channels = [];

            foreach ($definition['channels'] as $channelSlug) {
                $override = $typeSettings->get($channelSlug);
                $isLocked = in_array(
                    $channelSlug,
                    $definition['locked'] ?? [],
                    true,
                );

                $channels[$channelSlug] = [
                    'enabled' => $override !== null
                        ? $override->is_enabled
                        : in_array(
                            $channelSlug,
                            $definition['default'] ?? [],
                            true,
                        ),
                    'locked' => $isLocked,
                ];
            }

            // 3. Use slug as the matrix key (not FQCN) for consistent API shape.
            $matrix[$slug] = [
                'label' => $this->translateTypeLabel($definition['label']),
                'channels' => $channels,
            ];
        }

        return $matrix;
    }

    /**
     * Resolve a registered notification-type label for this notifiable.
     *
     * The label goes through the translator so an app can register a
     * translation key instead of a finished sentence. Doing it HERE rather than
     * at registration time is load-bearing: the registry is filled in a service
     * provider's `boot()`, which runs before any locale middleware, and under
     * Octane runs once for the lifetime of the worker. A `__()` call over there
     * resolves in the default locale and then freezes, so every request would
     * answer in whichever language the first one happened to want.
     *
     * The locale comes from the notifiable's own `HasLocalePreference` when it
     * declares one, which is the same contract Laravel's own
     * `NotificationSender` honours before rendering a notification
     * (`Illuminate\Notifications\NotificationSender::withLocale`). This package
     * WRITES `users.locale` at registration and login and never applied it
     * anywhere, so an adopter with no locale middleware of its own stored a
     * preference this package then ignored. A `null` locale means "whatever the
     * app has set", so an adopter that resolves the locale in middleware is
     * unaffected and the two agree.
     *
     * Non-breaking for an app that registers a plain sentence: `__()` returns
     * its argument unchanged when no translation line matches it, so
     * 'Incident opened' stays 'Incident opened'.
     *
     * The `is_string` guard is not defensive noise. `Translator::get()` returns
     * the line UNCHANGED when it is an array, so a key naming a group of lines
     * rather than one line would put a nested object where the response shape
     * promises a string. Falling back to the registered key is more useful to
     * whoever has to debug it than a JSON blob in a label.
     */
    protected function translateTypeLabel(string $label): string
    {
        $locale = $this instanceof HasLocalePreference
            ? $this->preferredLocale()
            : null;

        $translated = __($label, [], $locale);

        return is_string($translated) ? $translated : $label;
    }

    /**
     * Route notifications for the OneSignal channel (SDK v5 alias targeting).
     *
     * Returns an alias map that `OneSignalChannel` passes to
     * `\onesignal\client\model\Notification::setIncludeAliases()`.
     * The outer key is the alias label ('external_id') and the value is an
     * array of alias values to target.
     *
     * The prefix is required for two reasons:
     *   - OneSignal rejects bare numeric values such as '0', '1', '-1' as
     *     alias values.
     *   - The Flutter client registers the same prefixed id, so both sides
     *     must agree on the format.
     *
     * It comes from `magic-starter.onesignal.external_id_prefix` rather than
     * being written here, because {@see \FlutterSdk\MagicStarter\Notifications\Channels\OneSignalChannel}
     * composes the same string for a notifiable that does not use this trait,
     * and two hardcoded copies of a value whose mismatch is SILENT is the
     * shape this stack has already been bitten by. The client's own default
     * matches.
     *
     * `getKey()` is used instead of `$this->id` so the method works correctly
     * for both integer and UUID primary keys.
     *
     * Override in your User model to change the alias label or value format:
     *
     *   public function routeNotificationForOneSignal(): array
     *   {
     *       return ['external_id' => ['app_user_' . $this->uuid]];
     *   }
     *
     * @return array<string, array<int, string>>
     */
    public function routeNotificationForOneSignal(): array
    {
        return [
            'external_id' => [MagicStarter::onesignalExternalIdPrefix() . $this->getKey()],
        ];
    }
}
