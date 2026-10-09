<?php

namespace FlutterSdk\MagicStarter\Support;

use stdClass;

/**
 * A map that stays a JSON object when it is empty.
 *
 * PHP encodes an empty array as `[]` whether it stands for a list or a map, and
 * a client decoding a map (currency => price, limit => usage) refuses a list.
 * Every wire map the billing surface serves goes through here, so none of them
 * can reach a client as `[]` by accident.
 */
final class JsonObject
{
    /**
     * @template T
     *
     * @param  array<array-key, T>  $map
     * @return array<array-key, T>|stdClass `{}` when empty, the map untouched otherwise.
     */
    public static function map(array $map): array|stdClass
    {
        return $map === [] ? new stdClass : $map;
    }
}
