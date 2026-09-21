<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subject>
 */
class SubjectFactory extends Factory
{
    protected $model = Subject::class;

    public function definition(): array
    {
        return [
            'tipo' => fake()->randomElement(['natural', 'juridica']),
            'nombre_canonico' => fake()->name(),
            'documento' => null,
            'nivel_riesgo' => null,
            'activo' => true,
        ];
    }
}
