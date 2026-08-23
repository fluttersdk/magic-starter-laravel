<?php

namespace FlutterSdk\MagicStarter\Support;

use Illuminate\Support\Str;

/**
 * What a billable subject's primary key can look like on THIS deployment.
 *
 * The shape is not fixed: `magic-starter.use_uuids` decides it at migration
 * time, so a rail handing back an App User ID has to be checked against the
 * switch rather than against a hardcoded UUID pattern, or a deployment on
 * integer keys refuses every legitimate id it is given.
 *
 * Extracted because two callers had grown their own copy of it, the store
 * re-read job and the hourly reconciler, byte-identical apart from a parameter
 * name. Neither copy was wrong; two copies of one deployment-wide rule is how a
 * later change to that rule reaches one caller and not the other.
 */
final class TeamKey
{
    /**
     * Whether [$id] could be a billable key at all on this deployment.
     *
     * A cheap SHAPE check and nothing more. It answers "is this worth a database
     * lookup", never "does this subject exist", so a caller still has to resolve
     * the row. Its value is refusing an id that could not be one before spending
     * a query on it, which on the store rail means an App User ID a consumer
     * configured wrongly is named as malformed rather than reported as a missing
     * subject.
     */
    public static function looksLikeOne(string $id): bool
    {
        return config('magic-starter.use_uuids')
            ? Str::isUuid($id)
            : ctype_digit($id);
    }
}
