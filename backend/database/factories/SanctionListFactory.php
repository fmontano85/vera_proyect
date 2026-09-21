<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SanctionList;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SanctionList>
 */
class SanctionListFactory extends Factory
{
    protected $model = SanctionList::class;

    public function definition(): array
    {
        return [
            'codigo' => fake()->randomElement(['ofac_sdn', 'un_consolidated', 'eu']),
            'version' => (string) fake()->numberBetween(1, 100),
            'fecha_importacion' => now(),
        ];
    }
}
