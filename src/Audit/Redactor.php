<?php

namespace FlutterSdk\MagicStarter\Audit;

use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEncryptedCollection;
use Illuminate\Database\Eloquent\Model;
use ReflectionProperty;

/**
 * Replaces sensitive attribute values with a placeholder before they reach an
 * audit row.
 *
 * Deny-by-default: an attribute is redacted when the model hides it, encrypts
 * or hashes it through a cast, names it in `$auditExclude`, or when it is a
 * credential column (the built-in list plus `magic-starter.audit.redact`). The
 * configured list EXTENDS the built-in one and can never shorten it, so a
 * published config cannot quietly start storing passwords or 2FA secrets.
 */
class Redactor
{
    public const PLACEHOLDER = '[redacted]';

    /**
     * Credential columns redacted on every model, whatever it declares.
     *
     * `device_id` is one: `POST auth/guest` with it signs that guest in.
     *
     * @var list<string>
     */
    private const ALWAYS = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'token',
        'device_id',
    ];

    /**
     * Class casts that encrypt; string casts are matched by their `encrypted` prefix.
     *
     * @var list<class-string>
     */
    private const ENCRYPTING_CASTS = [
        AsEncryptedArrayObject::class,
        AsEncryptedCollection::class,
    ];

    /**
     * Redact the sensitive entries of an attribute map, keeping every key.
     *
     * @param  Model  $model  The model the values belong to; its hidden list, casts and exclusions decide.
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null Null when given null.
     */
    public static function redact(Model $model, ?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $sensitive = array_flip(self::sensitiveKeys($model));

        foreach (array_keys($values) as $key) {
            if (isset($sensitive[$key])) {
                $values[$key] = self::PLACEHOLDER;
            }
        }

        return $values;
    }

    /**
     * Every attribute name the given model's audit rows must not carry in clear.
     *
     * @return list<string>
     */
    public static function sensitiveKeys(Model $model): array
    {
        /** @var list<string> $configured */
        $configured = config('magic-starter.audit.redact', []);

        return array_values(array_unique([
            ...self::ALWAYS,
            ...$configured,
            ...$model->getHidden(),
            ...self::protectedCasts($model),
            ...self::auditExclude($model),
        ]));
    }

    /**
     * Attributes whose cast encrypts or hashes the stored value.
     *
     * The raw column holds ciphertext or a hash, and a cast's arguments
     * (`encrypted:array`, `AsEncryptedCollection::class.':...'`) follow a colon.
     *
     * @return list<string>
     */
    private static function protectedCasts(Model $model): array
    {
        $keys = [];

        foreach ($model->getCasts() as $key => $cast) {
            $type = explode(':', (string) $cast, 2)[0];

            if (str_starts_with($type, 'encrypted')
                || $type === 'hashed'
                || in_array($type, self::ENCRYPTING_CASTS, true)
            ) {
                $keys[] = (string) $key;
            }
        }

        return $keys;
    }

    /**
     * Attributes whose change alone is not worth an `updated` row.
     *
     * The model's `$auditIgnore` property plus every `audit.ignore` entry whose
     * class the model is an instance of, matched like `audit.exclude`. It lives
     * here because it is read by reflection exactly like `$auditExclude`.
     *
     * @return list<string>
     */
    public static function ignoredKeys(Model $model): array
    {
        $ignored = [];

        if (property_exists($model, 'auditIgnore')) {
            $declared = (new ReflectionProperty($model, 'auditIgnore'))->getValue($model);

            $ignored = is_array($declared) ? array_map('strval', $declared) : [];
        }

        /** @var array<class-string, list<string>> $configured */
        $configured = config('magic-starter.audit.ignore', []);

        foreach ($configured as $class => $keys) {
            if ($model instanceof $class) {
                $ignored = [...$ignored, ...$keys];
            }
        }

        // A credential change is always worth a row, like the redaction list
        // config can never shorten.
        return array_values(array_diff(array_unique($ignored), self::ALWAYS));
    }

    /**
     * The model's own `$auditExclude` list, when it declares one.
     *
     * Read by reflection because the property is usually protected, like
     * `$hidden`, and the global listener audits models that use no trait of
     * this package.
     *
     * @return list<string>
     */
    private static function auditExclude(Model $model): array
    {
        if (! property_exists($model, 'auditExclude')) {
            return [];
        }

        $excluded = (new ReflectionProperty($model, 'auditExclude'))->getValue($model);

        return is_array($excluded) ? array_values(array_map('strval', $excluded)) : [];
    }
}
