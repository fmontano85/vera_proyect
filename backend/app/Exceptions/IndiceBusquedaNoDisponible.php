<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Meilisearch no respondio al reindexar un subject. Se lanza DENTRO de la
 * transaccion para que el cambio (alias, nombre, alta, activo) se revierta:
 * un subject guardado con el indice desactualizado haria que el matching
 * dejara de encontrarlo en silencio. Laravel la convierte en 503.
 */
class IndiceBusquedaNoDisponible extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json([
            'mensaje' => 'El índice de búsqueda no está disponible en este momento. No se guardó el cambio; intenta de nuevo en unos minutos.',
        ], 503);
    }
}
