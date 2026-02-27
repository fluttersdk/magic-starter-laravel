<?php

namespace FlutterSdk\MagicStarter\Database\Factories;

use FlutterSdk\MagicStarter\Models\TeamInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Factory for TeamInvitation model.
 *
 * @extends Factory<TeamInvitation>
 */
class TeamInvitationFactory extends Factory
{
    protected $model = TeamInvitation::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'role' => 'member',
            'token' => Str::random(40),
        ];
    }
}
