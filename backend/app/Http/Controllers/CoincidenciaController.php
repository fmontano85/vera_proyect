<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MentionMatch;
use App\Services\Matching\SerializadorCoincidencia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dashboard de coincidencias pendientes (Fase 2, seccion 5). Dos bandejas
 * segun el control de dos pasos de la seccion 3.2:
 * - sin_propuesta: el analista debe proponer una resolucion.
 * - esperan_resolucion: ya hay propuesta; el oficial debe resolver.
 * Las mas antiguas primero (no dejar envejecer un pendiente).
 */
class CoincidenciaController extends Controller
{
    public function index(Request $request, SerializadorCoincidencia $serializador): JsonResponse
    {
        $this->authorize('viewAny', MentionMatch::class);

        $bandeja = $request->validate([
            'bandeja' => ['nullable', 'in:sin_propuesta,esperan_resolucion'],
        ])['bandeja'] ?? 'sin_propuesta';

        $coincidencias = MentionMatch::query()
            ->enBandeja($bandeja)
            ->with(SerializadorCoincidencia::RELACIONES)
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate();

        return response()->json($coincidencias->through(fn (MentionMatch $match) => $serializador->serializar($match)));
    }
}
