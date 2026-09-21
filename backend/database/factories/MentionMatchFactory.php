<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Mention;
use App\Models\MentionMatch;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MentionMatch>
 */
class MentionMatchFactory extends Factory
{
    protected $model = MentionMatch::class;

    public function definition(): array
    {
        return [
            'mention_id' => Mention::factory(),
            'subject_id' => Subject::factory(),
            'score_meilisearch' => fake()->randomFloat(2, 0, 100),
            'estado' => 'pendiente',
        ];
    }
}
