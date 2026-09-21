<?php

declare(strict_types=1);

namespace App\Data\Extraction;

use App\Enums\RolMencion;
use Spatie\LaravelData\Data;

class PersonaExtraidaData extends Data
{
    public function __construct(
        public string $nombre,
        public RolMencion $rol,
        /** @var array<int, string> */
        public array $delitos,
        public ?string $institucion_relacionada,
        public float $confianza,
    ) {
    }
}
