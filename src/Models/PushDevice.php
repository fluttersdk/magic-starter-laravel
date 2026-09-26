<?php

namespace FlutterSdk\MagicStarter\Models;

use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One push subscription a person's client has reported the delivery state of.
 *
 * A "device" here is exactly one push subscription: no name, no platform, no
 * token, because the only question this table answers is whether a push sent
 * now would arrive. The shape is the client's `PushDeliverySnapshot.toMap()`
 * (`magic_notifications`), stored beside the two facts the server adds: who
 * posted it, and when it arrived.
 *
 * Ask through {@see canReachByPush()}: its four conditions are what keep a row
 * from vouching for more than it knows.
 *
 * @property string|int $id
 * @property string|int $user_id
 * @property string|null $external_id
 * @property string|null $subscription_id
 * @property string $reachability
 * @property Carbon $captured_at
 * @property Carbon $reported_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model $user
 */
class PushDevice extends Model
{
    use ConditionallyUsesUuids;

    /**
     * The one reachability value that means a push sent now would arrive.
     *
     * The client's `PushReachability` has three other cases (`unavailable`,
     * `blocked`, `off`), and all three mean "not now" here.
     */
    public const string REACHABLE = 'on';

    /**
     * Every value the client may report, in the client's own vocabulary.
     *
     * @var list<string>
     */
    public const array REACHABILITY_VALUES = [
        'unavailable',
        'blocked',
        'off',
        self::REACHABLE,
    ];

    /**
     * How long a device's report speaks for it, in hours.
     *
     * Chosen by which error is cheap. Expiring a device that is fine costs a
     * log line: the push is still sent and an escalation walks on to somebody
     * it can prove. Trusting a device that went silent (wiped, reinstalled,
     * permission revoked while the app was closed) costs a page nobody hears,
     * and OneSignal reports no failure for it. A client reports on every
     * launch, sign-in and permission change, so a device in daily use refreshes
     * well inside a day.
     */
    public const int FRESH_FOR_HOURS = 24;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'external_id',
        'subscription_id',
        'reachability',
        'captured_at',
        'reported_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'reported_at' => 'datetime',
        ];
    }

    /**
     * The person whose client posted this report.
     *
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(MagicStarter::userModel());
    }

    /**
     * Whether a push sent to the user right now would reach at least one of
     * their devices.
     *
     * ANY device, not every one: a person holding a phone that rings is
     * reachable whatever their browser tabs say. A device counts when all four
     * hold:
     *
     * 1. It said `on`.
     * 2. It holds a subscription id, the address a push is delivered to.
     * 3. It is subscribed as THIS person, not a previous user of a shared phone.
     * 4. It was heard from inside {@see FRESH_FOR_HOURS}, on the server's clock.
     *
     * No row reads as false, which is the safe direction.
     */
    public static function canReachByPush(Model $user): bool
    {
        $externalId = self::externalIdFor($user);

        if ($externalId === null) {
            return false;
        }

        return self::query()
            ->where('user_id', $user->getKey())
            ->where('reachability', self::REACHABLE)
            ->where('external_id', $externalId)
            ->whereNotNull('subscription_id')
            ->where('reported_at', '>=', now()->subHours(self::FRESH_FOR_HOURS))
            ->exists();
    }

    /**
     * Remove one of the user's devices because the person on it signed out.
     *
     * Addressed by (this user, this subscription id), the key a report writes
     * under, so a caller can only release a row of their own and their OTHER
     * devices keep vouching. The row is removed rather than blanked, because a
     * device nobody is signed into makes no statement about reachability.
     *
     * @return bool Whether a row of this user's was removed.
     */
    public static function release(Model $user, string $subscriptionId): bool
    {
        $subscriptionId = trim($subscriptionId);

        if ($subscriptionId === '') {
            return false;
        }

        return self::query()
            ->where('user_id', $user->getKey())
            ->where('subscription_id', $subscriptionId)
            ->delete() > 0;
    }

    /**
     * The OneSignal alias a push to the user is addressed to, or null when the
     * user has none.
     *
     * Read back through `routeNotificationForOneSignal()` rather than composed
     * from the prefix, because that method is what the channel actually targets,
     * and an application that overrides it would otherwise disagree with its own
     * deliveries.
     */
    public static function externalIdFor(Model $user): ?string
    {
        if (! method_exists($user, 'routeNotificationForOneSignal')) {
            return null;
        }

        $aliases = $user->routeNotificationForOneSignal();
        $externalIds = $aliases['external_id'] ?? [];
        $first = is_array($externalIds) ? ($externalIds[0] ?? null) : null;

        return is_string($first) && trim($first) !== '' ? $first : null;
    }
}
