<?php

declare(strict_types=1);

namespace App\Data\Extraction;

use Spatie\LaravelData\Data;

/**
 * Contrato de salida de la extraccion (seccion 3.5 del CLAUDE.md raiz).
 * Valida la respuesta de Claude contra el esquema esperado - si Claude
 * devuelve algo que no calza, spatie/laravel-data lanza al construir el
 * DTO en vez de dejar pasar datos con forma incorrecta.
 */
class ExtractionResultData extends Data
{
    public function __construct(
        /** @var array<int, PersonaExtraidaData> */
        public array $personas,
        public ?string $fecha_hecho,
        public string $resumen,
        public float $confianza_global,
    ) {
    }
}
