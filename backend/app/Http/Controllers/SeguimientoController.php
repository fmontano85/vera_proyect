<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Seguimiento\MarcarSeguimientoRealizado;
use App\Models\Subject;
use App\Services\Seguimiento\CalculadoraSeguimiento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Seccion 3.8 del CLAUDE.md raiz (Fase 2): panel de seguimientos y cierre
 * manual. Delgado a proposito, logica en App\Actions\Seguimiento y
 * App\Services\Seguimiento.
 */
class SeguimientoController extends Controller
{
    /** Ventana del filtro 'proximos'. */
    private const DIAS_PROXIMOS = 30;

    /**
     * vencidos: proximo_seguimiento_en <= hoy. proximos: vencen en los
     * proximos 30 dias. Solo subjects activos. Orden: fecha de
     * vencimiento y, a igual fecha, nivel de riesgo (alto primero).
     */
    public function index(Request $request, CalculadoraSeguimiento $calculadora): JsonResponse
    {
        $this->authorize('viewAny', Subject::class);

        $filtro = $request->validate([
            'filtro' => ['nullable', 'in:vencidos,proximos'],
        ])['filtro'] ?? 'vencidos';

        $hoy = $calculadora->hoy();

        $consulta = Subject::query()->with('ultimoSeguimientoUsuario');

        if ($filtro === 'vencidos') {
            $consulta->vencidosAl($hoy->toDateString());
        } else {
            $consulta->proximosAl($hoy, self::DIAS_PROXIMOS);
        }

        $frecuencias = $calculadora->frecuenciasDelTenant(tenant('id'));

        $subjects = $consulta
            ->orderBy('proximo_seguimiento_en')
            ->orderByRaw("case nivel_riesgo when 'alto' then 0 when 'medio' then 1 when 'bajo' then 2 else 3 end")
            ->orderBy('id')
            ->paginate()
            ->through(fn (Subject $subject) => $calculadora->serializar($subject, $frecuencias));

        return response()->json($subjects);
    }

    public function realizado(Request $request, Subject $subject, MarcarSeguimientoRealizado $action, CalculadoraSeguimiento $calculadora): JsonResponse
    {
        $this->authorize('marcarSeguimiento', $subject);

        $validated = $request->validate([
            'observacion' => ['nullable', 'string', 'max:2000'],
        ]);

        $subject = $action->handle($subject, $request->user(), $validated['observacion'] ?? null);

        return response()->json($calculadora->serializar($subject->load('ultimoSeguimientoUsuario')));
    }
}
