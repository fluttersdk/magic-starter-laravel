<?php

namespace FlutterSdk\MagicStarter\Database\Factories;

use FlutterSdk\MagicStarter\Enums\SupportArticleVisibility;
use FlutterSdk\MagicStarter\Models\SupportArticle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for SupportArticle model.
 *
 * Builds a public article that is live and approved in English, the shape most
 * tests want; a test overrides what it is about.
 *
 * @extends Factory<SupportArticle>
 */
class SupportArticleFactory extends Factory
{
    protected $model = SupportArticle::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(3),
            'section' => 'general',
            'sort' => 0,
            'visibility' => SupportArticleVisibility::PUBLIC,
            'published_at' => now()->subMinute(),
            'question' => [
                'en' => fake()->sentence() . '?',
            ],
            'body' => [
                'en' => fake()->paragraph(),
            ],
            'locale_state' => [
                'en' => SupportArticle::LOCALE_APPROVED,
            ],
        ];
    }
}
