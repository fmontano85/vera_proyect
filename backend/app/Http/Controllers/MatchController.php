<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Matches\ProponerResolucion;
use App\Actions\Matches\ResolverMatch;
use App\Models\MentionMatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Resolucion de coincidencias en dos pasos (seccion 1, principio no
 * negociable: "el sistema propone, el analista resuelve" - aqui
 * "resuelve" es literal, viene del oficial_cumplimiento, no del
 * analista). Ver App\Policies\MentionMatchPolicy para quien puede cada
 * accion.
 */
class MatchController extends Controller
{
    private const ESTADOS_VALIDOS = 'confirmado,falso_positivo,homonimo';

    public function proponer(Request $request, MentionMatch $match, ProponerResolucion $action): JsonResponse
    {
        $this->authorize('proponer', $match);

        $validated = $request->validate([
            'estado' => ['required', 'in:'.self::ESTADOS_VALIDOS],
        ]);

        try {
            $match = $action->handle($match, $request->user(), $validated['estado']);
        } catch (RuntimeException $e) {
            return response()->json(['mensaje' => $e->getMessage()], 422);
        }

        return response()->json($match);
    }

    public function resolver(Request $request, MentionMatch $match, ResolverMatch $action): JsonResponse
    {
        $this->authorize('resolver', $match);

        $validated = $request->validate([
            'estado' => ['required', 'in:'.self::ESTADOS_VALIDOS],
        ]);

        try {
            $match = $action->handle($match, $request->user(), $validated['estado']);
        } catch (RuntimeException $e) {
            return response()->json(['mensaje' => $e->getMessage()], 422);
        }

        return response()->json($match);
    }
}
