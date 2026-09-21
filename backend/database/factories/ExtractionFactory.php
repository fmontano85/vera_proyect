<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Article;
use App\Models\Extraction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Extraction>
 */
class ExtractionFactory extends Factory
{
    protected $model = Extraction::class;

    public function definition(): array
    {
        return [
            'article_id' => Article::factory(),
            'modelo' => 'claude-haiku-4-5',
            'json_resultado' => [],
            'confianza' => fake()->randomFloat(3, 0, 1),
            'tokens_in' => fake()->numberBetween(100, 2000),
            'tokens_out' => fake()->numberBetween(50, 500),
            'costo' => null,
        ];
    }
}
