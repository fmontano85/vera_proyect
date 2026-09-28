<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ConfiguracionSanciones;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConfiguracionSanciones>
 */
class ConfiguracionSancionesFactory extends Factory
{
    protected $model = ConfiguracionSanciones::class;

    public function definition(): array
    {
        return [
            'modo_descarga_ofac' => 'automatico',
        ];
    }
}
