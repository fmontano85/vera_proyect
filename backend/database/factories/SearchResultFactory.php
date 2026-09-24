<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SearchResult;
use App\Models\SearchRun;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SearchResult>
 */
class SearchResultFactory extends Factory
{
    protected $model = SearchResult::class;

    public function definition(): array
    {
        $url = fake()->unique()->url();

        return [
            'search_run_id' => SearchRun::factory(),
            'subject_id' => Subject::factory(),
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'titulo' => fake()->sentence(),
            'snippet' => fake()->paragraph(),
            'medio' => fake()->domainName(),
            'fecha_brave' => fake()->dateTimeBetween('-30 days', 'now'),
        ];
    }
}
