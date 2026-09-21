<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SanctionEntry;
use App\Models\SanctionMatch;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SanctionMatch>
 */
class SanctionMatchFactory extends Factory
{
    protected $model = SanctionMatch::class;

    public function definition(): array
    {
        return [
            'subject_id' => Subject::factory(),
            'sanction_entry_id' => SanctionEntry::factory(),
            'score' => fake()->randomFloat(2, 0, 100),
            'estado' => 'pendiente',
        ];
    }
}
