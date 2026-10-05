<?php

namespace FlutterSdk\MagicStarter\Traits;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Str;

/**
 * Stores a user's email address in lower case.
 *
 * PostgreSQL compares strings case-sensitively, so `Bob@x.io` and `bob@x.io`
 * would otherwise be two accounts. The package lower-cases every email it
 * receives and looks up; this mutator closes the remaining door, a model write
 * from the consumer's own code. The consumer adds a `lower(email)` unique index
 * so the database refuses what the application missed.
 */
trait NormalizesEmail
{
    /**
     * Lower-case a non-null email on assignment.
     *
     * Null passes through untouched: a phone-identity or guest account has no email.
     *
     * @return Attribute<mixed, mixed>
     */
    protected function email(): Attribute
    {
        return Attribute::set(
            fn (mixed $value): mixed => is_string($value) ? Str::lower($value) : $value,
        );
    }
}
