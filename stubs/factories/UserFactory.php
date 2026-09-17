<?php

namespace Database\Factories;

use FlutterSdk\MagicStarter\Features;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Factory for User model.
 *
 * @extends Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    protected $model = \App\Models\User::class;

    /**
     * Define the model's default state.
     *
     * Only the columns every install has are unconditional. `locale` and
     * `timezone` arrive with `extended-profile` and `is_guest` with
     * `guest-auth`, so writing them unconditionally made this factory unable to
     * insert a row on an install without those features: every call died with
     * `table users has no column named is_guest`, which is the first thing a
     * consumer's own test suite hits.
     *
     * The `guest()` and `withPhone()` states below are deliberately NOT gated.
     * A caller reaching for one is asking for exactly that column, so failing
     * loudly on an install that lacks it is the honest answer; silently
     * returning a non-guest from `guest()` would not be.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $attributes = [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'remember_token' => Str::random(10),
        ];

        if (Features::enabled(Features::extendedProfile())) {
            $attributes['locale'] = 'en';
            $attributes['timezone'] = 'UTC';
        }

        if (Features::enabled(Features::guestAuth())) {
            $attributes['is_guest'] = false;
        }

        return $attributes;
    }

    /**
     * Indicate that the user is a guest.
     *
     * @return Factory<\App\Models\User>
     */
    public function guest(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'name' => 'Guest',
                'email' => null,
                'password' => null,
                'is_guest' => true,
            ];
        });
    }

    /**
     * Add phone number to the user.
     *
     * @return Factory<\App\Models\User>
     */
    public function withPhone(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'phone' => '+15555550100',
                'phone_country' => 'US',
            ];
        });
    }

    /**
     * Mark the user as unverified.
     *
     * @return Factory<\App\Models\User>
     */
    public function unverified(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'email_verified_at' => null,
            ];
        });
    }
}
