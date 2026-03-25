<?php

namespace Database\Factories;

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
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'remember_token' => Str::random(10),
            'locale' => 'en',
            'timezone' => 'UTC',
            'is_guest' => false,
        ];
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
