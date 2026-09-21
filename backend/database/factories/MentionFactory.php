<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Article;
use App\Models\Mention;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Mention>
 */
class MentionFactory extends Factory
{
    protected $model = Mention::class;

    public function definition(): array
    {
        return [
            'article_id' => Article::factory(),
            'nombre_extraido' => fake()->name(),
            'rol' => fake()->randomElement(['imputado', 'condenado', 'victima', 'testigo', 'otro']),
            'delitos' => [],
            'confianza' => fake()->randomFloat(3, 0, 1),
        ];
    }
}
