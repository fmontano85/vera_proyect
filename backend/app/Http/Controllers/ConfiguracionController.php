<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Seguimiento\ActualizarFrecuenciasSeguimiento;
use App\Models\FrecuenciaSeguimiento;
use App\Services\Seguimiento\CalculadoraSeguimiento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Configuracion del tenant (seccion 3.2: admin). Por ahora solo los dias
 * de seguimiento por nivel de riesgo (seccion 3.8).
 */
class ConfiguracionController extends Controller
{
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
