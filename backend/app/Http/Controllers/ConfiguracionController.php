<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Seguimiento\ActualizarFrecuenciasSeguimiento;
use App\Models\FrecuenciaSeguimiento;
use App\Services\Seguimiento\CalculadoraSeguimiento;
use Illuminate\Http\JsonResponse;
use App\Support\ConfiguracionTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Configuracion del tenant (seccion 3.2: admin). Por ahora solo los dias
 * de seguimiento por nivel de riesgo (seccion 3.8).
 */
class ConfiguracionController extends Controller
{
    /**
     * Seccion 3.9, punto 2: cuantos anios se conservan los datos de una
     * persona inactiva antes de la depuracion. Todos los usuarios del
     * tenant la ven; solo el admin la cambia (decision del usuario
     * 2026-09-28). La depuracion en si la habilita el superadmin.
     */
    public function retencion(): JsonResponse
    {
        return response()->json($this->serializarRetencion());
    }

    public function actualizarRetencion(Request $request): JsonResponse
    {
        Gate::authorize('configurar-retencion');

        $validated = $request->validate([
            'retencion_anios' => ['required', 'integer', 'min:'.ConfiguracionTenant::RETENCION_MINIMA_ANIOS, 'max:'.ConfiguracionTenant::RETENCION_MAXIMA_ANIOS],
        ]);

        $tenant = tenant();
        $antes = ConfiguracionTenant::retencionAnios($tenant);
        $tenant->update(['retencion_anios' => (int) $validated['retencion_anios']]);

        if ($antes !== (int) $validated['retencion_anios']) {
            activity()->performedOn($tenant)->causedBy($request->user())->event('retencion_cambiada')
                ->withProperties(['de' => $antes, 'a' => (int) $validated['retencion_anios']])
                ->log('Plazo de retención cambiado');
        }

        return response()->json($this->serializarRetencion());
    }

    /** @return array{retencion_anios: int, retencion_minima_anios: int, depuracion_habilitada: bool} */
    private function serializarRetencion(): array
    {
        $tenant = tenant();

        return [
            'retencion_anios' => ConfiguracionTenant::retencionAnios($tenant),
            'retencion_minima_anios' => ConfiguracionTenant::RETENCION_MINIMA_ANIOS,
            'depuracion_habilitada' => ConfiguracionTenant::depuracionHabilitada($tenant),
        ];
    }

    public function frecuencias(CalculadoraSeguimiento $calculadora): JsonResponse
    {
        $this->authorize('viewAny', FrecuenciaSeguimiento::class);

        return response()->json($calculadora->frecuenciasDelTenant(tenant('id')));
    }

    /**
     * Los 4 niveles son obligatorios, 1..365 dias (sin piso regulatorio
     * UIF todavia - decision del usuario 2026-09-25).
     */
    public function actualizarFrecuencias(Request $request, ActualizarFrecuenciasSeguimiento $action): JsonResponse
    {
        $this->authorize('update', FrecuenciaSeguimiento::class);

        $reglas = collect(FrecuenciaSeguimiento::NIVELES)
            ->mapWithKeys(fn (string $nivel) => [$nivel => ['required', 'integer', 'min:1', 'max:365']])
            ->all();

        $validated = $request->validate($reglas);

        return response()->json($action->handle(array_map('intval', $validated)));
    }
}
