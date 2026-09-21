<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Article;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Article>
 */
class ArticleFactory extends Factory
{
    protected $model = Article::class;

    public function definition(): array
    {
        return [
            'url' => fake()->unique()->url(),
            'titulo' => fake()->sentence(),
            'medio' => fake()->domainName(),
            'fecha_publicacion' => now(),
            'hash_contenido' => hash('sha256', fake()->text()),
            'evidence_path' => null,
            'estado_extraccion' => 'pendiente',
        ];
    }
}
