<?php

namespace FlutterSdk\MagicStarter\Support;

use BackedEnum;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use DateTimeInterface;
use FlutterSdk\MagicStarter\Enums\PlanStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The one place this package decodes a billable's billing state.
 *
 * The package ships the columns and not the model that owns them, so what
 * Eloquent hands back for any of them is the consuming application's decision
 * rather than this package's: a consumer may cast `plan` to a tier enum of its
 * own, cast `plan_source_event_at` to `datetime` and `plan_renews` to `boolean`,
 * or cast none of them and leave every read a raw column value. Both shapes are
 * legitimate and every reader in this package has to answer the same for either.
 *
 * Every reader here was a `protected` method on {@see
 * \FlutterSdk\MagicStarter\Actions\WriteEntitlement}, which is where each rule
 * was first needed and the wrong place for it to live: a controller, a resource,
 * a job or a command cannot reach a protected method on an action, so each of
 * them would have grown its own copy. Two copies of a decode rule are free to
 * disagree with each other while the tests on both sides pass, and the
 * disagreement here is not cosmetic: read the tier as absent and a cross-rail
 * revocation looks like it has nothing to take away, read the boolean as a
 * string and `1 !== true` disagrees forever, read the date as a string and a
 * `?CarbonInterface` parameter is a TypeError on a payment path, and answer
 * "holds a paid tier" two ways and a badge disagrees with a deletion refusal.
 *
 * The methods stay `protected` rather than becoming a public API. A reader that
 * needs them uses this trait, which is also what makes "nobody carries a second
 * copy" a thing a test can assert about a file.
 */
trait ReadsBillableAttributes
{
    /**
     * Read a stored attribute as a plain string id.
     *
     * A consuming application is free to cast `plan` (or `plan_provider`) to an
     * enum of its own, in which case Eloquent hands back an enum instance
     * rather than the column's text. Unwrapping the backing value here is what
     * keeps rule 2 armed on such a model: a stored tier read as absent would
     * make every cross-rail write look like it had nothing to revoke.
     *
     * Anything that is neither a backed enum nor a non-empty string reads as
     * absent, which is the direction that cannot revoke.
     */
    protected function stringAttribute(Model $billable, string $attribute): ?string
    {
        $value = $billable->getAttribute($attribute);

        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Read a stored attribute as an instant, whether or not the consumer's model
     * casts that column.
     *
     * A cast `datetime` and a raw string are both accepted, because the package
     * ships the column and not the model that owns it. A value that is neither
     * reads as absent.
     *
     * A malformed date string RAISES rather than reading as absent, and that is
     * the one place a defensive null would be the more expensive answer: every
     * timestamp under these columns was written by this package itself, so an
     * unparseable one means the row is corrupt, and absent is what lets an
     * incoming write apply. Returning null from here would therefore disarm the
     * ordering rule that reads it, silently, on the only kind of row where the
     * ordering cannot be trusted at all.
     *
     * @throws InvalidFormatException When the stored string is not a date.
     */
    protected function dateAttribute(Model $billable, string $attribute): ?CarbonInterface
    {
        $stored = $billable->getAttribute($attribute);

        if ($stored instanceof DateTimeInterface) {
            return Carbon::instance($stored);
        }

        if (is_string($stored) && $stored !== '') {
            return Carbon::parse($stored);
        }

        return null;
    }

    /**
     * Read a stored attribute as a real boolean, whether or not the consumer's
     * model casts that column.
     *
     * An uncast boolean column does not arrive as a boolean, and which shape it
     * arrives in is the database driver's decision rather than the schema's:
     * SQLite hands back `1` and `0`, PostgreSQL hands back `true` and `false`
     * since PHP 8.1 returned native types for it, and MySQL hands back either an
     * int or its decimal string. A reader comparing any of those against `true`
     * disagrees with the column forever, which is worse than a wrong answer
     * because it is a stable one.
     *
     * An unrecognised word reads as absent rather than raising, which is the
     * opposite decision from {@see self::dateAttribute()} and is the difference
     * between the two columns rather than an inconsistency. A date decides
     * whether a write applies at all, so an unusable one must stop the write; a
     * renewal flag is reported to a client, and null is the honest word for "the
     * column does not say".
     */
    protected function booleanAttribute(Model $billable, string $attribute): ?bool
    {
        $value = $billable->getAttribute($attribute);

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (! is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            '1', 'true', 't', 'yes', 'on' => true,
            '0', 'false', 'f', 'no', 'off' => false,
            default => null,
        };
    }

    /**
     * Whether the billable currently holds a tier somebody is paying for.
     *
     * The package ships no tier vocabulary, so for a long time this was a bare
     * null check: a revoked subject stores NULL rather than a free-tier word,
     * and "holds nothing" stayed answerable without naming a tier. The gap that
     * left is an adopter who writes their OWN free-tier word on a downgrade
     * instead of the NULL this package's writer stores. Such a subject reads as
     * paying forever, which is a wrong badge on one call site and a team that
     * can never be deleted on the other, refused with a sentence naming a
     * subscription the customer has already cancelled.
     *
     * The floor comes from `magic-starter.billing.tier_order`, whose documented
     * convention is cheapest first, so its first entry is the adopter's own
     * declaration of what free means to them. That is the adopter naming their
     * floor, not this package inventing one, which is the distinction that made
     * a null check the only honest answer before the catalogue existed.
     *
     * An UNPUBLISHED catalogue yields a null floor, and `$tier !== null` has
     * already answered by then, so an adopter who publishes nothing keeps
     * exactly the behaviour they have today. This widens what the package can
     * recognise; it never narrows it.
     */
    protected function holdsPaidTier(?string $tier, PlanStatus $status): bool
    {
        return $tier !== null
            && $tier !== ($this->tierOrder()[0] ?? null)
            && $status->grants();
    }

    /**
     * The adopter's plan catalogue, cheapest first.
     *
     * Read from config rather than from any enum here, because the package
     * ships no tier vocabulary at all. Non-string entries are discarded rather
     * than cast: a catalogue holding something other than plan ids is not a
     * catalogue this package can rank against, and silently stringifying it
     * would invent an order.
     *
     * It lives on this trait rather than on the action that first needed it
     * because two readers now rank against the same list, and the rule it
     * encodes decides whether money keeps moving. A second copy would be free
     * to disagree with the first while both sides' tests stayed green.
     *
     * @return list<string>
     */
    protected function tierOrder(): array
    {
        $configured = config('magic-starter.billing.tier_order', []);

        if (! is_array($configured)) {
            return [];
        }

        $order = [];

        foreach ($configured as $planId) {
            if (is_string($planId) && $planId !== '') {
                $order[] = $planId;
            }
        }

        return $order;
    }

    /**
     * The stored source-event timestamp the ordering rules reason about.
     *
     * A delegation and not a second decoder, deliberately. It was the only date
     * reader in this package once, so it hardcoded its own column and did its own
     * parsing; the moment a second column needed the same treatment that shape
     * became two copies of one rule, and the copy nobody edited is the one that
     * keeps the old behaviour. The column name stays named here because the
     * ordering rules are about this column specifically and no caller of theirs
     * should have to know its name.
     *
     * @throws InvalidFormatException When the stored string is not a date.
     */
    protected function storedEventAt(Model $billable): ?CarbonInterface
    {
        return $this->dateAttribute($billable, 'plan_source_event_at');
    }
}
