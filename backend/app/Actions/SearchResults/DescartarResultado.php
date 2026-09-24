<?php

declare(strict_types=1);

namespace App\Actions\SearchResults;

use App\Enums\EstadoSearchResult;
use App\Models\SearchResult;
use App\Models\User;
use RuntimeException;

/**
 * Marca un resultado como irrelevante (seccion 3.7). Valido desde
 * cualquier estado que no sea ya 'descartado' (un 'gap' o 'sin_menciones'
 * tambien se pueden descartar - el analista decide que ya no le interesa
 * seguir con eso).
 */
class DescartarResultado
{
    public function handle(SearchResult $searchResult, User $user): SearchResult
    {
        if ($searchResult->estado === EstadoSearchResult::Descartado) {
            throw new RuntimeException('Este resultado ya esta descartado.');
        }

        $searchResult->forceFill([
            'estado' => EstadoSearchResult::Descartado,
            'descartado_por' => $user->id,
            'descartado_en' => now(),
        ])->save();

        return $searchResult;
    }
}
