<?php

namespace FlutterSdk\MagicStarter\Social;

/**
 * Reads claims a provider may withhold, send empty, or send as another type.
 */
final class Claims
{
    /**
     * The value when it is a non-empty string, else null.
     */
    public static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
