<?php

namespace FlutterSdk\MagicStarter\Support;

use Exception;
use GeoIp2\Database\Reader;

class SessionLocation
{
    /**
     * Resolve the location based on the given IP address.
     *
     * @return array{city: string|null, country: string|null}|null
     */
    public static function resolve(?string $ipAddress): ?array
    {
        if (empty($ipAddress)) {
            return null;
        }

        // Loopback/private IPs will not resolve cleanly.
        if (in_array($ipAddress, ['127.0.0.1', '::1'], true) || filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }

        if (! class_exists(Reader::class)) {
            return null;
        }

        $dbPath = config('magic-starter.two_factor.geoip_db_path');

        if (empty($dbPath) || ! file_exists((string) $dbPath)) {
            return null;
        }

        try {
            $reader = new Reader((string) $dbPath);
            $record = $reader->city($ipAddress);

            return [
                'city' => $record->city->name,
                'country' => $record->country->isoCode,
            ];
        } catch (Exception) {
            return null;
        }
    }
}
