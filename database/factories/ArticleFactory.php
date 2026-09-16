<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\article>
 */
class ArticleFactory extends Factory
{
    protected $model = \App\Models\article::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = $this->faker->unique()->sentence(6);

        return [
            'title' => $title,
            'slug' => Str::slug($title),
            'summary' => $this->faker->optional(0.85)->paragraph(3),
            'content' => $this->faker->optional(0.9)->paragraphs(6, true),
            'category_id' => null,
            'sort_order' => $this->faker->numberBetween(0, 50),
            'image' => null,
            'published_at' => $this->faker->optional(0.8)->dateTimeBetween('-6 months', 'now'),
        ];
    }
}
