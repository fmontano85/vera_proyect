<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SanctionEntry;
use App\Models\SanctionList;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SanctionEntry>
 */
class SanctionEntryFactory extends Factory
{
    protected $model = SanctionEntry::class;

    public function definition(): array
    {
        return [
            'sanction_list_id' => SanctionList::factory(),
            'external_id' => fake()->unique()->numerify('#####'),
            'nombre' => fake()->name(),
            'aliases' => [],
            'tipo' => fake()->randomElement(['individual', 'entidad']),
            'programa' => fake()->word(),
            'pais' => fake()->countryCode(),
            'raw_json' => [],
        ];
    }
}
