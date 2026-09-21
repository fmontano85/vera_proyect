<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SearchRun;
use App\Models\Source;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SearchRun>
 */
class SearchRunFactory extends Factory
{
    protected $model = SearchRun::class;

    public function definition(): array
    {
        return [
            'subject_id' => Subject::factory(),
            'source_id' => Source::factory(),
            'query' => fake()->sentence(),
            'resultados' => [],
            'costo' => null,
        ];
    }
}
