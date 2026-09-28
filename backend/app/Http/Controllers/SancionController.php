<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Sanctions\ListarSanciones;
use App\Actions\Sanctions\ResolverSancion;
use App\Exceptions\IndiceBusquedaNoDisponible;
use App\Models\SanctionMatch;
use App\Models\Subject;
use App\Services\Sanctions\CruceSanciones;
use App\Services\Sanctions\SerializadorSancion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Meilisearch\Exceptions\ExceptionInterface as MeilisearchException;
use RuntimeException;

/**
 * Hallazgos contra listas de sanciones (OFAC SDN hoy). Delgado: la logica
 * vive en Services/Actions.
 *
 * Sanciones esta deshabilitada por defecto (decision del usuario
 * 2026-09-28): solo el superadmin la activa por tenant (panel de
 * superadmin, App\Http\Controllers\SuperadminController). 404 y no 403
 * para no confirmar que la funcion existe a un tenant sin ella.
 */
class SancionController extends Controller
{
    public function index(Request $request, ListarSanciones $action): JsonResponse
    {
        $this->authorize('viewAny', SanctionMatch::class);
        $this->verificarHabilitado();

        $filtros = $request->validate([
            'estado' => ['nullable', Rule::in(['pendiente', 'todos'])],
            'subject_id' => ['nullable', 'integer'],
        ]);

        return response()->json($action->handle($filtros));
    }

    public function cruzar(Subject $subject, CruceSanciones $cruce): JsonResponse
    {
        $this->authorize('view', $subject);
        Gate::authorize('cruzar', SanctionMatch::class);
        $this->verificarHabilitado();

        // Solo un fallo real de Meilisearch se etiqueta como "indice no
        // disponible" (503) - cualquier otra excepcion (un bug de codigo)
        // debe verse como lo que es, no disfrazarse de caida de infra.
        try {
            $nuevos = $cruce->cruzar($subject);
        } catch (MeilisearchException $e) {
            report($e);
            throw new IndiceBusquedaNoDisponible('No se pudo consultar el índice de sanciones: '.$e->getMessage(), previous: $e);
        }

        return response()->json(['hallazgos_nuevos' => $nuevos]);
    }

    public function resolver(
        Request $request,
        SanctionMatch $sancion,
        ResolverSancion $action,
        SerializadorSancion $serializador,
    ): JsonResponse {
        $this->authorize('resolver', $sancion);
        $this->verificarHabilitado();

        $validated = $request->validate([
            'estado' => ['required', Rule::in(['confirmado', 'falso_positivo', 'homonimo'])],
        ]);

        try {
            $sancion = $action->handle($sancion, $request->user(), $validated['estado']);
        } catch (RuntimeException $e) {
            return response()->json(['mensaje' => $e->getMessage()], 422);
        }

        return response()->json($serializador->serializar($sancion->load(SerializadorSancion::RELACIONES)));
    }

    private function verificarHabilitado(): void
    {
        abort_unless(tenant('sanciones_habilitado'), 404);
    }
}
