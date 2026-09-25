<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SearchResults\CapturaManual;
use App\Actions\SearchResults\DescartarResultado;
use App\Actions\SearchResults\ExtraerResultado;
use App\Http\Requests\CapturaManualRequest;
use App\Models\SearchResult;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Flujo bajo demanda (seccion 3.7 del CLAUDE.md raiz). Delgado a
 * proposito - la logica vive en app/Actions/SearchResults.
 */
class SearchResultController extends Controller
{
    public function index(Subject $subject): JsonResponse
    {
        $this->authorize('view', $subject);

        /**
         * Seccion 3.8: resultados que aparecieron despues del ultimo
         * seguimiento realizado. firstOrCreate por (subject_id, url_hash)
         * en RunSubjectSearchJob garantiza que created_at es la primera
         * vez que esa URL salio para el subject - sin columna extra. Si
         * nunca hubo seguimiento, nada se marca.
         */
        $ultimoSeguimiento = $subject->ultimo_seguimiento_en;

        $resultados = SearchResult::query()
            ->where('subject_id', $subject->id)
            ->with(['mentions' => fn ($q) => $q->with('match'), 'article'])
            ->latest()
            ->paginate()
            ->through(fn (SearchResult $resultado) => $resultado->toArray() + [
                'nuevo_desde_ultimo_seguimiento' => $ultimoSeguimiento !== null && $resultado->created_at->gt($ultimoSeguimiento),
            ]);

        return response()->json($resultados);
    }

    public function extraer(SearchResult $resultado, ExtraerResultado $action): JsonResponse
    {
        $this->authorize('extraer', $resultado);

        try {
            $resultado = $action->handle($resultado);
        } catch (RuntimeException $e) {
            return response()->json(['mensaje' => $e->getMessage()], 422);
        }

        return response()->json($resultado);
    }

    public function descartar(SearchResult $resultado, DescartarResultado $action): JsonResponse
    {
        $this->authorize('descartar', $resultado);

        try {
            $resultado = $action->handle($resultado, request()->user());
        } catch (RuntimeException $e) {
            return response()->json(['mensaje' => $e->getMessage()], 422);
        }

        return response()->json($resultado);
    }

    public function capturaManual(
        CapturaManualRequest $request,
        SearchResult $resultado,
        CapturaManual $action,
    ): JsonResponse {
        $this->authorize('capturaManual', $resultado);

        try {
            $match = $action->handle(
                $resultado,
                $request->user(),
                $request->safe()->except('pdf'),
                $request->file('pdf'),
            );
        } catch (RuntimeException $e) {
            return response()->json(['mensaje' => $e->getMessage()], 422);
        }

        return response()->json($match, 201);
    }
}
