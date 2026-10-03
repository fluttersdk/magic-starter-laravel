<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\ConnectsSocialAccounts;
use FlutterSdk\MagicStarter\Contracts\CreatesUsersFromProvider;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Social\VerifiedIdentity;
use FlutterSdk\MagicStarter\Support\RequestLocaleDetector;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * Default action creating the account a new provider identity signs in as.
 *
 * Not `CreateUser`: that action validates and hashes a password, and a social
 * account has none until its owner sets one. The address counts as verified
 * only when the provider vouches for it; anything else would let an unverified
 * provider address skip the package's email verification.
 */
class CreateUserFromProvider implements CreatesUsersFromProvider
{
    public function __construct(
        protected ConnectsSocialAccounts $connector,
    ) {}

    /**
     * Create the user, link the identity and fire `Registered`, in one transaction.
     *
     * `Registered` fires inside the transaction so the personal team its
     * listener creates rolls back with the user when anything after it fails.
     *
     * @param  Request  $request  read for the new user's locale and timezone
     * @return Authenticatable the created user
     */
    public function create(VerifiedIdentity $identity, Request $request): Authenticatable
    {
        return DB::transaction(function () use ($identity, $request): Authenticatable {
            // 1. Create the passwordless user. Every attribute is computed here
            //    rather than taken from input, so it is force-filled past a
            //    consumer model's `$fillable`, which rarely lists `email_verified_at`.
            $userModel = MagicStarter::userModel();
            $user = $userModel::query()->forceCreate($this->attributesFor($identity, $request));

            // 2. Link the identity. The unique (provider, provider_user_id) index
            //    is what stops a concurrent first sign-in from linking twice.
            //    The link is unconfirmed when the provider did not vouch for the
            //    address, so a later proof of mailbox control can sever it.
            $this->connector->connect($user, $identity, ownerConfirmed: $identity->emailVerified);

            // 3. Let the package's listeners (the personal team) and the
            //    framework's (the verification mail) see the new account.
            Event::dispatch(new Registered($user));

            return $user;
        });
    }

    /**
     * Build the new user's attributes.
     *
     * Locale and timezone are written only when the feature that ships their
     * column is on, matching `CreateUser`.
     *
     * @return array<string, mixed>
     */
    private function attributesFor(VerifiedIdentity $identity, Request $request): array
    {
        $defaults = config('magic-starter.defaults', []);

        $attributes = [
            'name' => $this->nameFor($identity),
            'email' => $identity->email,
            'password' => null,
            'email_verified_at' => $identity->emailVerified ? now() : null,
        ];

        if (Features::hasExtendedProfileFeatures()) {
            $attributes['locale'] = RequestLocaleDetector::detectLocale($request)
                ?? ($defaults['locale'] ?? 'en');
        }

        if (Features::hasTimezoneOrExtendedProfileFeatures()) {
            $attributes['timezone'] = RequestLocaleDetector::detectTimezone($request)
                ?? ($defaults['timezone'] ?? 'UTC');
        }

        return $attributes;
    }

    /**
     * The provider's name, else the address's local part.
     *
     * Apple sends the name only on the first authorisation and some providers
     * never do, while the `name` column is required.
     */
    private function nameFor(VerifiedIdentity $identity): string
    {
        $name = trim((string) $identity->name);

        if ($name !== '') {
            return $name;
        }

        $localPart = Str::before((string) $identity->email, '@');

        return $localPart !== '' ? $localPart : 'User';
    }
}
