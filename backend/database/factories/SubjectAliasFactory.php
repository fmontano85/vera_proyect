<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Subject;
use App\Models\SubjectAlias;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubjectAlias>
 */
class SubjectAliasFactory extends Factory
{
    protected $model = SubjectAlias::class;

    public function definition(): array
    {
        return [
            'subject_id' => Subject::factory(),
            'nombre' => fake()->name(),
        ];
    }
}
