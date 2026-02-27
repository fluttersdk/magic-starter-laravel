<?php

namespace FlutterSdk\MagicStarter\Database\Factories;

use FlutterSdk\MagicStarter\Models\PersonalAccessToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Factory for PersonalAccessToken model.
 *
 * @extends Factory<PersonalAccessToken>
 */
class PersonalAccessTokenFactory extends Factory
{
    protected $model = PersonalAccessToken::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'auth_token',
            'token' => hash('sha256', Str::random(40)),
            'abilities' => ['*'],
        ];
    }
}
