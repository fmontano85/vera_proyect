<?php

declare(strict_types=1);

namespace App\Actions\TagSearches;

use App\Jobs\RunTagSearchJob;
use App\Models\SearchTag;
use App\Models\Source;
use RuntimeException;

/**
 * Busqueda por tags (sesion posterior a la 3.7): dispara RunTagSearchJob
 * contra todas las Source activas de tipo 'brave', igual que
 * IniciarConsultaPuntual, pero validando los tags recibidos contra el
 * catalogo del tenant actual antes de gastar cuota - evita que se cuele
 * texto libre arbitrario (la decision original de "lista configurada, no
 * texto libre" se mantiene, solo que ahora la lista la administra el
 * tenant en vez de un config fijo).
 */
class IniciarBusquedaPorTags
{
    /**
     * @param  list<int>  $tagIds
     * @return list<int> ids de las sources contra las que se encolo la busqueda
     */
    public function handle(array $tagIds, ?int $diasAtras): array
    {
        $tags = SearchTag::query()
            ->whereIn('id', $tagIds)
            ->where('activo', true)
            ->pluck('nombre');

        if ($tags->count() !== count(array_unique($tagIds))) {
            throw new RuntimeException(
                'Uno o mas tags no existen en el catalogo de este tenant o estan inactivos.'
            );
        }

        $sources = Source::query()
            ->where('activo', true)
            ->where('tipo', 'brave')
            ->pluck('id');

        if ($sources->isEmpty()) {
            throw new RuntimeException(
                'No hay fuentes activas de tipo brave configuradas. Crea una Source con tipo=brave antes de ejecutar una busqueda por tags.'
            );
        }

        foreach ($sources as $sourceId) {
            RunTagSearchJob::dispatch($tags->all(), $sourceId, $diasAtras);
        }

        return $sources->all();
    }
}
