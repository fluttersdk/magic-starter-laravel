<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\CreatesUsers;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\NewsletterSubscriber;
use FlutterSdk\MagicStarter\Support\RequestLocaleDetector;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Default user creation action with optional feature-gated fields.
 *
 * When Features::extendedProfile() is enabled, stores locale and timezone.
 * When Features::newsletterSubscription() is enabled, creates a newsletter subscriber record.
 */
class CreateUser implements CreatesUsers
{
    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, mixed>  $input  The registration data.
     * @return Authenticatable The created user instance.
     *
     * @throws ValidationException
     */
    public function create(array $input): Authenticatable
    {
        // 1. Build validation rules based on enabled features.
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique((new (MagicStarter::userModel()))->getTable(), 'email'),
            ],
            'password' => ['required', 'string', 'min:8'],
        ];

        if (Features::hasExtendedProfileFeatures()) {
            $rules['locale'] = ['nullable', 'string', 'max:5'];
            $rules['timezone'] = ['nullable', 'string', 'timezone'];
            $rules['email_verified_at'] = ['nullable', 'date'];
        }

        if (Features::hasNewsletterSubscriptionFeatures()) {
            $rules['subscribe_newsletter'] = ['nullable', 'boolean'];
        }

        $validated = Validator::make($input, $rules)->validate();

        // 2. Build user attributes.
        $attributes = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ];

        if (Features::hasExtendedProfileFeatures()) {
            $defaults = config('magic-starter.defaults', []);
            $request = request();

            // 2a. Auto-detect from request headers, falling back to config defaults.
            $detectedLocale = $request
                ? RequestLocaleDetector::detectLocale($request)
                : null;
            $detectedTimezone = $request
                ? RequestLocaleDetector::detectTimezone($request)
                : null;

            $attributes['locale'] = Arr::get($validated, 'locale')
                ?? $detectedLocale
                ?? ($defaults['locale'] ?? 'en');
            $attributes['timezone'] = Arr::get($validated, 'timezone')
                ?? $detectedTimezone
                ?? ($defaults['timezone'] ?? 'UTC');
            $attributes['email_verified_at'] = Arr::get($validated, 'email_verified_at');
        }

        // 3. Create the user via dynamic model resolution.
        $userModel = MagicStarter::userModel();
        $user = $userModel::query()->create($attributes);

        // 4. Handle newsletter subscription when feature is enabled.
        if (Features::hasNewsletterSubscriptionFeatures()
            && Arr::get($validated, 'subscribe_newsletter', false)
        ) {
            NewsletterSubscriber::query()->firstOrCreate(
                ['email' => $user->email],
                [
                    'source' => 'registration',
                    'is_active' => true,
                ],
            );
        }

        return $user;
    }
}
