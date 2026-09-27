<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Sanctions\ResolverSancion;
use App\Exceptions\IndiceBusquedaNoDisponible;
use App\Models\SanctionMatch;
use App\Models\Subject;
use App\Services\Sanctions\CruceSanciones;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

/** Hallazgos contra listas de sanciones (OFAC SDN hoy). Delgado: la logica vive en Services/Actions. */
class SancionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SanctionMatch::class);

        $filtros = $request->validate([
            'estado' => ['nullable', Rule::in(['pendiente', 'todos'])],
            'subject_id' => ['nullable', 'integer'],
        ]);

        $hallazgos = SanctionMatch::query()
            ->with(['subject:id,nombre_canonico', 'sanctionEntry.sanctionList:id,codigo', 'resueltoPor:id,name'])
            ->when(($filtros['estado'] ?? 'pendiente') === 'pendiente', fn ($q) => $q->where('estado', 'pendiente'))
            ->when(isset($filtros['subject_id']), fn ($q) => $q->where('subject_id', $filtros['subject_id']))
            ->orderByDesc('score')
            ->orderByDesc('id')
            ->paginate()
            ->through(fn (SanctionMatch $m) => $this->serializar($m));

        return response()->json($hallazgos);
    }

    public function cruzar(Subject $subject, CruceSanciones $cruce): JsonResponse
    {
        $this->authorize('view', $subject);
        Gate::authorize('cruzar', SanctionMatch::class);

        try {
            $nuevos = $cruce->cruzar($subject);
        } catch (Throwable $e) {
            report($e);
            throw new IndiceBusquedaNoDisponible('No se pudo consultar el índice de sanciones: '.$e->getMessage(), previous: $e);
        }

        return response()->json(['hallazgos_nuevos' => $nuevos]);
    }

    public function resolver(Request $request, SanctionMatch $sancion, ResolverSancion $action): JsonResponse
    {
        $this->authorize('resolver', $sancion);

        $validated = $request->validate([
            'estado' => ['required', Rule::in(['confirmado', 'falso_positivo', 'homonimo'])],
        ]);

        try {
            $sancion = $action->handle($sancion, $request->user(), $validated['estado']);
        } catch (RuntimeException $e) {
            return response()->json(['mensaje' => $e->getMessage()], 422);
        }

        return response()->json($this->serializar($sancion->load(['subject:id,nombre_canonico', 'sanctionEntry.sanctionList:id,codigo', 'resueltoPor:id,name'])));
    }

    /** JSON explicito: sin exponer raw_json ni ids internos de mas. */
    private function serializar(SanctionMatch $m): array
    {
        return [
            'id' => $m->id,
            'subject' => $m->subject ? ['id' => $m->subject->id, 'nombre_canonico' => $m->subject->nombre_canonico] : null,
            'entrada' => [
                'nombre' => $m->sanctionEntry->nombre,
                'aliases' => $m->sanctionEntry->aliases ?? [],
                'tipo' => $m->sanctionEntry->tipo,
                'programa' => $m->sanctionEntry->programa,
                'pais' => $m->sanctionEntry->pais,
                'lista' => $m->sanctionEntry->sanctionList?->codigo,
            ],
            'score' => (float) $m->score,
            'estado' => $m->estado,
            'resuelto_por' => $m->resueltoPor?->name,
            'resuelto_en' => $m->resuelto_en,
            'creado_en' => $m->created_at,
        ];
    }
}
