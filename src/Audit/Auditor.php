<?php

namespace FlutterSdk\MagicStarter\Audit;

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Writes audit rows and erases a deleted user's trail.
 *
 * Every write is captured at the moment of the change (subject values, actor,
 * request context) and inserted through `DB::afterCommit()`: a change that
 * rolls back leaves no row, and outside a transaction the row lands at once.
 */
class Auditor
{
    /**
     * How many `withoutAuditing()` callbacks are currently running.
     *
     * A depth rather than a flag, so a nested call cannot resume auditing
     * while its outer caller still expects silence.
     */
    private static int $suppressed = 0;

    /**
     * Record an explicit event, such as an administrator's action.
     *
     * Explicit events are written even inside `withoutAuditing()`, which only
     * silences the model listener: the usual shape is an admin action that
     * suppresses the model noise and records one meaningful event instead.
     * Nothing is written while the audit feature is off, because the table
     * only exists once the feature's migration was published.
     *
     * @param  string  $event  A dotted event name, e.g. `admin.user_impersonated`.
     * @param  array<string, mixed>  $context  Merged over the request context (ip, user agent, url, route).
     * @param  Authenticatable|null  $actor  Null falls back to the authenticated user, if any.
     */
    public static function record(
        string $event,
        ?Model $subject,
        array $context = [],
        ?Authenticatable $actor = null,
    ): void {
        if (! Features::hasAuditFeatures()) {
            return;
        }

        self::write($event, $subject, null, null, $context, $actor);
    }

    /**
     * Capture a row now and insert it once the surrounding transaction commits.
     *
     * The values must already be the ones to describe; this method redacts them
     * against the subject but chooses nothing.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  array<string, mixed>  $context
     */
    public static function write(
        string $event,
        ?Model $subject,
        ?array $oldValues,
        ?array $newValues,
        array $context = [],
        ?Authenticatable $actor = null,
    ): void {
        $actor ??= auth()->user();

        $attributes = [
            'event' => $event,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => self::stringKey($subject?->getKey()),
            'actor_type' => $actor === null ? null : self::actorType($actor),
            'actor_id' => self::stringKey($actor?->getAuthIdentifier()),
            'related_user_id' => self::relatedUserId($subject),
            'old_values' => $subject === null ? $oldValues : Redactor::redact($subject, $oldValues),
            'new_values' => $subject === null ? $newValues : Redactor::redact($subject, $newValues),
            'context' => [
                ...self::requestContext(),
                ...$context,
            ],
        ];

        DB::afterCommit(function () use ($attributes): void {
            Audit::query()->create($attributes);
        });
    }

    /**
     * Run the callback with the model listener silenced.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function withoutAuditing(callable $callback): mixed
    {
        self::$suppressed++;

        try {
            return $callback();
        } finally {
            self::$suppressed--;
        }
    }

    /**
     * Whether the model listener should record changes right now.
     */
    public static function isAuditing(): bool
    {
        return self::$suppressed === 0;
    }

    /**
     * Erase the trail of a deleted user.
     *
     * Rows about the user and rows related to them (their teams, their linked
     * accounts) are deleted, since their values are that person's data. Rows
     * the user acted in survive with `actor_id` nulled and `actor_type` kept:
     * what happened to somebody else's record stays, who did it does not.
     * Query-builder writes, so no model event re-enters the listener.
     *
     * @param  int|string  $key  The deleted user's primary key.
     */
    public static function forgetUser(int|string $key): void
    {
        $key = (string) $key;
        $userType = self::userMorphClass();

        Audit::query()
            ->where(function ($query) use ($userType, $key): void {
                $query->where('auditable_type', $userType)->where('auditable_id', $key);
            })
            ->orWhere('related_user_id', $key)
            ->delete();

        Audit::query()
            ->where('actor_type', $userType)
            ->where('actor_id', $key)
            ->update([
                'actor_id' => null,
            ]);
    }

    /**
     * Whether the model is the application's user model.
     */
    public static function isUser(Model $model): bool
    {
        return is_a($model, MagicStarter::userModel());
    }

    /**
     * The user a row is about: the subject itself when it is the user model,
     * otherwise its raw `user_id` attribute.
     *
     * Read from the raw attributes rather than `getAttribute()`, which throws
     * for a missing column under `Model::preventAccessingMissingAttributes()`.
     */
    private static function relatedUserId(?Model $subject): ?string
    {
        if ($subject === null) {
            return null;
        }

        if (self::isUser($subject)) {
            return self::stringKey($subject->getKey());
        }

        return self::stringKey($subject->getAttributes()['user_id'] ?? null);
    }

    /**
     * The request the change happened in.
     *
     * The url is taken without its query string, which is where signed links
     * and reset tokens travel.
     *
     * @return array{ip: string|null, user_agent: string|null, url: string, route: string|null}
     */
    private static function requestContext(): array
    {
        $request = request();

        return [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'url' => $request->url(),
            'route' => $request->route()?->getName(),
        ];
    }

    private static function actorType(Authenticatable $actor): string
    {
        return $actor instanceof Model ? $actor->getMorphClass() : $actor::class;
    }

    private static function userMorphClass(): string
    {
        $userModel = MagicStarter::userModel();

        /** @var Model $user */
        $user = new $userModel;

        return $user->getMorphClass();
    }

    private static function stringKey(mixed $key): ?string
    {
        return $key === null ? null : (string) $key;
    }
}
