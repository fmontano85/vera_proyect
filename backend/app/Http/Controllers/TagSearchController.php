<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\TagSearches\IniciarBusquedaPorTags;
use App\Models\SearchResult;
use App\Models\SearchTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Busqueda por tags (sesion posterior a la 3.7 del CLAUDE.md raiz):
 * dispara una busqueda sin subject y lista sus resultados. Delgado a
 * proposito, logica en App\Actions\TagSearches.
 */
class TagSearchController extends Controller
{
    public function buscar(Request $request, IniciarBusquedaPorTags $action): JsonResponse
    {
        $this->authorize('buscar', SearchTag::class);

        $validated = $request->validate([
            'tag_ids' => ['required', 'array', 'min:1'],
            'tag_ids.*' => ['integer'],
            'dias_atras' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        try {
            $fuentes = $action->handle($validated['tag_ids'], $validated['dias_atras'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['mensaje' => $e->getMessage()], 422);
        }

        return response()->json([
            'mensaje' => 'Busqueda por tags encolada.',
            'fuentes' => $fuentes,
        ], 202);
    }

    /**
     * Resultados de todas las busquedas por tags del tenant (subject_id
     * null), mas recientes primero - mismo shape que
     * SearchResultController::index para reutilizar la misma tarjeta en
     * el frontend.
     */
    public function resultados(): JsonResponse
    {
        $this->authorize('viewAny', SearchTag::class);

        $resultados = SearchResult::query()
            ->whereNull('subject_id')
            ->with(['mentions' => fn ($q) => $q->with('match'), 'article'])
            ->latest()
            ->paginate();

        return response()->json($resultados);
    }
}
