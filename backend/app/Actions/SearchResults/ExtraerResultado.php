<?php

declare(strict_types=1);

namespace App\Actions\SearchResults;

use App\Enums\EstadoSearchResult;
use App\Jobs\FetchArticleJob;
use App\Models\SearchResult;
use RuntimeException;

/**
 * "Sacar informacion de noticia" (seccion 3.7 del CLAUDE.md raiz): unico
 * punto donde el analista decide gastar Fetch+IA en un resultado. Valido
 * en 'nuevo' (primera vez) o 'gap' (reintentar un fetch que fallo antes).
 */
class ExtraerResultado
{
    public function handle(SearchResult $searchResult): SearchResult
    {
        if (! in_array($searchResult->estado, [EstadoSearchResult::Nuevo, EstadoSearchResult::Gap], true)) {
            throw new RuntimeException(
                "No se puede extraer un resultado en estado '{$searchResult->estado->value}' - solo 'nuevo' o 'gap'."
            );
        }

        $searchResult->forceFill([
            'estado' => EstadoSearchResult::Procesando,
            'gap_motivo' => null,
            'http_status' => null,
        ])->save();

        FetchArticleJob::dispatch($searchResult->id);

        return $searchResult;
    }
}
