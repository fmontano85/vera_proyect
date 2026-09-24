<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Source>
 */
class SourceFactory extends Factory
{
    protected $model = Source::class;

    public function definition(): array
    {
        return [
            'nombre' => fake()->company(),
            'tipo' => fake()->randomElement(['cse', 'rss', 'oficial', 'sanciones', 'brave']),
            'config' => [],
            'activo' => true,
        ];
    }
}
