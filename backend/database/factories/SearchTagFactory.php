<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SearchTag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SearchTag>
 */
class SearchTagFactory extends Factory
{
    protected $model = SearchTag::class;

    public function definition(): array
    {
        return [
            'nombre' => fake()->unique()->word(),
            'activo' => true,
        ];
    }
}
