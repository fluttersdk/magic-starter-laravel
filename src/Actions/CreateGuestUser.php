<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\CreatesGuestUsers;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Support\RequestLocaleDetector;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Validator;

/**
 * Action for creating or finding a guest user by device ID.
 */
class CreateGuestUser implements CreatesGuestUsers
{
    /**
     * Create or find a guest user.
     *
     * @param  array<string, mixed>  $input  The guest user data.
     * @return Authenticatable The guest user instance.
     */
    public function create(array $input): Authenticatable
    {
        // 1. Validate device ID.
        $validated = Validator::make($input, [
            'device_id' => ['required', 'string', 'max:255'],
        ])->validate();

        // 2. Prepare default attributes for a guest user.
        $attributes = [
            'is_guest' => true,
            'name' => 'Guest',
            'email' => null,
            'password' => null,
        ];

        // 3. Handle extended profile features (locale/timezone) if enabled.
        if (Features::hasExtendedProfileFeatures()) {
            $defaults = config('magic-starter.defaults', []);
            $request = request();

            $detectedLocale = $request ? RequestLocaleDetector::detectLocale($request) : null;
            $detectedTimezone = $request ? RequestLocaleDetector::detectTimezone($request) : null;

            $attributes['locale'] = $detectedLocale ?? ($defaults['locale'] ?? 'en');
            $attributes['timezone'] = $detectedTimezone ?? ($defaults['timezone'] ?? 'UTC');
        }

        // 4. Find existing guest or create a new one using firstOrCreate.
        $userModel = MagicStarter::userModel();

        return $userModel::query()->firstOrCreate(
            ['device_id' => $validated['device_id']],
            $attributes,
        );
    }
}
